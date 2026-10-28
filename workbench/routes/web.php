<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'Waypost workbench',
    'api_prefix' => config('waypost.route.prefix'),
    'database' => DB::connection()->getDriverName() . ' ' . DB::connection()->getDatabaseName(),
]));
