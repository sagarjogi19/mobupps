<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\App;
use App\Config;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests: boots the full application against an
 * in-memory SQLite database and provides helpers to make requests exactly
 * as an HTTP client would (headers, JSON bodies, Bearer tokens).
 */
abstract class ApiTestCase extends TestCase
{
    protected App $app;

    protected function setUp(): void
    {
        $config = new Config([
            'JWT_SECRET' => 'integration-test-secret',
            'JWT_TTL' => 3600,
        ]);
        $pdo = Database::connect(':memory:');
        $this->app = new App($config, $pdo);
    }

    protected function request(
        string $method,
        string $path,
        array $body = [],
        ?string $token = null,
        array $query = [],
    ): Response {
        $headers = ['content-type' => 'application/json'];
        if ($token !== null) {
            $headers['authorization'] = "Bearer $token";
        }
        return $this->app->handle(new Request(strtoupper($method), $path, $query, $body, $headers));
    }

    protected function login(): string
    {
        $response = $this->request('POST', '/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'admin123',
        ]);
        $this->assertSame(200, $response->status, 'seeded admin login should succeed');
        return $response->payload['data']['token'];
    }
}
