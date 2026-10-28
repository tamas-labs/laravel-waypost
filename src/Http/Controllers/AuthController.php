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
     * @return array{token: string, user: array{id: string, name: string, email: string|null, role: string}}
     */
    public function login(Request $request): array
    {
        $email = Str::lower($request->string('email')->toString());
        $user = User::query()->where('email', $email)->where('role', Role::Supervisor)->first();
        if ($user?->password === null || !Hash::check($request->string('password')->toString(), $user->password)) {
            throw new ApiException(ErrorCode::Unauthenticated, 'Hibás e-mail-cím vagy jelszó.');
        }
        if (!$user->active) {
            throw new ApiException(ErrorCode::Unauthenticated, 'Ez a fiók inaktív, nem lehet vele belépni.');
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
