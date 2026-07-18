<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\Jwt;
use App\Auth\JwtException;
use App\Http\Request;
use App\Http\Response;

/**
 * Verifies the Bearer JWT and attaches the authenticated user id to the
 * request. All /api/products routes are behind this middleware.
 */
final class AuthMiddleware
{
    public function __construct(private readonly Jwt $jwt)
    {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return Response::error(401, 'UNAUTHORIZED', 'Missing Bearer token. Log in via POST /api/auth/login.');
        }

        try {
            $claims = $this->jwt->decode($token);
        } catch (JwtException $e) {
            return Response::error(401, 'UNAUTHORIZED', $e->getMessage());
        }

        return $next($request->withAttribute('userId', (int) ($claims['sub'] ?? 0)));
    }
}
