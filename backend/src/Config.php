<?php

declare(strict_types=1);

namespace App;

/**
 * Central configuration, sourced from environment variables with sane
 * development defaults. In production every value should be set explicitly.
 */
final class Config
{
    /** @param array<string, mixed> $overrides */
    public function __construct(private array $overrides = [])
    {
    }

    public function jwtSecret(): string
    {
        return (string) $this->get('JWT_SECRET', 'dev-only-change-me-in-production');
    }

    public function jwtTtl(): int
    {
        return (int) $this->get('JWT_TTL', 3600); // seconds
    }

    public function dbPath(): string
    {
        return (string) $this->get('DB_PATH', dirname(__DIR__) . '/database/app.sqlite');
    }

    public function corsAllowOrigin(): string
    {
        return (string) $this->get('CORS_ALLOW_ORIGIN', '*');
    }

    private function get(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->overrides)) {
            return $this->overrides[$key];
        }
        $env = getenv($key);
        return $env !== false ? $env : $default;
    }
}
