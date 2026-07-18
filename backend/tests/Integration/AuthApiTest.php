<?php

declare(strict_types=1);

namespace Tests\Integration;

final class AuthApiTest extends ApiTestCase
{
    public function testLoginWithValidCredentialsReturnsToken(): void
    {
        $response = $this->request('POST', '/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'admin123',
        ]);

        $this->assertSame(200, $response->status);
        $data = $response->payload['data'];
        $this->assertNotEmpty($data['token']);
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertSame('admin@example.com', $data['user']['email']);
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $response = $this->request('POST', '/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong',
        ]);

        $this->assertSame(401, $response->status);
        $this->assertSame('INVALID_CREDENTIALS', $response->payload['error']['code']);
    }

    public function testLoginWithUnknownEmailReturnsSameErrorAsWrongPassword(): void
    {
        $response = $this->request('POST', '/api/auth/login', [
            'email' => 'ghost@example.com',
            'password' => 'whatever',
        ]);

        $this->assertSame(401, $response->status);
        $this->assertSame('INVALID_CREDENTIALS', $response->payload['error']['code']);
    }

    public function testLoginWithMissingFieldsIsValidationError(): void
    {
        $response = $this->request('POST', '/api/auth/login', ['email' => 'admin@example.com']);
        $this->assertSame(422, $response->status);
    }

    public function testMeReturnsCurrentUser(): void
    {
        $token = $this->login();
        $response = $this->request('GET', '/api/auth/me', token: $token);

        $this->assertSame(200, $response->status);
        $this->assertSame('admin@example.com', $response->payload['data']['user']['email']);
    }

    public function testMeWithoutTokenIsUnauthorized(): void
    {
        $response = $this->request('GET', '/api/auth/me');
        $this->assertSame(401, $response->status);
    }
}
