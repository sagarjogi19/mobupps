<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Jwt;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UserRepository;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Jwt $jwt,
        private readonly int $tokenTtl,
    ) {
    }

    /** POST /api/auth/login  {email, password} */
    public function login(Request $request): Response
    {
        $email = $request->body['email'] ?? null;
        $password = $request->body['password'] ?? null;

        if (!is_string($email) || !is_string($password) || $email === '' || $password === '') {
            return Response::error(422, 'VALIDATION_ERROR', 'Validation failed.', [
                'email' => is_string($email) && $email !== '' ? null : 'Email is required.',
                'password' => is_string($password) && $password !== '' ? null : 'Password is required.',
            ]);
        }

        $user = $this->users->findByEmail($email);
        // Same error for unknown email and wrong password: don't leak which
        // accounts exist.
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            return Response::error(401, 'INVALID_CREDENTIALS', 'Invalid email or password.');
        }

        $token = $this->jwt->encode(['sub' => $user['id'], 'email' => $user['email']], $this->tokenTtl);

        return Response::ok([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->tokenTtl,
            'user' => ['id' => (int) $user['id'], 'email' => $user['email'], 'name' => $user['name']],
        ]);
    }

    /** GET /api/auth/me — lets the client verify a stored token on load. */
    public function me(Request $request): Response
    {
        $user = $this->users->findById((int) $request->attributes['userId']);
        if ($user === null) {
            return Response::error(401, 'UNAUTHORIZED', 'User no longer exists.');
        }
        return Response::ok(['user' => ['id' => (int) $user['id'], 'email' => $user['email'], 'name' => $user['name']]]);
    }
}
