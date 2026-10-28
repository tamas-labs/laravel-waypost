<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Waypost\Auth\Caller;
use Waypost\Http\Entities;
use Waypost\Models\Route;
use Waypost\Models\Schedule;

/** /routes: the patrol routes of the caller's sites. Their checkpoints come with versions (RouteVersionController). */
final class RouteController extends Controller
{
    /** @return array{routes: list<array<string, mixed>>} */
    public function index(Request $request, Caller $caller): array
    {
        $routes = Route::query()->whereIn('site_id', self::visibleSites($request, $caller))->orderBy('name')->orderBy('id')->get();

        return ['routes' => array_values($routes->map(Entities::route(...))->all())];
    }

    public function store(Request $request): JsonResponse
    {
        $input = self::input($request);
        $this->gate->authorize('create', [Route::class, $input['site_id']]);

        $route = Route::query()->create([...$input, 'id' => self::newId($request, Route::class)]);

        return self::created(['route' => Entities::route($route)]);
    }

    /** @return array{route: array<string, mixed>} */
    public function show(Route $route): array
    {
        $this->gate->authorize('view', $route);

        return ['route' => Entities::route($route)];
    }

    /** @return array{route: array<string, mixed>} */
    public function update(Request $request, Route $route): array
    {
        $this->gate->authorize('update', $route);

        $route->update(self::input($request));

        return ['route' => Entities::route($route)];
    }

    /**
     * Deletes the route's schedules with it. The versions stay, the rounds of the past refer to them.
     *
     * @return array{}
     */
    public function destroy(Route $route): array
    {
        $this->gate->authorize('delete', $route);

        DB::transaction(function () use ($route): void {
            $route->schedules()->get()->each(fn (Schedule $schedule) => $schedule->delete());
            $route->delete();
        });

        return [];
    }
}
