<?php

declare(strict_types=1);

namespace Waypost\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Waypost\Models\IdempotencyKey;
use Waypost\Support\Time;

/** Deletes the expired Idempotency-Keys (HandleIdempotencyKey). Scheduled hourly by the service provider. */
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
        IdempotencyKey::query()->where('expires_at', '<=', Time::now())->delete();
    }
}
