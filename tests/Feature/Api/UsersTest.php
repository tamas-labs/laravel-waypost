<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Waypost\Enums\Role;
use Waypost\Http\Middleware\HandleIdempotencyKey;
use Waypost\Models\Device;
use Waypost\Models\IdempotencyKey;
use Waypost\Models\PersonalAccessToken;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Pin\PinHash;
use Waypost\Pin\PinHasher;
use Waypost\Support\Uuid;

use function Pest\Laravel\call;
use function Pest\Laravel\postJson;

pest()->use(RefreshDatabase::class);

function hasPin(User $user, string $pin): bool
{
    $hash = PinHash::of($user->refresh());

    return $hash !== null && app(PinHasher::class)->check($pin, $hash);
}

it('creates a guard with a generated PIN, shown once', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);

    $response = callApi('POST', 'users', $supervisor, ['name' => 'Kiss Péter', 'role' => 'guard', 'site_ids' => [$site->id]])->assertCreated();

    expect($response)->toMatchSchema('api/user.response');
    expect($response->json('user'))->toMatchArray([
        'name' => 'Kiss Péter',
        'role' => 'guard',
        'active' => true,
        'site_ids' => [$site->id],
        // Not for the supervisor's own phone: it checks no PIN offline.
        'pin_hash' => null,
    ]);
    $user = User::query()->findOrFail((string) $response->json('user.id'));
    expect($response->json('pin'))->toMatch(schemaPattern('common/pin'));
    expect(hasPin($user, $response->json('pin')))->toBeTrue();

    $again = callApi('GET', "users/{$user->id}", $supervisor)->assertOk();
    expect($again)->toMatchSchema('api/user.response');
    expect($again->json())->not->toHaveKey('pin');
});

it('creates a supervisor who can log in, with the given PIN', function (): void {
    $site = Site::factory()->create();
    $id = Uuid::v7();

    $response = callApi('POST', 'users', supervisorOf($site), [
        'id' => $id, 'name' => 'Nagy Anna', 'role' => 'supervisor', 'email' => 'Anna@Example.com',
        'password' => 'titkos-jelszo', 'pin' => '001234', 'site_ids' => [$site->id],
    ])->assertCreated();

    expect($response->json('user.id'))->toBe($id);
    expect($response->json())->not->toHaveKey('pin');
    $user = User::query()->findOrFail($id);
    expect($user->role)->toBe(Role::Supervisor);
    expect($user->email)->toBe('anna@example.com');
    expect(hasPin($user, '001234'))->toBeTrue();
    postJson('api/waypost/v1/auth/login', ['email' => 'anna@example.com', 'password' => 'titkos-jelszo', 'device_name' => 'Pixel 8'])->assertOk();
});

it('gives the PIN verifier to the site phone', function (): void {
    $site = Site::factory()->create();

    $response = callApi('POST', 'users', supervisorOf($site), ['name' => 'Kiss Péter', 'role' => 'guard', 'pin' => '482913', 'site_ids' => [$site->id]], Device::factory()->for($site)->create())
        ->assertCreated();

    expect($response)->toMatchSchema('api/user.response');
    $user = User::query()->findOrFail((string) $response->json('user.id'));
    expect($response->json('user'))->toMatchArray(['pin_hash' => $user->pin_hash, 'pin_salt' => $user->pin_salt, 'pin_iterations' => 100000]);
});

it('refuses a PIN taken on one of the sites', function (): void {
    [$a, $b] = [Site::factory()->create(), Site::factory()->create()];
    User::factory()->withPin('482913')->create()->sites()->attach($b->id);

    $response = callApi('POST', 'users', supervisorOf($a, $b), ['name' => 'Kiss Péter', 'role' => 'guard', 'pin' => '482913', 'site_ids' => [$a->id, $b->id]])
        ->assertConflict();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error'))->toBe([
        'code' => 'conflict',
        'message' => 'Ez a PIN-kód már foglalt.',
        'details' => ['pin' => ['Egy telephelyen nem lehet két felhasználónak ugyanaz a PIN-kódja.']],
    ]);
    expect(User::query()->where('name', 'Kiss Péter')->exists())->toBeFalse();
});

it('accepts a PIN taken on another site only', function (): void {
    [$a, $b] = [Site::factory()->create(), Site::factory()->create()];
    User::factory()->withPin('482913')->create()->sites()->attach($b->id);

    callApi('POST', 'users', supervisorOf($a), ['name' => 'Kiss Péter', 'role' => 'guard', 'pin' => '482913', 'site_ids' => [$a->id]])->assertCreated();
});

it('refuses a taken e-mail address', function (): void {
    $site = Site::factory()->create();
    User::factory()->supervisor()->create(['email' => 'anna@example.com']);

    $response = callApi('POST', 'users', supervisorOf($site), [
        'name' => 'Nagy Anna', 'role' => 'supervisor', 'email' => 'ANNA@example.com', 'password' => 'titkos-jelszo', 'site_ids' => [$site->id],
    ])->assertConflict();

    expect($response->json('error.message'))->toBe('Ezzel az e-mail-címmel már van felhasználó.');
});

it('creates users only on the caller\'s sites', function (): void {
    [$own, $foreign] = [Site::factory()->create(), Site::factory()->create()];

    $response = callApi('POST', 'users', supervisorOf($own), ['name' => 'Kiss Péter', 'role' => 'guard', 'site_ids' => [$own->id, $foreign->id]])->assertForbidden();

    expect($response->json('error.message'))->toBe('Ehhez a telephelyhez nincs jogosultságod.');
});

it('creates users through the site phone only on its site', function (): void {
    [$own, $other] = [Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($own, $other);

    callApi('POST', 'users', $supervisor, ['name' => 'Kiss Péter', 'role' => 'guard', 'site_ids' => [$other->id]], Device::factory()->for($own)->create())
        ->assertForbidden();
});

it('validates the body against the schema: a guard has no e-mail address', function (): void {
    $site = Site::factory()->create();

    $response = callApi('POST', 'users', supervisorOf($site), ['name' => 'Kiss Péter', 'role' => 'guard', 'email' => 'peter@example.com', 'site_ids' => [$site->id]])
        ->assertUnprocessable();

    expect($response->json('error.details'))->toBe(['email' => ['Ez a mező nem megengedett.']]);
});

it('lists the users who share a site with the caller, and the caller', function (): void {
    [$a, $b, $foreign] = [Site::factory()->create(), Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($a, $b);
    $supervisor->update(['name' => 'Aladár']);
    $guard = User::factory()->create(['name' => 'Béla']);
    $guard->sites()->attach([$b->id, $foreign->id]);
    User::factory()->create()->sites()->attach($foreign->id);
    User::factory()->create();

    $response = callApi('GET', 'users', $supervisor)->assertOk();

    expect($response)->toMatchSchema('api/user-list.response');
    expect($response->json('users.*.id'))->toBe([$supervisor->id, $guard->id]);
    // Only the caller's sites.
    expect($response->json('users.1.site_ids'))->toBe([$b->id]);
    expect(callApi('GET', "users?site_id={$b->id}", $supervisor)->json('users.*.id'))->toBe([$supervisor->id, $guard->id]);
    expect(callApi('GET', "users?site_id={$foreign->id}", $supervisor)->json('users'))->toBe([]);
    callApi('GET', 'users?site_id=1', $supervisor)->assertUnprocessable()->assertJsonPath('error.details', ['site_id' => ['Hibás formátum.']]);
});

it('answers not_found for a user of somebody else\'s site', function (string $method, string $path, array $body): void {
    [$own, $foreign] = [Site::factory()->create(), Site::factory()->create()];
    $stranger = User::factory()->create();
    $stranger->sites()->attach($foreign->id);

    callApi($method, str_replace('{user}', $stranger->id, $path), supervisorOf($own), $body)->assertNotFound();
})->with([
    'show' => ['GET', 'users/{user}', []],
    'update' => ['PATCH', 'users/{user}', ['name' => 'X']],
    'pin' => ['PUT', 'users/{user}/pin', ['pin' => '123456']],
    'delete' => ['DELETE', 'users/{user}', []],
]);

it('updates a supervisor\'s credentials', function (): void {
    $site = Site::factory()->create();
    $other = supervisorOf($site);

    $response = callApi('PATCH', "users/{$other->id}", supervisorOf($site), ['name' => 'Nagy Anna', 'email' => 'anna.nagy@example.com', 'password' => 'uj-titkos-jelszo'])
        ->assertOk();

    expect($response)->toMatchSchema('api/user.response');
    expect($response->json('user.name'))->toBe('Nagy Anna');
    postJson('api/waypost/v1/auth/login', ['email' => 'anna.nagy@example.com', 'password' => 'uj-titkos-jelszo', 'device_name' => 'Pixel 8'])->assertOk();
});

it('ends the other sessions of a supervisor whose password changes', function (bool $self): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $stolen = supervisorToken($supervisor);
    $own = supervisorToken($supervisor);
    $colleague = supervisorOf($site);
    $colleagues = supervisorToken($colleague);
    $caller = ['Authorization' => 'Bearer ' . ($self ? $own : $colleagues)];
    $works = fn (string $token): bool => requestApi('GET', 'sites', ['Authorization' => "Bearer {$token}"])->isOk();

    // Another field leaves the sessions alone.
    requestApi('PATCH', "users/{$supervisor->id}", $caller, ['name' => 'Nagy Anna'])->assertOk();
    expect($works($stolen))->toBeTrue();

    requestApi('PATCH', "users/{$supervisor->id}", $caller, ['password' => 'uj-titkos-jelszo'])->assertOk();

    expect($works($stolen))->toBeFalse()
        ->and($works($own))->toBe($self)
        ->and($works($colleagues))->toBeTrue();
})->with(['by themselves' => true, 'by a colleague' => false]);

it('gives a guard no e-mail address or password', function (string $field, string $value, string $message): void {
    $site = Site::factory()->create();
    $guard = User::factory()->create();
    $guard->sites()->attach($site->id);

    $response = callApi('PATCH', "users/{$guard->id}", supervisorOf($site), [$field => $value])->assertUnprocessable();

    expect($response->json('error.details'))->toBe([$field => [$message]]);
})->with([
    ['email', 'peter@example.com', 'Őrnek nincs e-mail-címe.'],
    ['password', 'titkos-jelszo', 'Őrnek nincs jelszava.'],
]);

it('deactivates a user, whose tokens stop working', function (): void {
    $site = Site::factory()->create();
    $other = supervisorOf($site);
    $token = supervisorToken($other);

    $response = callApi('PATCH', "users/{$other->id}", supervisorOf($site), ['active' => false])->assertOk();

    expect($response->json('user.active'))->toBeFalse();
    postJson('api/waypost/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
});

it('does not let the caller deactivate themselves', function (): void {
    $site = Site::factory()->create();
    supervisorOf($site);
    $supervisor = supervisorOf($site);

    $response = callApi('PATCH', "users/{$supervisor->id}", $supervisor, ['active' => false])->assertForbidden();

    expect($response->json('error.message'))->toBe('Saját magadat nem inaktiválhatod.');
});

it('changes or deletes only a user whose every site the caller manages', function (string $method, string $path, array $body): void {
    [$shared, $theirs] = [Site::factory()->create(), Site::factory()->create()];
    $caller = supervisorOf($shared);
    // A colleague who also manages a site the caller does not: their account must not be taken over.
    $colleague = supervisorOf($shared, $theirs);
    $colleague->update(['email' => 'anna@example.com', 'pin_hash' => null]);

    $response = callApi($method, str_replace('{user}', $colleague->id, $path), $caller, $body)->assertForbidden();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.message'))->toBe('A felhasználó olyan telephelyhez is tartozik, amelyet nem kezelsz.');
    $colleague->refresh();
    expect($colleague->email)->toBe('anna@example.com');
    expect($colleague->active)->toBeTrue();
    expect($colleague->pin_hash)->toBeNull();
    expect($colleague->trashed())->toBeFalse();
    callApi('GET', "users/{$colleague->id}", $caller)->assertOk();
})->with([
    'e-mail address' => ['PATCH', 'users/{user}', ['email' => 'attacker@example.com']],
    'password' => ['PATCH', 'users/{user}', ['password' => 'attacker-password']],
    'deactivate' => ['PATCH', 'users/{user}', ['active' => false]],
    'PIN' => ['PUT', 'users/{user}/pin', []],
    'delete' => ['DELETE', 'users/{user}', []],
]);

it('does not let the site phone change the credentials of a supervisor who has other sites, not even their own', function (string $method, string $path, array $body): void {
    [$phoneSite, $otherSite] = [Site::factory()->create(), Site::factory()->create()];
    $supervisor = supervisorOf($phoneSite, $otherSite);
    $supervisor->update(['email' => 'anna@example.com', 'pin_hash' => null]);
    $password = $supervisor->password;

    $response = callApi($method, str_replace('{user}', $supervisor->id, $path), $supervisor, $body, phone: Device::factory()->for($phoneSite)->create())
        ->assertForbidden();

    expect($response)->toMatchSchema('api/error');
    expect($response->json('error.message'))->toBe('A felhasználó olyan telephelyhez is tartozik, amelyet nem kezelsz.');
    $supervisor->refresh();
    expect($supervisor->email)->toBe('anna@example.com');
    expect($supervisor->password)->toBe($password);
    expect($supervisor->pin_hash)->toBeNull();
    // With their own token they still can.
    callApi($method, str_replace('{user}', $supervisor->id, $path), $supervisor, $body)->assertOk();
})->with([
    'e-mail address' => ['PATCH', 'users/{user}', ['email' => 'attacker@example.com']],
    'password' => ['PATCH', 'users/{user}', ['password' => 'attacker-password']],
    'PIN' => ['PUT', 'users/{user}/pin', []],
]);

it('lets the site phone change the credentials of a supervisor whose only site it is', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);

    callApi('PATCH', "users/{$supervisor->id}", $supervisor, ['email' => 'anna.nagy@example.com', 'password' => 'uj-titkos-jelszo'], phone: Device::factory()->for($site)->create())
        ->assertOk();

    postJson('api/waypost/v1/auth/login', ['email' => 'anna.nagy@example.com', 'password' => 'uj-titkos-jelszo', 'device_name' => 'Pixel 8'])->assertOk();
});

it('changes the PIN to a generated one, unique on all of the user\'s sites', function (): void {
    [$own, $theirs] = [Site::factory()->create(), Site::factory()->create()];
    $guard = User::factory()->withPin('111111')->create();
    $guard->sites()->attach([$own->id, $theirs->id]);

    $response = callApi('PUT', "users/{$guard->id}/pin", supervisorOf($own, $theirs))->assertOk();

    expect($response)->toMatchSchema('api/user.response');
    expect(hasPin($guard, $response->json('pin')))->toBeTrue();
    expect(hasPin($guard, '111111'))->toBeFalse();
});

it('changes the PIN to the given one, unless another user of the user\'s sites has it', function (): void {
    [$own, $theirs] = [Site::factory()->create(), Site::factory()->create()];
    $guard = User::factory()->withPin('111111')->create();
    $guard->sites()->attach([$own->id, $theirs->id]);
    User::factory()->withPin('222222')->create()->sites()->attach($theirs->id);
    $supervisor = supervisorOf($own, $theirs);

    callApi('PUT', "users/{$guard->id}/pin", $supervisor, ['pin' => '222222'])->assertConflict();
    expect(hasPin($guard, '111111'))->toBeTrue();

    $response = callApi('PUT', "users/{$guard->id}/pin", $supervisor, ['pin' => '333333'])->assertOk();
    expect($response->json())->not->toHaveKey('pin');
    expect(hasPin($guard, '333333'))->toBeTrue();
});

it('locks the user\'s sites while it gives a PIN, until the user is saved', function (Closure $request): void {
    [$a, $b] = [Site::factory()->create(), Site::factory()->create()];
    $guard = User::factory()->withPin('111111')->create();
    $guard->sites()->attach($a->id);
    $outside = DB::transactionLevel();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = ['sql' => $query->sql, 'level' => $query->connection->transactionLevel()];
    });

    $request($guard, supervisorOf($a, $b), $a, $b)->assertSuccessful();

    $sites = preg_quote(Site::query()->getModel()->getTable(), '/');
    $users = preg_quote($guard->getTable(), '/');
    $lock = array_key_first(array_filter($queries, fn (array $query): bool => preg_match("/from [`\"]{$sites}[`\"].* for (no key )?update$/i", $query['sql']) === 1));
    $write = array_key_last(array_filter($queries, fn (array $query): bool => preg_match("/^(insert into|update) [`\"]{$users}[`\"]/i", $query['sql']) === 1));
    expect($lock)->toBeInt()->and($write)->toBeInt()->and((int) $lock)->toBeLessThan((int) $write);
    $between = array_slice($queries, (int) $lock, (int) $write - (int) $lock + 1);
    expect(array_filter($between, fn (array $query): bool => $query['level'] <= $outside))->toBe([]);
})->with([
    'a new user' => [fn (User $guard, User $supervisor, Site $a, Site $b) => callApi('POST', 'users', $supervisor, ['name' => 'Kiss Péter', 'role' => 'guard', 'site_ids' => [$a->id, $b->id]])],
    'a new PIN' => [fn (User $guard, User $supervisor) => callApi('PUT', "users/{$guard->id}/pin", $supervisor)],
    'a new site' => [fn (User $guard, User $supervisor, Site $a, Site $b) => callApi('POST', "sites/{$b->id}/users", $supervisor, ['user_id' => $guard->id])],
]);

it('limits the requests that give a PIN more strictly, per caller', function (): void {
    config(['waypost.rate_limits.pin' => 2]);
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $guard = fn (string $name): array => ['name' => $name, 'role' => 'guard', 'site_ids' => [$site->id]];

    $created = callApi('POST', 'users', $supervisor, $guard('Kiss Péter'))->assertCreated();
    callApi('PUT', "users/{$created->json('user.id')}/pin", $supervisor)->assertOk();
    $response = callApi('POST', 'users', $supervisor, $guard('Nagy Ádám'))->assertStatus(429)->assertHeader('Retry-After');

    expect($response->json('error.code'))->toBe('rate_limited');
    callApi('PATCH', "users/{$created->json('user.id')}", $supervisor, ['name' => 'Kiss Pál'])->assertOk();
    callApi('POST', 'users', supervisorOf($site), $guard('Nagy Ádám'))->assertCreated();
});

it('deletes a user with their subscriptions and tokens', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    $other = supervisorOf($site);
    supervisorToken($other);
    $subscription = ReportSubscription::factory()->for($site)->for($other)->create();

    $response = callApi('DELETE', "users/{$other->id}", $supervisor)->assertOk();

    expect($response)->toMatchSchema('api/deleted.response');
    expect($other->refresh()->trashed())->toBeTrue();
    expect($subscription->refresh()->trashed())->toBeTrue();
    expect(PersonalAccessToken::query()->whereMorphedTo('tokenable', $other)->count())->toBe(0);
    callApi('GET', "users/{$other->id}", $supervisor)->assertNotFound();
});

it('does not let the caller delete themselves', function (): void {
    $site = Site::factory()->create();
    $supervisor = supervisorOf($site);
    supervisorOf($site);

    callApi('DELETE', "users/{$supervisor->id}", $supervisor)->assertForbidden()->assertJsonPath('error.message', 'Saját magadat nem törölheted.');
});

it('replays the generated PIN for the same Idempotency-Key, stored encrypted', function (): void {
    $site = Site::factory()->create();
    $headers = [
        'HTTP_AUTHORIZATION' => 'Bearer ' . supervisorToken(supervisorOf($site)),
        'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid(),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];
    $body = json_encode(['name' => 'Kiss Péter', 'role' => 'guard', 'site_ids' => [$site->id]], JSON_THROW_ON_ERROR);

    $first = call('POST', 'api/waypost/v1/users', server: $headers, content: $body)->assertCreated();
    $replay = call('POST', 'api/waypost/v1/users', server: $headers, content: $body)->assertCreated();

    $replay->assertHeader(HandleIdempotencyKey::REPLAYED_HEADER, 'true');
    expect($replay->json('pin'))->toBe($first->json('pin'));
    expect(User::query()->where('name', 'Kiss Péter')->count())->toBe(1);
    $stored = IdempotencyKey::query()->toBase()->value('response_body');
    assert(is_string($stored));
    expect(json_decode($stored))->toBeNull();
    expect(json_decode(Crypt::decryptString($stored), true))->toMatchArray(['pin' => $first->json('pin')]);
});
