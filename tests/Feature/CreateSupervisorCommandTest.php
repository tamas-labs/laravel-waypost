<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Waypost\Enums\Role;
use Waypost\Models\User;

pest()->use(RefreshDatabase::class);

/**
 * The command with the options, without prompts.
 *
 * @param  array<string, string>  $options
 */
function createSupervisor(array $options): PendingCommand
{
    $parameters = ['--no-interaction' => true];
    foreach ($options as $name => $value) {
        $parameters["--{$name}"] = $value;
    }

    return artisanCommand('waypost:create-supervisor', $parameters);
}

it('creates a supervisor from the options', function (): void {
    createSupervisor(['name' => 'Demó Supervisor', 'email' => 'supervisor@example.com', 'password' => 'titkos-jelszo'])
        ->expectsOutputToContain('Supervisor létrehozva: Demó Supervisor <supervisor@example.com>')
        ->assertSuccessful();

    $user = User::query()->sole();

    expect($user->role)->toBe(Role::Supervisor)
        ->and($user->name)->toBe('Demó Supervisor')
        ->and($user->email)->toBe('supervisor@example.com')
        ->and($user->active)->toBeTrue()
        ->and(Hash::check('titkos-jelszo', (string) $user->password))->toBeTrue()
        ->and($user->pin_hash)->toBeNull()
        ->and($user->sites()->count())->toBe(0);
});

it('asks for the missing values and the password twice', function (): void {
    artisanCommand('waypost:create-supervisor')
        ->expectsQuestion('Név', 'Kovács Éva')
        ->expectsQuestion('E-mail-cím', 'eva@example.com')
        ->expectsQuestion('Jelszó', 'titkos-jelszo')
        ->expectsQuestion('Jelszó még egyszer', 'titkos-jelszo')
        ->assertSuccessful();

    expect(User::query()->sole()->email)->toBe('eva@example.com');
});

it('asks only for what the options do not give', function (): void {
    artisanCommand('waypost:create-supervisor', ['--name' => 'Kovács Éva', '--email' => 'eva@example.com'])
        ->expectsQuestion('Jelszó', 'titkos-jelszo')
        ->expectsQuestion('Jelszó még egyszer', 'titkos-jelszo')
        ->assertSuccessful();

    expect(User::query()->sole()->name)->toBe('Kovács Éva');
});

it('stores the e-mail address trimmed and lowercase', function (): void {
    createSupervisor(['name' => '  Kovács Éva ', 'email' => ' Eva@Example.COM ', 'password' => 'titkos-jelszo'])->assertSuccessful();

    $user = User::query()->sole();

    expect($user->name)->toBe('Kovács Éva')
        ->and($user->email)->toBe('eva@example.com');
});

it('fails with the same e-mail address a second time, in any case', function (): void {
    createSupervisor(['name' => 'Első', 'email' => 'supervisor@example.com', 'password' => 'titkos-jelszo'])->assertSuccessful();

    createSupervisor(['name' => 'Második', 'email' => 'Supervisor@Example.com', 'password' => 'masik-jelszo'])
        ->expectsOutputToContain('Ezzel az e-mail-címmel már van felhasználó.')
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});

it('allows the address of a deleted user again', function (): void {
    User::factory()->supervisor()->create(['email' => 'supervisor@example.com'])->delete();

    createSupervisor(['name' => 'Új', 'email' => 'supervisor@example.com', 'password' => 'titkos-jelszo'])->assertSuccessful();

    expect(User::query()->sole()->name)->toBe('Új');
});

it('reports every invalid value in Hungarian', function (array $options, string $message): void {
    createSupervisor([...['name' => 'Kovács Éva', 'email' => 'eva@example.com', 'password' => 'titkos-jelszo'], ...$options])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => '   '], 'A név megadása kötelező.'],
    'long name' => [['name' => str_repeat('a', 256)], 'A név legfeljebb 255 karakter lehet.'],
    'no e-mail' => [['email' => ''], 'Az e-mail-cím megadása kötelező.'],
    'bad e-mail' => [['email' => 'nem-email'], 'Ez nem érvényes e-mail-cím.'],
    'short password' => [['password' => '1234567'], 'A jelszó legalább 8 karakter legyen.'],
]);

it('reports a missing option when it cannot ask', function (): void {
    artisanCommand('waypost:create-supervisor', ['--name' => 'Kovács Éva', '--no-interaction' => true])
        ->expectsOutputToContain('Az e-mail-cím megadása kötelező.')
        ->expectsOutputToContain('A jelszó megadása kötelező.')
        ->assertFailed();
});

// Interactively the prompt asks again; under tests Laravel stops at the first invalid answer instead.
it('validates every answer as it is given', function (string $question, string $answer, string $message): void {
    $command = artisanCommand('waypost:create-supervisor', ['--name' => 'Kovács Éva']);
    if ($question !== 'E-mail-cím') {
        $command->expectsQuestion('E-mail-cím', 'eva@example.com');
    }
    if ($question === 'Jelszó még egyszer') {
        $command->expectsQuestion('Jelszó', 'titkos-jelszo');
    }

    $command->expectsQuestion($question, $answer)
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'e-mail' => ['E-mail-cím', 'nem-email', 'Ez nem érvényes e-mail-cím.'],
    'password' => ['Jelszó', 'rovid', 'A jelszó legalább 8 karakter legyen.'],
    'confirmation' => ['Jelszó még egyszer', 'masik-jelszo', 'A két jelszó nem egyezik.'],
]);
