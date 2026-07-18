<?php

declare(strict_types=1);

/**
 * Front controller.
 *
 * - /api/*  -> JSON API
 * - anything else -> static files from ../../frontend (the web client),
 *   so a single `php -S localhost:8000 backend/public/index.php` serves
 *   both the API and the UI. In production the static part would be
 *   handled by nginx/Apache instead.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\App;
use App\Config;
use App\Http\Request;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/api/') || $path === '/api') {
    $app = new App(new Config());
    $app->handle(Request::fromGlobals())->send();
    return;
}

// The OpenAPI spec lives at the repo root; expose it for Swagger UI (/docs.html).
if ($path === '/openapi.yaml') {
    header('Content-Type: application/yaml; charset=utf-8');
    readfile(__DIR__ . '/../../openapi.yaml');
    return;
}

// ---- Static frontend ----
$frontendDir = realpath(__DIR__ . '/../../frontend');
$requested = $path === '/' ? '/index.html' : $path;
$file = $frontendDir !== false ? realpath($frontendDir . $requested) : false;

// realpath() also protects against ../ traversal outside the frontend dir.
if ($file !== false && is_file($file) && str_starts_with($file, $frontendDir)) {
    $mimes = [
        'html' => 'text/html; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    readfile($file);
    return;
}

http_response_code(404);
header('Content-Type: text/plain');
echo 'Not found';
