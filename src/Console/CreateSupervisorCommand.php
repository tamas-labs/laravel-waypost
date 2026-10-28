<?php

declare(strict_types=1);

namespace Waypost\Console;

use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\Console\Attribute\AsCommand;
use Waypost\Enums\Role;
use Waypost\Models\User;
use Waypost\Support\Table;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates a supervisor account (spec: Supervisor fiók; there is no registration). The values come from the
 * options or, interactively, from prompts. No PIN: it must be unique within a site, and a new supervisor has no
 * site yet.
 */
#[AsCommand(name: 'waypost:create-supervisor')]
final class CreateSupervisorCommand extends Command
{
    protected $signature = 'waypost:create-supervisor
        {--name= : A supervisor neve}
        {--email= : E-mail-cím, ezzel lép be}
        {--password= : Jelszó, legalább 8 karakter (ha hiányzik, a parancs bekéri)}';

    protected $description = 'Supervisor fiók létrehozása';

    private const array MESSAGES = [
        'name.required' => 'A név megadása kötelező.',
        'name.max' => 'A név legfeljebb 255 karakter lehet.',
        'email.required' => 'Az e-mail-cím megadása kötelező.',
        'email.email' => 'Ez nem érvényes e-mail-cím.',
        'email.max' => 'Az e-mail-cím legfeljebb 255 karakter lehet.',
        'email.unique' => 'Ezzel az e-mail-címmel már van felhasználó.',
        'password.required' => 'A jelszó megadása kötelező.',
        'password.min' => 'A jelszó legalább 8 karakter legyen.',
        'password.max' => 'A jelszó legfeljebb 255 karakter lehet.',
    ];

    public function handle(): int
    {
        $values = [
            'name' => $this->value('name', fn () => text('Név', required: 'A név megadása kötelező.', validate: fn (string $value) => $this->firstError('name', $value))),
            'email' => $this->value('email', fn () => text('E-mail-cím', required: 'Az e-mail-cím megadása kötelező.', validate: fn (string $value) => $this->firstError('email', $value))),
            'password' => $this->value('password', fn () => $this->askPassword()),
        ];

        $validator = Validator::make($values, $this->rules(), self::MESSAGES);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        try {
            $user = User::query()->create([...$values, 'role' => Role::Supervisor, 'active' => true]);
        } catch (UniqueConstraintViolationException) {
            // Created concurrently since the validation.
            $this->components->error(self::MESSAGES['email.unique']);

            return self::FAILURE;
        }

        $this->components->info("Supervisor létrehozva: {$user->name} <{$user->email}>. Az appban ezzel az e-mail-címmel és a jelszóval léphet be.");

        return self::SUCCESS;
    }

    /**
     * The option, or the answer to the prompt if the option is missing and the command is interactive.
     *
     * @param  callable(): string  $prompt
     */
    private function value(string $field, callable $prompt): string
    {
        $value = $this->option($field);
        if (!is_string($value) && $this->input->isInteractive()) {
            $value = $prompt();
        }

        return $this->normalize($field, is_string($value) ? $value : '');
    }

    /** Trimmed, as in an HTTP request; the e-mail address lowercase, as User stores it. */
    private function normalize(string $field, string $value): string
    {
        return match ($field) {
            'password' => $value,
            'email' => Str::lower(trim($value)),
            default => trim($value),
        };
    }

    private function askPassword(): string
    {
        $password = password('Jelszó', required: 'A jelszó megadása kötelező.', validate: fn (string $value) => $this->firstError('password', $value));
        password('Jelszó még egyszer', validate: fn (string $value) => $value === $password ? null : 'A két jelszó nem egyezik.');

        return $password;
    }

    /** The first validation error of one field, for the prompt; null if the value is valid. */
    private function firstError(string $field, string $value): ?string
    {
        $value = $this->normalize($field, $value);

        return Validator::make([$field => $value], [$field => $this->rules()[$field]], self::MESSAGES)->errors()->first($field) ?: null;
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'max:255'],
            // Deleted users do not keep their address.
            'email' => ['required', 'email', 'max:255', Rule::unique(Table::name('users'), 'email')->whereNull('deleted_at')],
            'password' => ['required', 'min:8', 'max:255'],
        ];
    }
}
