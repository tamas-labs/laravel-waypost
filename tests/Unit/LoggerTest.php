<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Waypost\Support\Logger;

it('logs on the configured channel, or on the default one', function (?string $channel, ?string $expected): void {
    config()->set('logging.channels.waypost', ['driver' => 'single', 'path' => storage_path('logs/waypost.log')]);
    config()->set('waypost.log_channel', $channel);

    expect(Logger::channel())->toBe(Log::channel($expected ?? (string) config('logging.default')));
})->with([
    'its own' => ['waypost', 'waypost'],
    'not set' => [null, null],
    'empty' => ['', null],
]);
