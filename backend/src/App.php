<?php

declare(strict_types=1);

namespace App;

use App\Auth\Jwt;
use App\Controllers\AuthController;
use App\Controllers\ProductController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Middleware\AuthMiddleware;
use App\Repositories\ProductRepository;
use App\Repositories\UserRepository;
use App\Validation\ProductValidator;
use PDO;

/**
 * Composition root: wires dependencies and defines all routes.
 * Accepting a PDO instance makes integration tests trivial
 * (they pass an in-memory SQLite connection).
 */
final class App
{
    private Router $router;

    public function __construct(private readonly Config $config, ?PDO $pdo = null)
    {
        $pdo ??= Database::connect($config->dbPath());
        Database::migrate($pdo);
        Database::seed($pdo);

        $jwt = new Jwt($config->jwtSecret());
        $auth = new AuthMiddleware($jwt);

        $authController = new AuthController(new UserRepository($pdo), $jwt, $config->jwtTtl());
        $productController = new ProductController(new ProductRepository($pdo), new ProductValidator());

        $this->router = new Router();

        $this->router->add('GET', '/api/health', fn () => Response::ok(['status' => 'ok', 'time' => date('c')]));

        $this->router->add('POST', '/api/auth/login', [$authController, 'login']);
        $this->router->add('GET', '/api/auth/me', [$authController, 'me'], [$auth]);

        $this->router->add('GET', '/api/products', [$productController, 'index'], [$auth]);
        $this->router->add('GET', '/api/products/categories', [$productController, 'categories'], [$auth]);
        $this->router->add('GET', '/api/products/{id}', [$productController, 'show'], [$auth]);
        $this->router->add('POST', '/api/products', [$productController, 'create'], [$auth]);
        $this->router->add('PUT', '/api/products/{id}', [$productController, 'update'], [$auth]);
        $this->router->add('PATCH', '/api/products/{id}', [$productController, 'update'], [$auth]);
        $this->router->add('DELETE', '/api/products/{id}', [$productController, 'delete'], [$auth]);
    }

    public function handle(Request $request): Response
    {
        if ($request->method === 'OPTIONS') {
            return new Response(204, [], $this->corsHeaders());
        }

        try {
            $response = $this->router->dispatch($request);
        } catch (\Throwable $e) {
            error_log('[app] ' . $e::class . ': ' . $e->getMessage());
            $response = Response::error(500, 'INTERNAL_ERROR', 'An unexpected error occurred.');
        }

        return new Response($response->status, $response->payload, $response->headers + $this->corsHeaders());
    }

    /** @return array<string, string> */
    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => $this->config->corsAllowOrigin(),
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            'Access-Control-Max-Age' => '86400',
        ];
    }
}
