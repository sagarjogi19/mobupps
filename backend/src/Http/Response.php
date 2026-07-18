<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Simple JSON response value object. Rendering (sending headers/body) is a
 * separate step so tests can assert on responses without output buffering.
 */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $payload = [],
        public readonly array $headers = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function ok(array $data, int $status = 200, array $meta = []): self
    {
        $payload = ['success' => true, 'data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        return new self($status, $payload);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    /** @param array<string, mixed> $details */
    public static function error(int $status, string $code, string $message, array $details = []): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        return new self($status, ['success' => false, 'error' => $error]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if ($this->status !== 204) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
}
