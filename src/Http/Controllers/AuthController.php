<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Waypost\Auth\Caller;
use Waypost\Enums\ErrorCode;
use Waypost\Enums\Role;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Http\Middleware\Authenticate;
use Waypost\Models\User;

/** POST /auth/login and POST /auth/logout (spec: Azonosítás / Supervisor fiók). */
final class AuthController
{
    /**
     * A non-expiring supervisor token (api/auth-login.response). Guards have no password login; an unknown
     * address, a guard and a wrong password get the same answer.
     *
     * Also in the time it takes: without a password to check, the password is hashed instead, which costs as much as
     * a check with the same hasher. Otherwise the quicker answer would tell which addresses have an account. A hash
     * made with older settings (e.g. fewer bcrypt rounds) is made again at the login, so that the stored hashes cost
     * what a new one does (FR-015).
     *
     * @return array{token: string, user: array{id: string, name: string, email: string|null, role: string}}
     */
    public function login(Request $request): array
    {
        $email = Str::lower($request->string('email')->toString());
        $password = $request->string('password')->toString();
        $user = User::query()->where('email', $email)->where('role', Role::Supervisor)->first();
        if ($user?->password === null) {
            Hash::make($password);

            throw new ApiException(ErrorCode::Unauthenticated, 'Hibás e-mail-cím vagy jelszó.');
        }
        if (!Hash::check($password, $user->password)) {
            throw new ApiException(ErrorCode::Unauthenticated, 'Hibás e-mail-cím vagy jelszó.');
        }
        if (!$user->active) {
            throw new ApiException(ErrorCode::Unauthenticated, 'Ez a fiók inaktív, nem lehet vele belépni.');
        }

        if (Hash::needsRehash($user->password)) {
            // The model's hashed cast hashes the plain password with the current settings.
            $user->forceFill(['password' => $password])->save();
        }

        $token = $user->createToken($request->string('device_name')->toString(), [Authenticate::SUPERVISOR]);

        return [
            'token' => $token->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role->value],
        ];
    }

    /**
     * Deletes the token of the request (api/auth-logout.response).
     *
     * @return array{}
     */
    public function logout(Caller $caller): array
    {
        $caller->token->delete();

        return [];
    }
}
