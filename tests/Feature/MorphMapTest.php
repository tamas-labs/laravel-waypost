<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Waypost\Models\Device;
use Waypost\Models\IdempotencyKey;
use Waypost\Models\Incident;
use Waypost\Models\Media;
use Waypost\Models\MorphMap;
use Waypost\Models\PersonalAccessToken;
use Waypost\Models\Scan;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Table;
use Waypost\Support\Uuid;
use Waypost\Tests\Fixtures\ChainBuilder;

use function Pest\Laravel\postJson;

pest()->use(RefreshDatabase::class);

it('has a prefixed alias for every model of the package', function (): void {
    $models = [];
    foreach (glob(dirname(__DIR__, 2) . '/src/Models/*.php') ?: [] as $file) {
        $class = 'Waypost\\Models\\' . basename($file, '.php');
        if (is_subclass_of($class, EloquentModel::class) && !(new ReflectionClass($class))->isAbstract()) {
            $models[] = $class;
        }
    }
    sort($models);
    $mapped = array_values(MorphMap::ALIASES);
    sort($mapped);

    expect($mapped)->toBe($models);
    foreach (MorphMap::ALIASES as $alias => $class) {
        expect($alias)->toStartWith('waypost.')
            ->and((new $class())->getMorphClass())->toBe($alias)
            ->and(Relation::getMorphedModel($alias))->toBe($class);
    }
});

it('works in a host that enforces its morph map', function (): void {
    Relation::requireMorphMap();
    try {
        $site = Site::factory()->create();
        $supervisor = supervisorOf($site);
        $supervisor->update(['password' => 'titkos-jelszo']);
        postJson('api/waypost/v1/auth/login', ['email' => $supervisor->email, 'password' => 'titkos-jelszo', 'device_name' => 'Pixel 8'])->assertOk();

        $phone = Device::factory()->for($site)->create();
        $chain = new ChainBuilder($phone);
        $shift = $chain->shiftStarted();
        $photoId = Uuid::v7();
        $incident = $chain->incident($shift['payload']['shift_id'], ['media' => [['id' => $photoId, 'sha256' => hash('sha256', 'x')]]]);
        pushAs($phone, [$shift, $incident])->assertOk();

        expect(PersonalAccessToken::query()->where('tokenable_id', $supervisor->id)->sole()->tokenable_type)->toBe('waypost.user')
            ->and(Media::query()->whereKey($photoId)->sole()->owner_type)->toBe('waypost.incident')
            ->and(Media::query()->sole()->owner)->toBeInstanceOf(Incident::class)
            ->and(IdempotencyKey::query()->sole()->owner_type)->toBe('waypost.device');
    } finally {
        Relation::requireMorphMap(false);
    }
});

it('turns the stored class names into the aliases, and back', function (): void {
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_11_05_000020_use_waypost_morph_aliases.php';
    $scan = Scan::factory()->create();
    $photo = Media::factory()->create(['owner_type' => 'waypost.scan', 'owner_id' => $scan->id]);
    $user = User::factory()->create();
    $user->createToken('test');
    $rows = fn (): array => [
        DB::table(Table::name('media'))->where('id', $photo->id)->value('owner_type'),
        DB::table(Table::name('personal_access_tokens'))->where('tokenable_id', $user->id)->value('tokenable_type'),
    ];

    $migration->down();
    expect($rows())->toBe([Scan::class, User::class]);

    $migration->up();
    expect($rows())->toBe(['waypost.scan', 'waypost.user'])
        ->and(Media::query()->whereKey($photo->id)->sole()->owner?->is($scan))->toBeTrue();
});
