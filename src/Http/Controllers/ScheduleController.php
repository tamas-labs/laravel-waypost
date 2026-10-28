<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Waypost\Auth\Caller;
use Waypost\Enums\ScheduleMode;
use Waypost\Http\Entities;
use Waypost\Models\Route;
use Waypost\Models\Schedule;
use Waypost\Schedule\TimeLimit;
use Waypost\Validation\SchemaValidator;

/** /schedules (spec: Menetrend → körök): when the rounds of the routes of the caller's sites start. */
final class ScheduleController extends Controller
{
    private const array MODE_FIELDS = [
        ScheduleMode::List->value => ['start_times'],
        ScheduleMode::Interval->value => ['interval_from', 'interval_to', 'interval_every_min'],
    ];

    /** @return array{schedules: list<array<string, mixed>>} */
    public function index(Request $request, Caller $caller): array
    {
        $routeId = self::uuidParameter($request, 'route_id');
        $schedules = Schedule::query()
            ->whereHas('route', fn (Builder $routes) => $routes->whereIn('site_id', self::visibleSites($request, $caller)))
            ->when($routeId, fn (Builder $schedules, string $id) => $schedules->where('route_id', $id))
            ->orderBy('name')->orderBy('id')
            ->get();

        return ['schedules' => array_values($schedules->map(Entities::schedule(...))->all())];
    }

    /**
     * Without time_limit_min the limit comes from the route's current version (TimeLimit::default()); null means
     * no limit.
     */
    public function store(Request $request, Caller $caller, SchemaValidator $validator): JsonResponse
    {
        $input = self::input($request);
        $route = Route::query()->whereIn('site_id', $caller->siteIds())->find($request->string('route_id')->toString());
        if ($route === null) {
            self::invalid('route_id', 'Nincs ilyen útvonal.');
        }
        $this->gate->authorize('create', [Schedule::class, $route]);

        $schedule = new Schedule([...$input, 'id' => self::newId($request, Schedule::class)]);
        if (!array_key_exists('time_limit_min', $input)) {
            $schedule->time_limit_min = TimeLimit::default($route->currentVersion?->walk_duration_s);
        }
        self::save($schedule, $validator);

        return self::created(['schedule' => Entities::schedule($schedule)]);
    }

    /** @return array{schedule: array<string, mixed>} */
    public function show(Schedule $schedule): array
    {
        $this->gate->authorize('view', $schedule);

        return ['schedule' => Entities::schedule($schedule)];
    }

    /**
     * A new mode comes with its own fields (the schema requires them), and the fields of the old mode are cleared.
     * Without mode only the fields of the current mode can change.
     *
     * @return array{schedule: array<string, mixed>}
     */
    public function update(Request $request, Schedule $schedule, SchemaValidator $validator): array
    {
        $this->gate->authorize('update', $schedule);
        $input = self::input($request);

        if (isset($input['mode']) && $input['mode'] !== $schedule->mode->value) {
            foreach (self::MODE_FIELDS[$schedule->mode->value] as $field) {
                $input[$field] = null;
            }
        } elseif (!isset($input['mode'])) {
            $other = $schedule->mode === ScheduleMode::List ? ScheduleMode::Interval : ScheduleMode::List;
            foreach (self::MODE_FIELDS[$other->value] as $field) {
                if (array_key_exists($field, $input)) {
                    self::invalid($field, $other === ScheduleMode::List ? 'Csak lista módban adható meg.' : 'Csak intervallum módban adható meg.');
                }
            }
        }

        $schedule->fill($input);
        self::save($schedule, $validator);

        return ['schedule' => Entities::schedule($schedule)];
    }

    /** @return array{} */
    public function destroy(Schedule $schedule): array
    {
        $this->gate->authorize('delete', $schedule);

        $schedule->delete();

        return [];
    }

    /** The rules the schema cannot express (waypost-schema decisions: list mode is a sequence). */
    private static function save(Schedule $schedule, SchemaValidator $validator): void
    {
        $schedule->updateTimestamps();
        $validator->assertValid(Entities::schedule($schedule), 'entities/schedule');

        if ($schedule->mode === ScheduleMode::List && !self::isSequence($schedule->start_times ?? [])) {
            self::invalid('start_times', 'Az időpontok legfeljebb egyszer léphetnek át a következő napra, és akkor az utolsó korábbi legyen az elsőnél.');
        }
        if ($schedule->mode === ScheduleMode::Interval && $schedule->interval_from === $schedule->interval_to) {
            self::invalid('interval_to', 'Nem lehet ugyanaz, mint a kezdete.');
        }

        $schedule->save();
    }

    /**
     * At most one step back (the next element is on the next day), and then the last element is earlier than the
     * first, so the window is shorter than 24 hours.
     *
     * @param  list<string>  $times  HH:MM
     */
    private static function isSequence(array $times): bool
    {
        $steps = 0;
        for ($i = 1, $count = count($times); $i < $count; $i++) {
            if ($times[$i] <= $times[$i - 1]) {
                $steps++;
            }
        }

        return $steps === 0 || ($steps === 1 && $times[count($times) - 1] < $times[0]);
    }
}
