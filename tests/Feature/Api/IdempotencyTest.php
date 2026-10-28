<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Waypost\Http\Middleware\Authenticate;
use Waypost\Http\Middleware\HandleIdempotencyKey;
use Waypost\Jobs\PruneIdempotencyKeys;
use Waypost\Models\Device;
use Waypost\Models\IdempotencyKey;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Time;
use Waypost\WaypostServiceProvider;

use function Pest\Laravel\call;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

const KEY = '0199a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b';

beforeEach(function (): void {
    travelTo(Time::fromIso('2026-10-27T20:00:00.000Z'));
    WaypostServiceProvider::apiRoutes(function (): void {
        Route::middleware([Authenticate::using(Authenticate::SUPERVISOR, Authenticate::DEVICE), HandleIdempotencyKey::class])->group(function (): void {
            Route::post('_test/sites', fn (Request $request) => response()->json(['id' => Site::factory()->create(['name' => $request->string('name')->toString()])->id], 201));
            Route::post('_test/invalid', fn () => throw ValidationException::withMessages(['name' => ['A név megadása kötelező.']]));
            Route::post('_test/crash', function (): never {
                Site::factory()->create();

                throw new RuntimeException('boom');
            });
            Route::put('_test/media', fn (Request $request) => ['sha256' => $request->string('sha256')->toString(), 'count' => Site::factory()->create()->id]);
        });
    });
});

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function write(string $token, array $body = ['name' => 'Telephely A'], ?string $key = KEY, string $uri = 'sites'): TestResponse
{
    $headers = ['Authorization' => "Bearer {$token}"];
    if ($key !== null) {
        $headers['Idempotency-Key'] = $key;
    }

    return postJson("api/waypost/v1/_test/{$uri}", $body, $headers);
}

it('requires a UUID Idempotency-Key', function (?string $key, string $message): void {
    $response = write(supervisorToken(User::factory()->supervisor()->create()), key: $key)->assertUnprocessable();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.details'))->toBe(['Idempotency-Key' => [$message]]);
    expect(Site::query()->count())->toBe(0);
})->with([
    'missing' => [null, 'Az Idempotency-Key fejléc kötelező.'],
    'not a UUID' => ['12345', 'Az Idempotency-Key fejlécnek UUID-nak kell lennie.'],
    'empty' => ['', 'Az Idempotency-Key fejlécnek UUID-nak kell lennie.'],
]);

it('authenticates before it looks at the key', function (): void {
    write('nope', key: null)->assertUnauthorized();
});

it('replays the response to a repeated key without running again, with a fresh server_time', function (): void {
    $token = supervisorToken(User::factory()->supervisor()->create());
    $first = write($token)->assertCreated();
    travelTo(Time::fromIso('2026-10-28T19:59:59.999Z'));

    $second = write($token)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(Site::query()->count())->toBe(1);
    expect($second->json('id'))->toBe($first->json('id'));
    expect($first->json('server_time'))->toBe('2026-10-27T20:00:00.000Z');
    expect($second->json('server_time'))->toBe('2026-10-28T19:59:59.999Z');
    $first->assertHeaderMissing('Idempotent-Replayed');
});

it('treats the key case-insensitively', function (): void {
    $token = supervisorToken(User::factory()->supervisor()->create());
    write($token, key: strtoupper(KEY))->assertCreated();

    write($token)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect(Site::query()->count())->toBe(1);
});

it('refuses the same key with another request', function (array $body, string $uri): void {
    $token = supervisorToken(User::factory()->supervisor()->create());
    write($token)->assertCreated();

    $response = write($token, $body, uri: $uri)->assertUnprocessable();

    expect($response->json('error.details'))->toBe(['Idempotency-Key' => ['Ezt az Idempotency-Key-t már egy másik kéréshez használtad.']]);
    expect(Site::query()->count())->toBe(1);
})->with([
    'other body' => [['name' => 'Telephely B'], 'sites'],
    'other path' => [['name' => 'Telephely A'], 'invalid'],
]);

it('keeps the keys of every caller apart', function (): void {
    $device = Device::factory()->create();
    $supervisor = supervisorOf($device->site()->firstOrFail());

    write(supervisorToken($supervisor))->assertCreated();
    write(deviceToken($device))->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    write(supervisorToken(User::factory()->supervisor()->create()))->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    // A second token of the same supervisor is the same caller.
    write(supervisorToken($supervisor))->assertHeader('Idempotent-Replayed', 'true');

    expect(Site::query()->count())->toBe(4);
});

it('counts the acting supervisor\'s requests as the device\'s', function (): void {
    WaypostServiceProvider::apiRoutes(fn () => Route::post('_test/acting', fn () => ['id' => Site::factory()->create()->id])
        ->middleware([Authenticate::using(Authenticate::ACTING), HandleIdempotencyKey::class]));
    $device = Device::factory()->create();
    $headers = ['Authorization' => 'Bearer ' . deviceToken($device), 'Idempotency-Key' => KEY];

    postJson('api/waypost/v1/_test/acting', [], [...$headers, 'X-Acting-User' => supervisorOf($device->site()->firstOrFail())->id])->assertOk();
    postJson('api/waypost/v1/_test/acting', [], [...$headers, 'X-Acting-User' => supervisorOf($device->site()->firstOrFail())->id])
        ->assertHeader('Idempotent-Replayed', 'true');

    expect(IdempotencyKey::query()->sole()->owner?->is($device))->toBeTrue();
});

it('replays a client error too', function (): void {
    $token = supervisorToken(User::factory()->supervisor()->create());
    write($token, uri: 'invalid')->assertUnprocessable();

    $response = write($token, uri: 'invalid')->assertUnprocessable()->assertHeader('Idempotent-Replayed', 'true');

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.details'))->toBe(['name' => ['A név megadása kötelező.']]);
});

it('forgets the key after a server error, so the request can be retried', function (): void {
    $token = supervisorToken(User::factory()->supervisor()->create());

    write($token, uri: 'crash')->assertStatus(500);
    write($token, uri: 'crash')->assertStatus(500)->assertHeaderMissing('Idempotent-Replayed');

    expect(IdempotencyKey::query()->count())->toBe(0);
});

it('answers conflict while the first request with the key still runs', function (): void {
    $user = User::factory()->supervisor()->create();
    $token = supervisorToken($user);
    write($token)->assertCreated();
    IdempotencyKey::query()->update(['response_status' => null, 'response_body' => null]);

    $response = write($token)->assertStatus(409);

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.message'))->toBe('Ugyanez a kérés még feldolgozás alatt van, próbáld újra később.');
});

it('runs the request again once the key has expired', function (): void {
    $token = supervisorToken(User::factory()->supervisor()->create());
    write($token)->assertCreated();

    travelTo(Time::fromIso('2026-10-28T19:59:59.999Z'));
    write($token)->assertHeader('Idempotent-Replayed', 'true');
    travelTo(Time::fromIso('2026-10-28T20:00:00.000Z'));
    write($token, ['name' => 'Más törzs'])->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

    expect(Site::query()->count())->toBe(2);
    expect(IdempotencyKey::query()->sole()->expires_at->toIso8601ZuluString('millisecond'))->toBe('2026-10-29T20:00:00.000Z');
});

it('stores the caller, the request and the response', function (): void {
    $user = User::factory()->supervisor()->create();
    $response = write(supervisorToken($user))->assertCreated();

    $record = IdempotencyKey::query()->sole();
    expect($record->owner?->is($user))->toBeTrue();
    expect($record->only(['key', 'method', 'path', 'response_status']))->toBe([
        'key' => KEY,
        'method' => 'POST',
        'path' => '/api/waypost/v1/_test/sites',
        'response_status' => 201,
    ]);
    expect($record->request_hash)->toMatch('/^[0-9a-f]{64}$/');
    expect(json_decode((string) $record->response_body, true))->toBe(['id' => $response->json('id')]);
});

it('fingerprints a multipart upload by its fields and file contents, not by its boundary', function (): void {
    $token = supervisorToken(User::factory()->supervisor()->create());
    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    $upload = fn (string $content, string $sha256 = 'abc'): TestResponse => call(
        'PUT',
        'api/waypost/v1/_test/media',
        ['sha256' => $sha256],
        files: ['file' => UploadedFile::fake()->createWithContent('photo.jpg', $content)],
        server: ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_IDEMPOTENCY_KEY' => KEY, 'HTTP_ACCEPT' => 'application/json'],
    );

    $upload('jpeg bytes')->assertOk();
    $upload('jpeg bytes')->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    $upload('other bytes')->assertUnprocessable();
    $upload('jpeg bytes', 'def')->assertUnprocessable();
});

it('prunes the expired keys hourly', function (): void {
    $user = User::factory()->supervisor()->create();
    $expired = IdempotencyKey::factory()->for($user, 'owner')->create(['expires_at' => Time::now()]);
    $alive = IdempotencyKey::factory()->for($user, 'owner')->create(['expires_at' => Time::now()->addMillisecond()]);

    dispatch(new PruneIdempotencyKeys());

    expect(IdempotencyKey::query()->pluck('id')->all())->toBe([$alive->id]);
    expect(IdempotencyKey::query()->find($expired->id))->toBeNull();

    $events = array_filter(app(Schedule::class)->events(), fn ($event): bool => $event instanceof CallbackEvent && $event->description === 'waypost:prune-idempotency-keys');
    expect($events)->toHaveCount(1);
    expect(array_values($events)[0]->expression)->toBe('0 * * * *');
});

it('queues the pruning on the configured queue', function (): void {
    config(['waypost.queue.connection' => 'redis', 'waypost.queue.queue' => 'waypost']);

    $job = new PruneIdempotencyKeys();

    expect([$job->connection, $job->queue])->toBe(['redis', 'waypost']);
});
