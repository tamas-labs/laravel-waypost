<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Waypost\Http\Middleware\ValidateSchema;
use Waypost\Validation\SchemaValidator;
use Waypost\WaypostServiceProvider;

use function Pest\Laravel\call;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    WaypostServiceProvider::apiRoutes(function (): void {
        Route::post('_test/push', fn (Request $request) => ['input' => $request->all()])
            ->middleware(ValidateSchema::using('api/sync-push.request'));
        Route::post('_test/login', fn (Request $request) => ['input' => $request->all()])
            ->middleware(ValidateSchema::using('api/auth-login.request'));
    });
});

/**
 * The validation details of a response.
 *
 * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
 * @return array<string, list<string>>|null
 */
function detailsOf(TestResponse $response): ?array
{
    $response->assertUnprocessable();
    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.code'))->toBe('validation_failed');

    /** @var array<string, list<string>>|null */
    return $response->json('error.details');
}

/** @return array<string, mixed> */
function validPush(): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(Waypost\Schema\Paths::root() . '/examples/api/sync-push.request/valid/two-entries.json'), true);
}

it('passes a valid body through, untouched by the host\'s middleware', function (): void {
    $body = ['email' => 'nagy.anna@example.com', 'password' => '  szóközzel  ', 'device_name' => 'Pixel 8'];

    postJson('api/waypost/v1/_test/login', $body)->assertOk()->assertJsonPath('input', $body);
    postJson('api/waypost/v1/_test/push', validPush())->assertOk()->assertJsonPath('input', validPush());
});

it('keys the messages by the dot-separated path of the field', function (): void {
    $body = validPush();
    /** @var array<int, array<string, mixed>> $entries */
    $entries = $body['entries'];
    $entries[1]['seq'] = 'tizenkettő';
    unset($entries[0]['hash']);
    $entries[0]['extra'] = true;
    $body['entries'] = $entries;

    expect(detailsOf(postJson('api/waypost/v1/_test/push', $body)))->toBe([
        'entries.0.hash' => ['Kötelező mező.'],
        'entries.0.extra' => ['Ismeretlen mező.'],
        'entries.1.seq' => ['Hibás típus, egész szám kell.'],
    ]);
});

it('reports an error in a nested schema chosen by the entry type', function (): void {
    $body = validPush();
    /** @var array<int, array<string, mixed>> $entries */
    $entries = $body['entries'];
    $entries[0]['payload']['lat_e7'] = 47.5;
    $body['entries'] = $entries;

    expect(detailsOf(postJson('api/waypost/v1/_test/push', $body)))->toBe(['entries.0.payload.lat_e7' => ['Hibás típus, egész szám kell.']]);
});

it('reports a malformed nullable field without its null branch', function (): void {
    $body = validPush();
    /** @var array<int, array<string, mixed>> $entries */
    $entries = $body['entries'];
    $entries[0]['gnss_time'] = 'tegnap';
    $body['entries'] = $entries;

    expect(detailsOf(postJson('api/waypost/v1/_test/push', $body)))->toBe(['entries.0.gnss_time' => ['Hibás formátum.']]);
});

it('gives the messages of the keywords in Hungarian', function (mixed $value, string $message): void {
    $body = ['email' => 'nagy.anna@example.com', 'password' => 'password', 'device_name' => $value];

    expect(detailsOf(postJson('api/waypost/v1/_test/login', $body)))->toBe(['device_name' => [$message]]);
})->with([
    'empty' => ['', 'Nem lehet üres.'],
    'too long' => [str_repeat('á', 256), 'Legfeljebb 255 karakter lehet.'],
    'number' => [8, 'Hibás típus, szöveg kell.'],
    'null' => [null, 'Hibás típus, szöveg kell.'],
]);

it('refuses a body that is not a JSON object', function (string $content, ?string $type, string $message): void {
    $response = call('POST', 'api/waypost/v1/_test/login', server: array_filter([
        'CONTENT_TYPE' => $type,
        'HTTP_ACCEPT' => 'application/json',
    ]), content: $content);

    expect(detailsOf($response))->toBeNull();
    expect($response->json('error.message'))->toBe($message);
})->with([
    'not JSON' => ['{"email": ', 'application/json', 'A kérés törzse nem érvényes JSON.'],
    'empty' => ['', 'application/json', 'A kérés törzse nem érvényes JSON.'],
    'array' => ['[]', 'application/json', 'A kérés törzsének JSON-objektumnak kell lennie.'],
    'string' => ['"x"', 'application/json', 'A kérés törzsének JSON-objektumnak kell lennie.'],
    'form' => ['email=a', 'application/x-www-form-urlencoded', 'A kérés törzsének JSON-nak kell lennie (Content-Type: application/json).'],
]);

it('agrees with the examples of every request schema', function (): void {
    $validator = app(SchemaValidator::class);
    $checked = 0;
    foreach (glob(Waypost\Schema\Paths::root() . '/examples/api/*.request/*/*.json') ?: [] as $file) {
        $schema = 'api/' . basename(dirname($file, 2));
        $errors = $validator->errors(json_decode((string) file_get_contents($file)), $schema);

        expect($errors === [])->toBe(basename(dirname($file)) === 'valid', $file);
        foreach ($errors as $messages) {
            expect($messages)->not->toBeEmpty();
            foreach ($messages as $message) {
                expect($message)->not->toBe('');
            }
        }
        $checked++;
    }

    expect($checked)->toBeGreaterThan(10);
});
