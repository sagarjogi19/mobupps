<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Validates and normalizes product payloads.
 *
 * validate() returns ['data' => normalized fields, 'errors' => field => message].
 * In partial mode (PATCH-like updates via PUT) only the provided fields are
 * validated; in full mode required fields must be present.
 */
final class ProductValidator
{
    private const MAX_NAME = 160;
    private const MAX_SKU = 40;
    private const MAX_DESCRIPTION = 2000;
    private const MAX_CATEGORY = 80;
    private const MAX_PRICE = 1_000_000;
    private const MAX_STOCK = 1_000_000;

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    public function validate(array $input, bool $partial = false): array
    {
        $data = [];
        $errors = [];

        $has = fn (string $f): bool => array_key_exists($f, $input);

        // name
        if ($has('name')) {
            $name = is_string($input['name']) ? trim($input['name']) : '';
            if ($name === '' || !is_string($input['name'])) {
                $errors['name'] = 'Name is required and must be a non-empty string.';
            } elseif (mb_strlen($name) > self::MAX_NAME) {
                $errors['name'] = 'Name must be at most ' . self::MAX_NAME . ' characters.';
            } else {
                $data['name'] = $name;
            }
        } elseif (!$partial) {
            $errors['name'] = 'Name is required.';
        }

        // sku
        if ($has('sku')) {
            $sku = is_string($input['sku']) ? strtoupper(trim($input['sku'])) : '';
            if ($sku === '') {
                $errors['sku'] = 'SKU is required and must be a non-empty string.';
            } elseif (mb_strlen($sku) > self::MAX_SKU || !preg_match('/^[A-Z0-9][A-Z0-9\-_.]*$/', $sku)) {
                $errors['sku'] = 'SKU may only contain letters, numbers, dashes, dots and underscores (max ' . self::MAX_SKU . ' chars).';
            } else {
                $data['sku'] = $sku;
            }
        } elseif (!$partial) {
            $errors['sku'] = 'SKU is required.';
        }

        // description (optional, defaults to empty string)
        if ($has('description')) {
            if (!is_string($input['description'])) {
                $errors['description'] = 'Description must be a string.';
            } elseif (mb_strlen($input['description']) > self::MAX_DESCRIPTION) {
                $errors['description'] = 'Description must be at most ' . self::MAX_DESCRIPTION . ' characters.';
            } else {
                $data['description'] = trim($input['description']);
            }
        } elseif (!$partial) {
            $data['description'] = '';
        }

        // price
        if ($has('price')) {
            if (!is_numeric($input['price'])) {
                $errors['price'] = 'Price must be a number.';
            } else {
                $price = (float) $input['price'];
                if ($price < 0) {
                    $errors['price'] = 'Price cannot be negative.';
                } elseif ($price > self::MAX_PRICE) {
                    $errors['price'] = 'Price cannot exceed ' . self::MAX_PRICE . '.';
                } else {
                    $data['price'] = round($price, 2);
                }
            }
        } elseif (!$partial) {
            $errors['price'] = 'Price is required.';
        }

        // category
        if ($has('category')) {
            $category = is_string($input['category']) ? trim($input['category']) : '';
            if ($category === '') {
                $errors['category'] = 'Category is required and must be a non-empty string.';
            } elseif (mb_strlen($category) > self::MAX_CATEGORY) {
                $errors['category'] = 'Category must be at most ' . self::MAX_CATEGORY . ' characters.';
            } else {
                $data['category'] = $category;
            }
        } elseif (!$partial) {
            $errors['category'] = 'Category is required.';
        }

        // stock (optional on create, defaults to 0)
        if ($has('stock')) {
            $stock = $input['stock'];
            if (!is_int($stock) && !(is_string($stock) && ctype_digit($stock))) {
                $errors['stock'] = 'Stock must be a non-negative integer.';
            } elseif ((int) $stock < 0) {
                $errors['stock'] = 'Stock cannot be negative.';
            } elseif ((int) $stock > self::MAX_STOCK) {
                $errors['stock'] = 'Stock cannot exceed ' . self::MAX_STOCK . '.';
            } else {
                $data['stock'] = (int) $stock;
            }
        } elseif (!$partial) {
            $data['stock'] = 0;
        }

        return ['data' => $data, 'errors' => $errors];
    }
}
