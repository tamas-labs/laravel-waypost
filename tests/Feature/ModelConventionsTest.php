<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;
use Waypost\Support\Time;
use Waypost\Support\Uuid;
use Waypost\Tests\Fixtures\Widget;

use function Pest\Laravel\travelTo;

// The table follows the package's migration conventions: UUID key, datetime(3) columns.
beforeEach(function (): void {
    Schema::dropIfExists(Table::name('widgets'));
    Schema::create(Table::name('widgets'), function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->dateTime('happened_at', 3)->nullable();
        $table->datetimes(3);
        $table->softDeletesDatetime(precision: 3);
    });
});

afterEach(function (): void {
    Schema::dropIfExists(Table::name('widgets'));
    date_default_timezone_set('UTC');
});

/** Simulates a host application in another time zone. */
function useBudapestTime(): void
{
    config(['app.timezone' => 'Europe/Budapest']);
    date_default_timezone_set('Europe/Budapest');
}

/** @return array<string, mixed> */
function rawWidget(string $id): array
{
    return (array) DB::table(Table::name('widgets'))->where('id', $id)->first();
}

it('assigns a UUIDv7 key on create', function (): void {
    $widget = Widget::create();

    expect($widget->id)->toMatch(Uuid::PATTERN)
        ->and(rawWidget($widget->id)['id'])->toBe($widget->id);
});

it('keeps a key set by the client', function (): void {
    $id = Uuid::v7();

    expect(Widget::create(['id' => $id])->id)->toBe($id)
        ->and(Widget::find($id))->not->toBeNull();
});

it('stores timestamps in UTC with milliseconds whatever the application time zone is', function (): void {
    useBudapestTime();
    travelTo(Carbon::parse('2026-10-27 21:00:00.123', 'Europe/Budapest'));

    $widget = Widget::create(['happened_at' => Carbon::parse('2026-10-24 22:30:00.456', 'Europe/Budapest')]);
    $widget->delete();

    expect(rawWidget($widget->id))->toMatchArray([
        'happened_at' => '2026-10-24 20:30:00.456',
        'created_at' => '2026-10-27 20:00:00.123',
        'updated_at' => '2026-10-27 20:00:00.123',
        'deleted_at' => '2026-10-27 20:00:00.123',
    ]);
});

it('reads stored values back as UTC', function (): void {
    useBudapestTime();
    $id = Uuid::v7();
    DB::table(Table::name('widgets'))->insert(['id' => $id, 'happened_at' => '2026-10-27 20:00:00.789']);

    $happenedAt = Widget::findOrFail($id)->happened_at;

    expect($happenedAt?->getTimezone()->getName())->toBe('UTC')
        ->and($happenedAt?->format('Y-m-d H:i:s.v'))->toBe('2026-10-27 20:00:00.789');
});

it('accepts ISO strings and immutable instances', function (): void {
    $widget = Widget::create(['happened_at' => '2026-10-27T21:00:00.001+01:00']);
    $other = Widget::create(['happened_at' => CarbonImmutable::parse('2026-10-27 20:00:00.002', 'UTC')]);

    expect(rawWidget($widget->id)['happened_at'])->toBe('2026-10-27 20:00:00.001')
        ->and(rawWidget($other->id)['happened_at'])->toBe('2026-10-27 20:00:00.002');
});

it('serialises dates in the API form', function (): void {
    useBudapestTime();
    travelTo(CarbonImmutable::parse('2026-10-27 20:00:00.123', 'UTC'));

    $widget = Widget::create(['happened_at' => '2026-10-27T20:00:00.000Z']);
    $array = Widget::findOrFail($widget->id)->toArray();

    expect($array)->toMatchArray([
        'happened_at' => '2026-10-27T20:00:00.000Z',
        'created_at' => '2026-10-27T20:00:00.123Z',
        'updated_at' => '2026-10-27T20:00:00.123Z',
        'deleted_at' => null,
    ])->and($array['created_at'])->toMatch(Time::ISO_PATTERN);
});
