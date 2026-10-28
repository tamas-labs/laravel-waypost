<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Waypost\Enums\ErrorCode;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Time;
use Waypost\WaypostServiceProvider;

use function Pest\Laravel\getJson;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    WaypostServiceProvider::apiRoutes(function (): void {
        Route::get('_test/ok', fn () => ['value' => 1]);
        Route::get('_test/empty', fn () => []);
        Route::get('_test/unauthenticated', fn () => throw new AuthenticationException());
        Route::get('_test/device-revoked', fn () => throw ApiException::deviceRevoked());
        Route::get('_test/forbidden', fn () => throw new AuthorizationException('This action is unauthorized.'));
        Route::get('_test/hidden', fn () => throw (new AuthorizationException())->withStatus(404));
        Route::get('_test/missing-model', fn () => throw (new ModelNotFoundException())->setModel(Site::class));
        Route::get('_test/sites/{site}', fn (Site $site) => ["id" => $site->id]);
        Route::get('_test/validation', fn () => throw ValidationException::withMessages([
            'name' => ['A név megadása kötelező.'],
            'entries.3.seq' => ['A sorszámnak pozitív egésznek kell lennie.'],
        ]));
        Route::get('_test/conflict', fn () => throw ApiException::conflict('Ez a PIN-kód már foglalt ezen a telephelyen.', ['pin' => ['Foglalt PIN-kód.']]));
        Route::get('_test/unique-index', function (): never {
            $user = User::factory()->supervisor()->create();
            User::factory()->supervisor()->create(['email' => $user->email]);

            throw new LogicException('unreachable');
        });
        Route::get('_test/throttled', fn () => ['value' => 1])->middleware(ThrottleRequests::using('waypost'));
        Route::get('_test/crash', fn () => throw new RuntimeException('Secret internals: database password is hunter2'));
        Route::get('_test/maintenance', fn () => abort(503));
        Route::post('_test/post-only', fn () => []);
    });
});

/**
 * @param  Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
 * @return array<string, mixed>
 */
function errorOf(Illuminate\Testing\TestResponse $response): array
{
    expect($response)->toMatchSchema('api/error');

    /** @var array<string, mixed> */
    return $response->json('error');
}

it('adds server_time first to every JSON response', function (): void {
    travelTo(Time::fromIso('2026-10-27T20:00:00.123Z'));

    $response = getJson('api/waypost/v1/_test/ok')->assertOk();

    expect($response->getContent())->toBe('{"server_time":"2026-10-27T20:00:00.123Z","value":1}');
    expect(getJson('api/waypost/v1/_test/empty')->getContent())->toBe('{"server_time":"2026-10-27T20:00:00.123Z"}');
});

it('answers every error code with its status, in the error format', function (string $uri, ErrorCode $code, int $status, string $message): void {
    travelTo(Time::fromIso('2026-10-27T20:00:00.000Z'));

    $response = getJson("api/waypost/v1/_test/{$uri}")->assertStatus($status);

    expect($response->json('server_time'))->toBe('2026-10-27T20:00:00.000Z');
    expect(errorOf($response))->toBe(['code' => $code->value, 'message' => $message]);
})->with([
    'unauthenticated' => ['unauthenticated', ErrorCode::Unauthenticated, 401, 'Bejelentkezés szükséges.'],
    'device_revoked' => ['device-revoked', ErrorCode::DeviceRevoked, 401, 'Ezt a telefont visszavonták.'],
    'forbidden' => ['forbidden', ErrorCode::Forbidden, 403, 'Ehhez nincs jogosultságod.'],
    'not_found (record)' => ['missing-model', ErrorCode::NotFound, 404, 'A keresett elem nem található.'],
    'not_found (denied as not found)' => ['hidden', ErrorCode::NotFound, 404, 'A keresett elem nem található.'],
    'not_found (route binding)' => ['sites/0199a1b2-0000-7000-8000-000000000000', ErrorCode::NotFound, 404, 'A keresett elem nem található.'],
    'not_found (endpoint)' => ['nothing-here', ErrorCode::NotFound, 404, 'Nincs ilyen végpont.'],
    'conflict (unique index)' => ['unique-index', ErrorCode::Conflict, 409, 'Az adat ütközik egy meglévővel.'],
    'bad_request (method)' => ['post-only', ErrorCode::BadRequest, 405, 'Ez a végpont ezt a kérésmódot nem támogatja.'],
    'server_error (maintenance)' => ['maintenance', ErrorCode::ServerError, 503, 'A szolgáltatás átmenetileg nem érhető el, próbáld újra később.'],
]);

it('lists the messages per field for validation_failed', function (): void {
    $response = getJson('api/waypost/v1/_test/validation')->assertStatus(422);

    expect(errorOf($response))->toBe([
        'code' => 'validation_failed',
        'message' => 'A megadott adatok hibásak.',
        'details' => [
            'name' => ['A név megadása kötelező.'],
            'entries.3.seq' => ['A sorszámnak pozitív egésznek kell lennie.'],
        ],
    ]);
});

it('keeps the message and the details of a conflict', function (): void {
    $response = getJson('api/waypost/v1/_test/conflict')->assertStatus(409);

    expect(errorOf($response))->toBe([
        'code' => 'conflict',
        'message' => 'Ez a PIN-kód már foglalt ezen a telephelyen.',
        'details' => ['pin' => ['Foglalt PIN-kód.']],
    ]);
});

it('answers rate_limited with Retry-After above the limit', function (): void {
    config(['waypost.rate_limits.api' => 2]);

    getJson('api/waypost/v1/_test/throttled')->assertOk();
    getJson('api/waypost/v1/_test/throttled')->assertOk();
    $response = getJson('api/waypost/v1/_test/throttled')->assertStatus(429)->assertHeader('Retry-After');

    expect(errorOf($response))->toBe(['code' => 'rate_limited', 'message' => 'Túl sok kérés, próbáld újra később.']);
});

it('hides the details of an unexpected error', function (): void {
    config(['app.debug' => true]);

    $response = getJson('api/waypost/v1/_test/crash')->assertStatus(500);

    expect(errorOf($response))->toBe(['code' => 'server_error', 'message' => 'Váratlan szerverhiba, próbáld újra később.']);
    expect($response->getContent())->not->toContain('hunter2');
});

it('uses the error format also for a request that does not ask for JSON', function (): void {
    $response = get('api/waypost/v1/_test/unauthenticated', ['Accept' => 'text/html'])->assertStatus(401);

    expect(errorOf($response)['code'])->toBe('unauthenticated');
});

it('follows the configured prefix', function (): void {
    config(['waypost.route.prefix' => 'custom/api']);

    getJson('custom/api/anything')->assertNotFound()->assertJsonPath('error.message', 'Nincs ilyen végpont.');
});

it('formats the errors of its routes whatever the host\'s exception handler is', function (string $uri, int $status, string $code): void {
    // Like Collision's adapter on the command line: no render callbacks of Laravel's handler.
    app()->instance(ExceptionHandler::class, new class implements ExceptionHandler {
        public function report(Throwable $e): void
        {
        }

        public function shouldReport(Throwable $e): bool
        {
            return false;
        }

        public function render($request, Throwable $e): JsonResponse
        {
            return new JsonResponse(['message' => 'The host\'s own format.'], 500);
        }

        public function renderForConsole($output, Throwable $e): void
        {
        }
    });

    $response = getJson("api/waypost/v1/_test/{$uri}")->assertStatus($status);

    expect(errorOf($response)['code'])->toBe($code);
})->with([
    'unauthenticated' => ['unauthenticated', 401, 'unauthenticated'],
    'forbidden' => ['forbidden', 403, 'forbidden'],
    'not_found (record)' => ['missing-model', 404, 'not_found'],
    'not_found (route binding)' => ['sites/0199a1b2-0000-7000-8000-000000000000', 404, 'not_found'],
    'validation_failed' => ['validation', 422, 'validation_failed'],
    'conflict' => ['conflict', 409, 'conflict'],
    'not_found (denied as not found)' => ['hidden', 404, 'not_found'],
    'server_error' => ['crash', 500, 'server_error'],
]);

it('leaves the errors of the host application alone', function (): void {
    Route::get('host/forbidden', fn () => throw new AuthorizationException());

    getJson('host/forbidden')->assertForbidden()->assertJsonMissingPath('error')->assertJsonPath('message', 'This action is unauthorized.');
    postJson('api/waypost/v1-other')->assertNotFound()->assertJsonMissingPath('error');
});

it('renders an unauthenticated request of the host application as before', function (): void {
    Route::get('host/private', fn (Request $request) => throw new AuthenticationException());

    getJson('host/private')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
});
