<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;
use Waypost\Auth\Caller;
use Waypost\Http\Entities;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Models\Checkpoint;
use Waypost\Models\Route;
use Waypost\Models\RouteVersion;
use Waypost\Models\RouteVersionCheckpoint;

/**
 * /routes/{id}/versions (spec: Betanítás): the checkpoints of a route in immutable versions. A new version becomes
 * the current one; a version is never changed (there is no PATCH or DELETE, the models refuse an update).
 */
final class RouteVersionController extends Controller
{
    /** @return array{route_versions: list<array<string, mixed>>, route_version_checkpoints: list<array<string, mixed>>} */
    public function index(Route $route): array
    {
        $this->gate->authorize('view', $route);
        $versions = $route->versions()->with('points')->get();

        return [
            'route_versions' => array_values($versions->map(Entities::routeVersion(...))->all()),
            'route_version_checkpoints' => array_values($versions->flatMap(
                fn (RouteVersion $version) => $version->points->map(Entities::routeVersionCheckpoint(...)),
            )->all()),
        ];
    }

    /** @return array{route: array<string, mixed>, route_version: array<string, mixed>, route_version_checkpoints: list<array<string, mixed>>} */
    public function show(Route $route, RouteVersion $version): array
    {
        $this->gate->authorize('view', $route);
        if ($version->route_id !== $route->id) {
            throw ApiException::notFound();
        }

        return self::versionResponse($route, $version);
    }

    /**
     * The next version (previous + 1) with the checkpoints in order (position = index + 1), each a checkpoint of the
     * route's site, each once; the first one has no walk time from a previous one.
     */
    public function store(Request $request, Route $route, Caller $caller): JsonResponse
    {
        $this->gate->authorize('update', $route);
        $input = self::input($request);
        /** @var list<array{checkpoint_id: string, walk_time_from_prev_s?: int|null}> $points */
        $points = $input['checkpoints'];
        self::checkPoints($route, $points);
        $creator = $caller->user ?? throw new LogicException('Only a supervisor creates a route version.');
        $id = self::newId($request, RouteVersion::class);

        $version = self::write(function () use ($route, $input, $points, $creator, $id): RouteVersion {
            // Serialises the versions of the route: the next number is read under the lock.
            Route::query()->whereKey($route->id)->lockForUpdate()->first();
            $version = RouteVersion::query()->create([
                'id' => $id,
                'route_id' => $route->id,
                'version' => (int) RouteVersion::query()->where('route_id', $route->id)->max('version') + 1,
                'walk_duration_s' => $input['walk_duration_s'] ?? null,
                'created_by' => $creator->id,
            ]);
            foreach ($points as $index => $point) {
                RouteVersionCheckpoint::query()->create([
                    'route_version_id' => $version->id,
                    'checkpoint_id' => $point['checkpoint_id'],
                    'position' => $index + 1,
                    'walk_time_from_prev_s' => $point['walk_time_from_prev_s'] ?? null,
                ]);
            }
            $route->update(['current_version_id' => $version->id]);

            return $version;
        }, 'Közben az útvonalnak egy másik verziója készült, próbáld újra.');

        return self::created(self::versionResponse($route, $version));
    }

    /** @param  list<array{checkpoint_id: string, walk_time_from_prev_s?: int|null}>  $points */
    private static function checkPoints(Route $route, array $points): void
    {
        $ids = array_column($points, 'checkpoint_id');
        $onSite = Checkpoint::query()->where('site_id', $route->site_id)->whereKey($ids)->pluck('id')->all();

        $errors = [];
        $seen = [];
        foreach ($points as $index => $point) {
            $id = $point['checkpoint_id'];
            if (!in_array($id, $onSite, true)) {
                $errors["checkpoints.{$index}.checkpoint_id"] = ['Nincs ilyen pont ezen a telephelyen.'];
            } elseif (isset($seen[$id])) {
                $errors["checkpoints.{$index}.checkpoint_id"] = ['Ez a pont már szerepel az útvonalban.'];
            }
            $seen[$id] = true;
        }
        if (($points[0]['walk_time_from_prev_s'] ?? null) !== null) {
            $errors['checkpoints.0.walk_time_from_prev_s'] = ['Az első pontnál nem adható meg.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array{route: array<string, mixed>, route_version: array<string, mixed>, route_version_checkpoints: list<array<string, mixed>>} */
    private static function versionResponse(Route $route, RouteVersion $version): array
    {
        return [
            'route' => Entities::route($route),
            'route_version' => Entities::routeVersion($version),
            'route_version_checkpoints' => array_values($version->points()->get()->map(Entities::routeVersionCheckpoint(...))->all()),
        ];
    }
}
