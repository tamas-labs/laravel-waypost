<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers;

use Illuminate\Http\Request;
use LogicException;
use Waypost\Auth\Caller;
use Waypost\Http\Entities;
use Waypost\Http\Middleware\Authenticate;
use Waypost\Models\Device;
use Waypost\Models\Site;
use Waypost\Support\Time;

/** POST /devices/enroll (spec: Azonosítás / Telephelyi telefon hozzárendelése). */
final class DeviceController extends Controller
{
    /**
     * Enrolls the calling phone as the site phone: a new device with a device token and a 32-byte HMAC secret, both
     * only in this response (and its idempotent replay). The site's previous phone is revoked: its requests get
     * device_revoked, but its token stays, so it can still upload its last entries (push-final, M4).
     *
     * @return array{device: array<string, mixed>, token: string, secret: string}
     */
    public function enroll(Request $request, Caller $caller): array
    {
        $siteId = $request->string('site_id')->toString();
        $this->gate->authorize('create', [Device::class, $siteId]);
        $supervisor = $caller->user ?? throw new LogicException('Only a supervisor enrolls a device.');

        $secret = base64_encode(random_bytes(32));
        $device = new Device([...self::input($request), 'secret' => $secret, 'enrolled_by' => $supervisor->id]);
        $token = self::write(function () use ($device, $siteId): string {
            // Two enrolments of the same site at once: the second waits, then revokes the first.
            Site::query()->whereKey($siteId)->lockForUpdate()->first();
            $now = Time::now();
            Device::query()->where('site_id', $siteId)->whereNull('revoked_at')->get()
                ->each(fn (Device $previous) => $previous->update(['revoked_at' => $now]));
            $device->fill(['enrolled_at' => $now])->save();

            return $device->createToken($device->name, [Authenticate::DEVICE])->plainTextToken;
        });

        return ['device' => Entities::device($device), 'token' => $token, 'secret' => $secret];
    }
}
