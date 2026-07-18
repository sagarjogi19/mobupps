<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Jwt;
use App\Auth\JwtException;
use PHPUnit\Framework\TestCase;

final class JwtTest extends TestCase
{
    private Jwt $jwt;

    protected function setUp(): void
    {
        $this->jwt = new Jwt('test-secret');
    }

    public function testEncodeDecodeRoundTrip(): void
    {
        $token = $this->jwt->encode(['sub' => 42, 'email' => 'a@b.c'], 60);
        $claims = $this->jwt->decode($token);

        $this->assertSame(42, $claims['sub']);
        $this->assertSame('a@b.c', $claims['email']);
        $this->assertArrayHasKey('iat', $claims);
        $this->assertGreaterThan(time(), $claims['exp']);
    }

    public function testTokenHasThreeSegments(): void
    {
        $token = $this->jwt->encode(['sub' => 1], 60);
        $this->assertCount(3, explode('.', $token));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = $this->jwt->encode(['sub' => 1], -10);

        $this->expectException(JwtException::class);
        $this->expectExceptionMessage('expired');
        $this->jwt->decode($token);
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $token = $this->jwt->encode(['sub' => 1], 60);
        [$header, $body, $sig] = explode('.', $token);

        $forgedBody = rtrim(strtr(base64_encode(json_encode(
            ['sub' => 999, 'iat' => time(), 'exp' => time() + 60]
        )), '+/', '-_'), '=');

        $this->expectException(JwtException::class);
        $this->jwt->decode("$header.$forgedBody.$sig");
    }

    public function testTokenSignedWithDifferentSecretIsRejected(): void
    {
        $other = new Jwt('another-secret');
        $token = $other->encode(['sub' => 1], 60);

        $this->expectException(JwtException::class);
        $this->jwt->decode($token);
    }

    public function testMalformedTokenIsRejected(): void
    {
        $this->expectException(JwtException::class);
        $this->jwt->decode('not-a-jwt');
    }
}
