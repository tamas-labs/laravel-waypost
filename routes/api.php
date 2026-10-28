<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Waypost\Http\Controllers\AuthController;
use Waypost\Http\Controllers\CheckpointController;
use Waypost\Http\Controllers\DeviceController;
use Waypost\Http\Controllers\ReportSubscriptionController;
use Waypost\Http\Controllers\RouteController;
use Waypost\Http\Controllers\RouteVersionController;
use Waypost\Http\Controllers\ScheduleController;
use Waypost\Http\Controllers\SiteController;
use Waypost\Http\Controllers\SiteUserController;
use Waypost\Http\Controllers\SyncController;
use Waypost\Http\Controllers\UserController;
use Waypost\Http\Middleware\Authenticate;
use Waypost\Http\Middleware\HandleIdempotencyKey;
use Waypost\Http\Middleware\ValidateSchema;

// Loaded by WaypostServiceProvider::apiRoutes(): under waypost.route.prefix, named "waypost.…", with server_time
// and the waypost.route.middleware. Middleware order of a route: Authenticate, throttling, HandleIdempotencyKey
// (write requests), ValidateSchema.

/**
 * The middleware of a write request: Idempotency-Key, and the body against the schema (api/<name>.request).
 *
 * @return list<string>
 */
$write = fn (?string $schema = null): array => $schema === null
    ? [HandleIdempotencyKey::class]
    : [HandleIdempotencyKey::class, ValidateSchema::using("api/{$schema}.request")];

// No token, so no Idempotency-Key: a repeated login only issues another token.
Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware([ThrottleRequests::using('waypost-login'), ValidateSchema::using('api/auth-login.request')])
    ->name('auth.login');

Route::middleware([Authenticate::using(Authenticate::SUPERVISOR), ThrottleRequests::using('waypost')])->group(function () use ($write): void {
    // No Idempotency-Key: a repeated logout finds no token and answers unauthenticated, which means the same.
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    // With the supervisor's own token only: the site phone itself is what gets enrolled.
    Route::post('devices/enroll', [DeviceController::class, 'enroll'])->middleware($write('devices-enroll'))->name('devices.enroll');
});

// The site phone, or a supervisor's own phone (spec: Szinkron protokoll / Pull).
Route::middleware([Authenticate::using(Authenticate::SUPERVISOR, Authenticate::DEVICE), ThrottleRequests::using('waypost')])->group(function (): void {
    Route::get('sync/pull', [SyncController::class, 'pull'])->name('sync.pull');
});

// Configuration (spec: API-szerződés): a supervisor, or the site phone for a supervisor who logged in on it with
// their PIN (X-Acting-User). A path parameter is a UUID, otherwise there is no such endpoint (and PostgreSQL's uuid
// column is never queried with something else).
Route::middleware([Authenticate::using(Authenticate::SUPERVISOR, Authenticate::ACTING), ThrottleRequests::using('waypost')])
    ->whereUuid(['site', 'user', 'checkpoint', 'route', 'version', 'schedule', 'subscription'])
    ->group(function () use ($write): void {
        Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
        Route::post('sites', [SiteController::class, 'store'])->middleware($write('site-create'))->name('sites.store');
        Route::get('sites/{site}', [SiteController::class, 'show'])->name('sites.show');
        Route::patch('sites/{site}', [SiteController::class, 'update'])->middleware($write('site-update'))->name('sites.update');
        Route::delete('sites/{site}', [SiteController::class, 'destroy'])->middleware($write())->name('sites.destroy');

        Route::get('sites/{site}/users', [SiteUserController::class, 'index'])->name('sites.users.index');
        Route::post('sites/{site}/users', [SiteUserController::class, 'store'])->middleware($write('site-user-attach'))->name('sites.users.store');
        Route::delete('sites/{site}/users/{user}', [SiteUserController::class, 'destroy'])->middleware($write())->name('sites.users.destroy');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->middleware($write('user-create'))->name('users.store');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::patch('users/{user}', [UserController::class, 'update'])->middleware($write('user-update'))->name('users.update');
        Route::put('users/{user}/pin', [UserController::class, 'pin'])->middleware($write('user-pin'))->name('users.pin');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware($write())->name('users.destroy');

        Route::get('checkpoints', [CheckpointController::class, 'index'])->name('checkpoints.index');
        Route::post('checkpoints', [CheckpointController::class, 'store'])->middleware($write('checkpoint-create'))->name('checkpoints.store');
        Route::get('checkpoints/{checkpoint}', [CheckpointController::class, 'show'])->name('checkpoints.show');
        Route::patch('checkpoints/{checkpoint}', [CheckpointController::class, 'update'])->middleware($write('checkpoint-update'))->name('checkpoints.update');
        Route::delete('checkpoints/{checkpoint}', [CheckpointController::class, 'destroy'])->middleware($write())->name('checkpoints.destroy');

        Route::get('routes', [RouteController::class, 'index'])->name('routes.index');
        Route::post('routes', [RouteController::class, 'store'])->middleware($write('route-create'))->name('routes.store');
        Route::get('routes/{route}', [RouteController::class, 'show'])->name('routes.show');
        Route::patch('routes/{route}', [RouteController::class, 'update'])->middleware($write('route-update'))->name('routes.update');
        Route::delete('routes/{route}', [RouteController::class, 'destroy'])->middleware($write())->name('routes.destroy');

        // A version never changes: GET only, any other method is 405.
        Route::get('routes/{route}/versions', [RouteVersionController::class, 'index'])->name('routes.versions.index');
        Route::post('routes/{route}/versions', [RouteVersionController::class, 'store'])->middleware($write('route-version-create'))->name('routes.versions.store');
        Route::get('routes/{route}/versions/{version}', [RouteVersionController::class, 'show'])->name('routes.versions.show');

        Route::get('schedules', [ScheduleController::class, 'index'])->name('schedules.index');
        Route::post('schedules', [ScheduleController::class, 'store'])->middleware($write('schedule-create'))->name('schedules.store');
        Route::get('schedules/{schedule}', [ScheduleController::class, 'show'])->name('schedules.show');
        Route::patch('schedules/{schedule}', [ScheduleController::class, 'update'])->middleware($write('schedule-update'))->name('schedules.update');
        Route::delete('schedules/{schedule}', [ScheduleController::class, 'destroy'])->middleware($write())->name('schedules.destroy');

        Route::get('report-subscriptions', [ReportSubscriptionController::class, 'index'])->name('report-subscriptions.index');
        Route::post('report-subscriptions', [ReportSubscriptionController::class, 'store'])->middleware($write('report-subscription-create'))->name('report-subscriptions.store');
        Route::get('report-subscriptions/{subscription}', [ReportSubscriptionController::class, 'show'])->name('report-subscriptions.show');
        Route::patch('report-subscriptions/{subscription}', [ReportSubscriptionController::class, 'update'])->middleware($write('report-subscription-update'))->name('report-subscriptions.update');
        Route::delete('report-subscriptions/{subscription}', [ReportSubscriptionController::class, 'destroy'])->middleware($write())->name('report-subscriptions.destroy');
    });
