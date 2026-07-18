<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Minimal, dependency-free JWT (HS256) implementation.
 *
 * Only the subset needed here is implemented: HS256 signing, expiry (exp),
 * issued-at (iat) and constant-time signature verification. In a larger
 * project a vetted library (firebase/php-jwt) would be preferred; this is
 * hand-rolled to honour the "no heavy dependencies" constraint and to keep
 * the crypto auditable in ~60 lines.
 */
final class Jwt
{
    public function __construct(private readonly string $secret)
    {
    }

    /** @param array<string, mixed> $claims */
    public function encode(array $claims, int $ttlSeconds): string
    {
        $now = time();
        $payload = array_merge($claims, ['iat' => $now, 'exp' => $now + $ttlSeconds]);

        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $body = self::b64(json_encode($payload));
        $signature = self::b64(hash_hmac('sha256', "$header.$body", $this->secret, true));

        return "$header.$body.$signature";
    }

    /**
     * @return array<string, mixed> the verified claims
     * @throws JwtException when the token is malformed, tampered or expired
     */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new JwtException('Malformed token.');
        }
        [$header, $body, $signature] = $parts;

        $expected = self::b64(hash_hmac('sha256', "$header.$body", $this->secret, true));
        if (!hash_equals($expected, $signature)) {
            throw new JwtException('Invalid token signature.');
        }

        $claims = json_decode(self::unb64($body), true);
        if (!is_array($claims)) {
            throw new JwtException('Invalid token payload.');
        }
        if (!isset($claims['exp']) || time() >= (int) $claims['exp']) {
            throw new JwtException('Token has expired.');
        }

        return $claims;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
