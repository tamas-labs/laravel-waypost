<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Waypost\WaypostServiceProvider;

it('is registered', function (): void {
    expect(app()->getProviders(WaypostServiceProvider::class))->toHaveCount(1);
});

it('merges the default configuration', function (): void {
    expect(config('waypost.route.prefix'))->toBe('api/waypost/v1')
        ->and(config('waypost.route.middleware'))->toBe(['api'])
        ->and(config('waypost.table_prefix'))->toBe('waypost_')
        ->and(config('waypost.storage.disk'))->toBe('local')
        ->and(config('waypost.queue.queue'))->toBe('default')
        ->and(config('waypost.mail.from.name'))->toBe('Waypost');
});

it('publishes the configuration under the waypost-config tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(WaypostServiceProvider::class, 'waypost-config');

    expect(array_values($paths))->toBe([config_path('waypost.php')]);
});
