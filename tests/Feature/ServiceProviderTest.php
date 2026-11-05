<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\ServiceProvider;
use Waypost\Enums\ReportFrequency;
use Waypost\Jobs\PruneExpiredData;
use Waypost\Jobs\PruneIdempotencyKeys;
use Waypost\Jobs\SendDueReports;
use Waypost\Jobs\SendNoDataAlerts;
use Waypost\Jobs\SendPeriodReport;
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

it('runs its scheduled jobs on one server, and none of them twice at a time', function (): void {
    $events = array_filter(app(Schedule::class)->events(), fn ($event): bool => $event instanceof CallbackEvent && str_starts_with((string) $event->description, 'waypost:'));

    expect($events)->toHaveCount(4)
        ->and(array_values(array_map(fn (CallbackEvent $event): bool => $event->onOneServer, $events)))->toBe([true, true, true, true]);
    $jobs = [new PruneIdempotencyKeys(), new SendDueReports(), new SendNoDataAlerts(), new PruneExpiredData(), new SendPeriodReport('x', ReportFrequency::Daily, '2026-11-03', '2026-11-03')];
    foreach ($jobs as $job) {
        expect($job)->toBeInstanceOf(ShouldBeUnique::class)->and($job->uniqueFor)->toBeGreaterThan(0);
    }
});

it('never replaces an auth guard of the application with its own', function (): void {
    config(['auth.guards.waypost' => ['driver' => 'session', 'provider' => 'users']]);

    expect(fn () => (new WaypostServiceProvider(app()))->register())->toThrow(LogicException::class, 'WAYPOST_GUARD');
    expect(config('auth.guards.waypost'))->toBe(['driver' => 'session', 'provider' => 'users']);
});

it('registers its guard again over its own, as after config:cache', function (): void {
    (new WaypostServiceProvider(app()))->register();

    expect(config('auth.guards.waypost'))->toBe(['driver' => 'waypost']);
});
