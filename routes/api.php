<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Waypost\Http\Controllers\AuthController;
use Waypost\Http\Middleware\Authenticate;
use Waypost\Http\Middleware\ValidateSchema;

// Loaded by WaypostServiceProvider::apiRoutes(): under waypost.route.prefix, named "waypost.…", with server_time
// and the waypost.route.middleware. Middleware order of a route: Authenticate, throttling, HandleIdempotencyKey
// (write requests), ValidateSchema.

// No token, so no Idempotency-Key: a repeated login only issues another token.
Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware([ThrottleRequests::using('waypost-login'), ValidateSchema::using('api/auth-login.request')])
    ->name('auth.login');

Route::middleware([Authenticate::using(Authenticate::SUPERVISOR), ThrottleRequests::using('waypost')])->group(function (): void {
    // No Idempotency-Key: a repeated logout finds no token and answers unauthenticated, which means the same.
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
});
