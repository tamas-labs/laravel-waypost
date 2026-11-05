<?php

declare(strict_types=1);

namespace Waypost\Chain;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Waypost\Enums\ChainFlag;
use Waypost\Enums\ErrorCode;
use Waypost\Enums\VerifyStatus;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Jobs\SendShiftReport;
use Waypost\Models\ChainEntry;
use Waypost\Models\Device;
use Waypost\Models\Media;
use Waypost\Models\Shift;
use Waypost\Support\Time;

/**
 * Receives a batch of a device's chain entries (spec: Láncolt napló / Szerveroldali ellenőrzés), in one transaction with
 * the device locked. An entry is checked in order: seq (gap), prev_hash (fork), HMAC (bad_hmac).
 *
 * A rejected entry is stored too, as evidence, in its seq's only place. A gap entry waits there and is checked again when
 * its turn comes; a fork or bad_hmac entry stays until an entry with its seq arrives, which replaces it: otherwise whoever
 * holds the token but not the secret could block the chain for good. Only ok entries reach the projections.
 *
 * A gap entry is stored only with the device's HMAC; one without it gets chain_gap all the same, but touches nothing.
 * Otherwise whoever holds the token but not the secret could fill the table with entries at any seq, and push out
 * the ones that wait.
 */
final readonly class ChainProcessor
{
    /**
     * Unapplied entries of earlier pushes tried again in one push, the oldest first; the entries accepted now are
     * always tried. A phone that worked offline on a route the server does not know yet may have thousands, and every
     * batch of its sync would try all of them again, in the locked transaction.
     */
    public const int PENDING_LIMIT = 500;

    /** Photos listed in media_missing, the oldest first: the app uploads them, the next push lists the rest. */
    public const int MEDIA_MISSING_LIMIT = 500;

    public function __construct(private Projector $projector)
    {
    }

    /**
     * @param  list<array<string, mixed>>  $entries  chain/entry.json, in ascending seq order, of this device
     * @param  bool  $closeUpload  the last request of a revoked device (POST /sync/push-final with done: true)
     * @return array<string, mixed> the body of the response
     */
    public function process(Device $device, array $entries, bool $closeUpload = false): array
    {
        return DB::transaction(function () use ($device, $entries, $closeUpload): array {
            $device = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            // A push-final that waited for the lock while another one closed the upload.
            if ($device->revoked_at !== null && $device->upload_closed_at !== null) {
                throw ApiException::deviceRevoked();
            }

            $result = new PushResult();
            $lastSeq = $device->last_seq;
            $incoming = array_flip(array_map(fn (array $entry): int => (int) $entry['seq'], $entries));
            foreach ($entries as $data) {
                $this->receive($device, ChainEntry::fromPush($data), $incoming, $result);
            }
            $this->projectPending($device, $lastSeq);

            // Without updated_at: the chain state is no change for the pull.
            $device->timestamps = false;
            $device->fill(['last_seen_at' => Time::now()]);
            if ($closeUpload) {
                $device->fill(['upload_closed_at' => Time::now()]);
                // Replaced during a shift: it never gets its shift_closed, but nothing more comes for it.
                Shift::query()->where('device_id', $device->id)->whereNull('ended_at')->get()->each(SendShiftReport::after(...));
            }
            $device->save();

            return $result->response($device->last_seq, $this->missingMedia($device));
        });
    }

    /** @param  array<int, int>  $incoming  the seqs of the batch */
    private function receive(Device $device, ChainEntry $entry, array $incoming, PushResult $result): void
    {
        if ($entry->seq > $device->last_seq + 1 && !EntryHash::matches($entry->content(), $device->secret)) {
            $result->reject($entry->id, ErrorCode::ChainGap, $device->last_seq + 1);

            return;
        }
        if (!$this->claimId($device, $entry)) {
            $result->reject($entry->id, ErrorCode::ChainFork);

            return;
        }
        if ($entry->seq <= $device->last_seq) {
            $this->receiveAgain($device, $entry, $result);

            return;
        }

        $status = $this->verify($device, $entry);
        if ($status === VerifyStatus::Ok) {
            $this->replaceRejected($device, $entry);
            $this->accept($device, $entry, $result);
            $this->acceptWaiting($device, $incoming, $result);

            return;
        }

        $expectedSeq = $status === VerifyStatus::Gap ? $device->last_seq + 1 : null;
        $this->replaceRejected($device, $entry);
        $entry->fill(['verify_status' => $status, 'flags' => []])->save();
        $result->reject($entry->id, $status->errorCode(), $expectedSeq);
    }

    /**
     * Whether the entry's ID is free for it. A rejected entry of this device with the same ID but another seq gives way;
     * an accepted one, or one of another device, stays, and the new entry is not stored.
     */
    private function claimId(Device $device, ChainEntry $entry): bool
    {
        $holder = ChainEntry::query()->find($entry->id);
        if ($holder === null || ($holder->device_id === $device->id && $holder->seq === $entry->seq)) {
            return true;
        }
        if ($holder->device_id !== $device->id || $holder->verify_status === VerifyStatus::Ok) {
            return false;
        }
        $holder->delete();

        return true;
    }

    /**
     * An entry the chain has already passed. The same entry again is accepted again; another one with its seq is a fork
     * (the accepted entry stays, so this one is not stored). The retention job may have deleted the stored one: then the
     * HMAC tells whether the entry is the device's own.
     */
    private function receiveAgain(Device $device, ChainEntry $entry, PushResult $result): void
    {
        $stored = ChainEntry::query()->where('device_id', $device->id)->where('seq', $entry->seq)->first();
        if ($stored !== null) {
            $stored->hash === $entry->hash ? $result->accept($stored->id) : $result->reject($entry->id, ErrorCode::ChainFork);
        } elseif (EntryHash::matches($entry->content(), $device->secret)) {
            $result->accept($entry->id);
        } else {
            $result->reject($entry->id, ErrorCode::BadHmac);
        }
    }

    private function verify(Device $device, ChainEntry $entry): VerifyStatus
    {
        return match (true) {
            $entry->seq !== $device->last_seq + 1 => VerifyStatus::Gap,
            $entry->prev_hash !== ($device->last_hash ?? EntryHash::GENESIS) => VerifyStatus::Fork,
            !EntryHash::matches($entry->content(), $device->secret) => VerifyStatus::BadHmac,
            default => VerifyStatus::Ok,
        };
    }

    /** Removes the rejected entry stored in the seq's place; the same entry again keeps the time it first arrived. */
    private function replaceRejected(Device $device, ChainEntry $entry): void
    {
        $stored = ChainEntry::query()->where('device_id', $device->id)->where('seq', $entry->seq)->first();
        $entry->fill(['received_at' => $stored !== null && $stored->hash === $entry->hash ? $stored->received_at : Time::now()]);
        $stored?->delete();
    }

    private function accept(Device $device, ChainEntry $entry, PushResult $result): void
    {
        $entry->fill([
            'verify_status' => VerifyStatus::Ok,
            'flags' => ServerFlags::timeSuspect($entry) ? [ChainFlag::TimeSuspect->value] : [],
        ])->save();
        $device->last_seq = $entry->seq;
        $device->last_hash = $entry->hash;
        $result->accept($entry->id);
    }

    /**
     * The entries that waited for the ones just accepted, as long as they follow without a hole. One the batch still
     * brings is left for the batch: that one replaces what waits in its place.
     *
     * @param  array<int, int>  $incoming
     */
    private function acceptWaiting(Device $device, array $incoming, PushResult $result): void
    {
        while (!isset($incoming[$device->last_seq + 1])) {
            $next = ChainEntry::query()
                ->where('device_id', $device->id)
                ->where('seq', $device->last_seq + 1)
                ->where('verify_status', VerifyStatus::Gap)
                ->first();
            if ($next === null) {
                return;
            }

            $status = $this->verify($device, $next);
            if ($status !== VerifyStatus::Ok) {
                $next->update(['verify_status' => $status]);
                $result->reject($next->id, $status->errorCode());

                return;
            }
            $this->accept($device, $next, $result);
        }
    }

    /**
     * Everything accepted now, and at most PENDING_LIMIT entries of earlier pushes not applied yet: what they refer to
     * may be here by now. An honest phone's entry waits only for configuration that comes later, so the rest follows
     * with the next pushes; one that can never be applied goes with the retention.
     *
     * Each entry in its own savepoint: one that fails (a database error, a value the column does not take) is logged and
     * stays unapplied, and the push still commits what it received. Otherwise the entry would roll back every later push
     * of the device too, and its data would stay on the phone.
     *
     * @param  int  $lastSeq  the device's last_seq before this push
     */
    private function projectPending(Device $device, int $lastSeq): void
    {
        $pending = fn () => ChainEntry::query()
            ->where('device_id', $device->id)
            ->where('verify_status', VerifyStatus::Ok)
            ->whereNull('projected_at')
            ->orderBy('seq');

        $pending()->where('seq', '<=', $lastSeq)->limit(self::PENDING_LIMIT)->get()
            ->concat($pending()->where('seq', '>', $lastSeq)->get())
            ->each(function (ChainEntry $entry) use ($device): void {
                try {
                    DB::transaction(function () use ($device, $entry): void {
                        if ($this->projector->apply($device, $entry)) {
                            $entry->update(['projected_at' => Time::now()]);
                        }
                    });
                } catch (Throwable $exception) {
                    Log::error('Waypost: a chain entry could not be applied; it stays unapplied.', [
                        'device_id' => $device->id, 'entry_id' => $entry->id, 'seq' => $entry->seq, 'exception' => $exception,
                    ]);
                }
            });
    }

    /** @return list<string> */
    private function missingMedia(Device $device): array
    {
        return array_values(array_map(
            strval(...),
            Media::query()->where('device_id', $device->id)->whereNull('uploaded_at')->orderBy('id')->limit(self::MEDIA_MISSING_LIMIT)->pluck('id')->all(),
        ));
    }
}
