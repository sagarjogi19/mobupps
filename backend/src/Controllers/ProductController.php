<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\ProductRepository;
use App\Validation\ProductValidator;

final class ProductController
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductValidator $validator,
    ) {
    }

    /** GET /api/products — paginated list with search/filter/sort. */
    public function index(Request $request): Response
    {
        $q = $request->query;

        $criteria = [
            'q' => trim((string) ($q['q'] ?? '')),
            'category' => trim((string) ($q['category'] ?? '')),
            'sort' => (string) ($q['sort'] ?? 'created_at'),
            'order' => (string) ($q['order'] ?? 'desc'),
            'page' => (int) ($q['page'] ?? 1),
            'per_page' => (int) ($q['per_page'] ?? 10),
            'in_stock' => ($q['in_stock'] ?? '') === 'true' || ($q['in_stock'] ?? '') === '1',
        ];
        if (isset($q['min_price']) && is_numeric($q['min_price'])) {
            $criteria['min_price'] = (float) $q['min_price'];
        }
        if (isset($q['max_price']) && is_numeric($q['max_price'])) {
            $criteria['max_price'] = (float) $q['max_price'];
        }

        $result = $this->products->search($criteria);
        $totalPages = (int) max(1, ceil($result['total'] / $result['per_page']));

        return Response::ok($result['items'], 200, [
            'page' => $result['page'],
            'per_page' => $result['per_page'],
            'total' => $result['total'],
            'total_pages' => $totalPages,
            'has_next' => $result['page'] < $totalPages,
            'has_prev' => $result['page'] > 1,
        ]);
    }

    /** GET /api/products/{id} */
    public function show(Request $request, array $params): Response
    {
        $product = $this->findOr404($params);
        return is_array($product) ? Response::ok($product) : $product;
    }

    /** POST /api/products */
    public function create(Request $request): Response
    {
        ['data' => $data, 'errors' => $errors] = $this->validator->validate($request->body, partial: false);
        if ($errors !== []) {
            return Response::error(422, 'VALIDATION_ERROR', 'Validation failed.', $errors);
        }
        if ($this->products->skuExists($data['sku'])) {
            return Response::error(409, 'DUPLICATE_SKU', 'A product with this SKU already exists.', [
                'sku' => 'SKU must be unique.',
            ]);
        }
        return Response::ok($this->products->create($data), 201);
    }

    /** PUT /api/products/{id} — partial update: only provided fields change. */
    public function update(Request $request, array $params): Response
    {
        $existing = $this->findOr404($params);
        if (!is_array($existing)) {
            return $existing;
        }

        if ($request->body === []) {
            return Response::error(422, 'VALIDATION_ERROR', 'Request body is empty; nothing to update.');
        }

        ['data' => $data, 'errors' => $errors] = $this->validator->validate($request->body, partial: true);
        if ($errors !== []) {
            return Response::error(422, 'VALIDATION_ERROR', 'Validation failed.', $errors);
        }
        if (isset($data['sku']) && $this->products->skuExists($data['sku'], $existing['id'])) {
            return Response::error(409, 'DUPLICATE_SKU', 'A product with this SKU already exists.', [
                'sku' => 'SKU must be unique.',
            ]);
        }
        return Response::ok($this->products->update($existing['id'], $data));
    }

    /** DELETE /api/products/{id} */
    public function delete(Request $request, array $params): Response
    {
        $existing = $this->findOr404($params);
        if (!is_array($existing)) {
            return $existing;
        }
        $this->products->delete($existing['id']);
        return Response::noContent();
    }

    /** GET /api/products/categories — distinct categories for filter UI. */
    public function categories(Request $request): Response
    {
        return Response::ok(['categories' => $this->products->categories()]);
    }

    /** @return array<string, mixed>|Response */
    private function findOr404(array $params): array|Response
    {
        $raw = $params['id'] ?? '';
        if (!ctype_digit((string) $raw)) {
            return Response::error(422, 'VALIDATION_ERROR', 'Product id must be a positive integer.');
        }
        $product = $this->products->find((int) $raw);
        return $product ?? Response::error(404, 'NOT_FOUND', 'Product not found.');
    }
}
