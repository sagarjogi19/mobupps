<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * All product persistence in one place. Every query uses prepared
 * statements; sort columns are whitelisted to prevent SQL injection
 * through the `sort` query parameter.
 */
final class ProductRepository
{
    private const SORTABLE = ['name', 'price', 'stock', 'category', 'created_at', 'updated_at', 'id'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{
     *   q?: string, category?: string, min_price?: float, max_price?: float,
     *   in_stock?: bool, sort?: string, order?: string, page?: int, per_page?: int
     * } $criteria
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function search(array $criteria): array
    {
        $where = [];
        $params = [];

        if (($criteria['q'] ?? '') !== '') {
            $where[] = '(name LIKE :q OR description LIKE :q OR sku LIKE :q)';
            $params[':q'] = '%' . self::escapeLike((string) $criteria['q']) . '%';
        }
        if (($criteria['category'] ?? '') !== '') {
            $where[] = 'category = :category';
            $params[':category'] = $criteria['category'];
        }
        if (isset($criteria['min_price'])) {
            $where[] = 'price >= :min_price';
            $params[':min_price'] = $criteria['min_price'];
        }
        if (isset($criteria['max_price'])) {
            $where[] = 'price <= :max_price';
            $params[':max_price'] = $criteria['max_price'];
        }
        if (($criteria['in_stock'] ?? false) === true) {
            $where[] = 'stock > 0';
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $sort = in_array($criteria['sort'] ?? '', self::SORTABLE, true) ? $criteria['sort'] : 'created_at';
        $order = strtolower($criteria['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $page = max(1, (int) ($criteria['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($criteria['per_page'] ?? 10)));
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM products$whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT * FROM products$whereSql ORDER BY $sort $order, id DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map([self::class, 'cast'], $stmt->fetchAll()),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::cast($row);
    }

    public function skuExists(string $sku, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM products WHERE sku = ?';
        $params = [$sku];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @param array{name: string, sku: string, description: string, price: float, category: string, stock: int} $data
     * @return array<string, mixed> the created product
     */
    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, sku, description, price, category, stock) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'], $data['sku'], $data['description'],
            $data['price'], $data['category'], $data['stock'],
        ]);
        return $this->find((int) $this->pdo->lastInsertId());
    }

    /**
     * @param array<string, mixed> $data whitelisted fields to update
     * @return array<string, mixed> the updated product
     */
    public function update(int $id, array $data): array
    {
        $sets = [];
        $params = [];
        foreach (['name', 'sku', 'description', 'price', 'category', 'stock'] as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        if ($sets !== []) {
            $sets[] = "updated_at = datetime('now')";
            $params[] = $id;
            $stmt = $this->pdo->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $stmt->execute($params);
        }
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** @return string[] distinct categories, for the filter dropdown */
    public function categories(): array
    {
        return $this->pdo->query('SELECT DISTINCT category FROM products ORDER BY category')
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param array<string, mixed> $row */
    private static function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['price'] = round((float) $row['price'], 2);
        $row['stock'] = (int) $row['stock'];
        return $row;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $value);
    }
}
