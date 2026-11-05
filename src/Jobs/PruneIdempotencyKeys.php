<?php

declare(strict_types=1);

namespace Waypost\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Waypost\Http\Middleware\HandleIdempotencyKey;
use Waypost\Models\IdempotencyKey;
use Waypost\Support\Time;

/** Deletes the expired and the abandoned Idempotency-Keys (HandleIdempotencyKey). Scheduled hourly by the service provider. */
final class PruneIdempotencyKeys implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onConnection(config('waypost.queue.connection'));
        $this->onQueue(config('waypost.queue.queue'));
    }

    public function handle(): void
    {
        IdempotencyKey::query()
            ->where('expires_at', '<=', Time::now()->format(Time::DATABASE_FORMAT))
            ->orWhere(fn (Builder $busy) => $busy
                ->whereNull('response_status')
                ->where('created_at', '<=', HandleIdempotencyKey::abandonedBefore()->format(Time::DATABASE_FORMAT)))
            ->delete();
    }
}
