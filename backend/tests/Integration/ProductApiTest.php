<?php

declare(strict_types=1);

namespace Tests\Integration;

final class ProductApiTest extends ApiTestCase
{
    private const VALID_PRODUCT = [
        'name' => 'Test Gadget',
        'sku' => 'TST-9999',
        'description' => 'A gadget used in tests.',
        'price' => 12.34,
        'category' => 'Testing',
        'stock' => 7,
    ];

    // ---- authentication guard ----

    public function testAllProductRoutesRequireAuth(): void
    {
        $this->assertSame(401, $this->request('GET', '/api/products')->status);
        $this->assertSame(401, $this->request('GET', '/api/products/1')->status);
        $this->assertSame(401, $this->request('POST', '/api/products', self::VALID_PRODUCT)->status);
        $this->assertSame(401, $this->request('PUT', '/api/products/1', ['price' => 1])->status);
        $this->assertSame(401, $this->request('DELETE', '/api/products/1')->status);
    }

    public function testGarbageTokenIsRejected(): void
    {
        $response = $this->request('GET', '/api/products', token: 'garbage.token.here');
        $this->assertSame(401, $response->status);
    }

    // ---- list / pagination / search / filter ----

    public function testListReturnsPaginatedProducts(): void
    {
        $token = $this->login();
        $response = $this->request('GET', '/api/products', token: $token, query: ['page' => '1', 'per_page' => '10']);

        $this->assertSame(200, $response->status);
        $this->assertCount(10, $response->payload['data']);
        $meta = $response->payload['meta'];
        $this->assertSame(25, $meta['total']); // seeded catalog size
        $this->assertSame(3, $meta['total_pages']);
        $this->assertTrue($meta['has_next']);
        $this->assertFalse($meta['has_prev']);
    }

    public function testLastPageHasRemainder(): void
    {
        $token = $this->login();
        $response = $this->request('GET', '/api/products', token: $token, query: ['page' => '3', 'per_page' => '10']);

        $this->assertCount(5, $response->payload['data']);
        $this->assertFalse($response->payload['meta']['has_next']);
        $this->assertTrue($response->payload['meta']['has_prev']);
    }

    public function testSearchByKeyword(): void
    {
        $token = $this->login();
        $response = $this->request('GET', '/api/products', token: $token, query: ['q' => 'keyboard']);

        $this->assertGreaterThanOrEqual(1, count($response->payload['data']));
        foreach ($response->payload['data'] as $product) {
            $haystack = strtolower($product['name'] . ' ' . $product['description'] . ' ' . $product['sku']);
            $this->assertStringContainsString('keyboard', $haystack);
        }
    }

    public function testFilterByCategoryAndPriceRange(): void
    {
        $token = $this->login();
        $response = $this->request('GET', '/api/products', token: $token, query: [
            'category' => 'Electronics', 'min_price' => '30', 'max_price' => '120',
        ]);

        $this->assertGreaterThanOrEqual(1, count($response->payload['data']));
        foreach ($response->payload['data'] as $product) {
            $this->assertSame('Electronics', $product['category']);
            $this->assertGreaterThanOrEqual(30.0, $product['price']);
            $this->assertLessThanOrEqual(120.0, $product['price']);
        }
    }

    public function testSortByPriceAscending(): void
    {
        $token = $this->login();
        $response = $this->request('GET', '/api/products', token: $token, query: [
            'sort' => 'price', 'order' => 'asc', 'per_page' => '100',
        ]);

        $prices = array_column($response->payload['data'], 'price');
        $sorted = $prices;
        sort($sorted);
        $this->assertSame($sorted, $prices);
    }

    public function testSqlInjectionAttemptInSortIsNeutralized(): void
    {
        $token = $this->login();
        $response = $this->request('GET', '/api/products', token: $token, query: [
            'sort' => 'price; DROP TABLE products--',
        ]);

        $this->assertSame(200, $response->status); // falls back to default sort
        $this->assertSame(200, $this->request('GET', '/api/products', token: $token)->status);
    }

    // ---- create ----

    public function testCreateProduct(): void
    {
        $token = $this->login();
        $response = $this->request('POST', '/api/products', self::VALID_PRODUCT, $token);

        $this->assertSame(201, $response->status);
        $product = $response->payload['data'];
        $this->assertSame('Test Gadget', $product['name']);
        $this->assertSame('TST-9999', $product['sku']);
        $this->assertSame(12.34, $product['price']);
        $this->assertIsInt($product['id']);

        $fetched = $this->request('GET', '/api/products/' . $product['id'], token: $token);
        $this->assertSame(200, $fetched->status);
    }

    public function testCreateWithInvalidPayloadReturnsFieldErrors(): void
    {
        $token = $this->login();
        $response = $this->request('POST', '/api/products', ['name' => '', 'price' => -1], $token);

        $this->assertSame(422, $response->status);
        $details = $response->payload['error']['details'];
        $this->assertArrayHasKey('name', $details);
        $this->assertArrayHasKey('price', $details);
        $this->assertArrayHasKey('sku', $details);
        $this->assertArrayHasKey('category', $details);
    }

    public function testCreateWithDuplicateSkuConflicts(): void
    {
        $token = $this->login();
        $this->request('POST', '/api/products', self::VALID_PRODUCT, $token);
        $response = $this->request('POST', '/api/products', self::VALID_PRODUCT, $token);

        $this->assertSame(409, $response->status);
        $this->assertSame('DUPLICATE_SKU', $response->payload['error']['code']);
    }

    // ---- update ----

    public function testPartialUpdateOnlyChangesProvidedFields(): void
    {
        $token = $this->login();
        $created = $this->request('POST', '/api/products', self::VALID_PRODUCT, $token)->payload['data'];

        $response = $this->request('PUT', '/api/products/' . $created['id'], ['price' => 99.99], $token);

        $this->assertSame(200, $response->status);
        $updated = $response->payload['data'];
        $this->assertSame(99.99, $updated['price']);
        $this->assertSame('Test Gadget', $updated['name']); // untouched
        $this->assertSame(7, $updated['stock']);            // untouched
    }

    public function testUpdateNonExistentProductIs404(): void
    {
        $token = $this->login();
        $response = $this->request('PUT', '/api/products/999999', ['price' => 1], $token);
        $this->assertSame(404, $response->status);
    }

    public function testUpdateWithEmptyBodyIsRejected(): void
    {
        $token = $this->login();
        $created = $this->request('POST', '/api/products', self::VALID_PRODUCT, $token)->payload['data'];

        $response = $this->request('PUT', '/api/products/' . $created['id'], [], $token);
        $this->assertSame(422, $response->status);
    }

    public function testUpdateToExistingSkuConflicts(): void
    {
        $token = $this->login();
        $created = $this->request('POST', '/api/products', self::VALID_PRODUCT, $token)->payload['data'];

        $response = $this->request('PUT', '/api/products/' . $created['id'], ['sku' => 'ELC-0001'], $token);
        $this->assertSame(409, $response->status);
    }

    // ---- delete ----

    public function testDeleteProduct(): void
    {
        $token = $this->login();
        $created = $this->request('POST', '/api/products', self::VALID_PRODUCT, $token)->payload['data'];

        $response = $this->request('DELETE', '/api/products/' . $created['id'], token: $token);
        $this->assertSame(204, $response->status);

        $fetched = $this->request('GET', '/api/products/' . $created['id'], token: $token);
        $this->assertSame(404, $fetched->status);
    }

    public function testDeleteNonExistentProductIs404(): void
    {
        $token = $this->login();
        $response = $this->request('DELETE', '/api/products/999999', token: $token);
        $this->assertSame(404, $response->status);
    }

    // ---- misc ----

    public function testCategoriesEndpoint(): void
    {
        $token = $this->login();
        $response = $this->request('GET', '/api/products/categories', token: $token);

        $this->assertSame(200, $response->status);
        $this->assertContains('Electronics', $response->payload['data']['categories']);
    }

    public function testInvalidIdFormatIs422(): void
    {
        $token = $this->login();
        $response = $this->request('GET', '/api/products/abc', token: $token);
        $this->assertSame(422, $response->status);
    }

    public function testUnknownRouteIs404AndWrongMethodIs405(): void
    {
        $token = $this->login();
        $this->assertSame(404, $this->request('GET', '/api/nope', token: $token)->status);
        $this->assertSame(405, $this->request('DELETE', '/api/products', token: $token)->status);
    }
}
