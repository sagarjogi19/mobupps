<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validation\ProductValidator;
use PHPUnit\Framework\TestCase;

final class ProductValidatorTest extends TestCase
{
    private ProductValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ProductValidator();
    }

    public function testValidFullPayloadPasses(): void
    {
        $result = $this->validator->validate([
            'name' => '  Wireless Mouse ',
            'sku' => 'elc-1001',
            'description' => 'A mouse.',
            'price' => '24.995',
            'category' => 'Electronics',
            'stock' => '12',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('Wireless Mouse', $result['data']['name']); // trimmed
        $this->assertSame('ELC-1001', $result['data']['sku']);        // uppercased
        $this->assertSame(25.0, $result['data']['price']);            // rounded to 2dp
        $this->assertSame(12, $result['data']['stock']);              // cast to int
    }

    public function testMissingRequiredFieldsAreReported(): void
    {
        $result = $this->validator->validate([]);

        foreach (['name', 'sku', 'price', 'category'] as $field) {
            $this->assertArrayHasKey($field, $result['errors']);
        }
    }

    public function testDefaultsAppliedOnCreate(): void
    {
        $result = $this->validator->validate([
            'name' => 'X', 'sku' => 'X-1', 'price' => 1, 'category' => 'C',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('', $result['data']['description']);
        $this->assertSame(0, $result['data']['stock']);
    }

    public function testNegativePriceRejected(): void
    {
        $result = $this->validator->validate(['price' => -5], partial: true);
        $this->assertArrayHasKey('price', $result['errors']);
    }

    public function testNonNumericPriceRejected(): void
    {
        $result = $this->validator->validate(['price' => 'abc'], partial: true);
        $this->assertArrayHasKey('price', $result['errors']);
    }

    public function testInvalidSkuCharactersRejected(): void
    {
        $result = $this->validator->validate(['sku' => 'bad sku!'], partial: true);
        $this->assertArrayHasKey('sku', $result['errors']);
    }

    public function testNegativeStockRejected(): void
    {
        $result = $this->validator->validate(['stock' => -1], partial: true);
        $this->assertArrayHasKey('stock', $result['errors']);
    }

    public function testPartialModeIgnoresMissingFields(): void
    {
        $result = $this->validator->validate(['price' => 10], partial: true);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['price' => 10.0], $result['data']);
    }

    public function testOverlongNameRejected(): void
    {
        $result = $this->validator->validate(['name' => str_repeat('a', 200)], partial: true);
        $this->assertArrayHasKey('name', $result['errors']);
    }
}
