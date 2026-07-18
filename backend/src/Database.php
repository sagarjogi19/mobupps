<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * PDO connection factory + schema migration + development seed data.
 *
 * SQLite is used for portability (zero setup). Because everything goes
 * through PDO with prepared statements, swapping to MySQL/PostgreSQL only
 * requires changing the DSN and the tiny bit of DDL below.
 */
final class Database
{
    public static function connect(string $path): PDO
    {
        if ($path !== ':memory:' && !is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                sku TEXT NOT NULL UNIQUE,
                description TEXT NOT NULL DEFAULT '',
                price REAL NOT NULL CHECK (price >= 0),
                category TEXT NOT NULL,
                stock INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_name ON products(name)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_category ON products(category)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_price ON products(price)');
    }

    public static function seed(PDO $pdo): void
    {
        $hasUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
        if (!$hasUsers) {
            $stmt = $pdo->prepare('INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?)');
            $stmt->execute(['admin@example.com', 'Store Admin', password_hash('admin123', PASSWORD_DEFAULT)]);
        }

        $hasProducts = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() > 0;
        if ($hasProducts) {
            return;
        }

        $samples = [
            ['Wireless Mouse', 'ELC-0001', 'Ergonomic 2.4GHz wireless mouse with silent clicks.', 24.99, 'Electronics', 120],
            ['Mechanical Keyboard', 'ELC-0002', 'Hot-swappable RGB mechanical keyboard, brown switches.', 89.00, 'Electronics', 45],
            ['USB-C Hub 7-in-1', 'ELC-0003', 'HDMI 4K, 3x USB 3.0, SD/microSD, 100W PD.', 39.50, 'Electronics', 80],
            ['Noise Cancelling Headphones', 'ELC-0004', 'Over-ear ANC headphones, 35h battery life.', 199.99, 'Electronics', 30],
            ['1080p Webcam', 'ELC-0005', 'Full HD webcam with built-in dual microphones.', 49.99, 'Electronics', 60],
            ['Portable SSD 1TB', 'ELC-0006', 'USB 3.2 Gen2 portable SSD, 1050MB/s read.', 109.00, 'Electronics', 55],
            ['Smart LED Bulb', 'ELC-0007', 'WiFi RGB bulb, works with voice assistants.', 14.99, 'Electronics', 200],
            ['Bluetooth Speaker', 'ELC-0008', 'Waterproof IPX7 speaker with 12h playtime.', 59.99, 'Electronics', 75],
            ['Cotton T-Shirt', 'CLT-0001', '100% organic cotton, unisex fit.', 19.99, 'Clothing', 300],
            ['Denim Jacket', 'CLT-0002', 'Classic fit denim jacket, stone washed.', 79.00, 'Clothing', 40],
            ['Running Shoes', 'CLT-0003', 'Lightweight breathable running shoes.', 95.00, 'Clothing', 65],
            ['Wool Beanie', 'CLT-0004', 'Warm merino wool beanie, one size.', 22.50, 'Clothing', 150],
            ['Rain Jacket', 'CLT-0005', 'Packable waterproof shell, taped seams.', 120.00, 'Clothing', 25],
            ['Espresso Maker', 'HOM-0001', '15-bar pump espresso machine with steam wand.', 149.99, 'Home & Kitchen', 20],
            ['Chef Knife 8"', 'HOM-0002', 'German stainless steel chef knife.', 64.99, 'Home & Kitchen', 90],
            ['Non-stick Pan Set', 'HOM-0003', '3-piece ceramic non-stick fry pan set.', 54.00, 'Home & Kitchen', 35],
            ['French Press', 'HOM-0004', '1L borosilicate glass french press.', 27.99, 'Home & Kitchen', 110],
            ['Scented Candle', 'HOM-0005', 'Soy wax candle, sandalwood & vanilla, 45h burn.', 16.50, 'Home & Kitchen', 180],
            ['Yoga Mat', 'SPT-0001', '6mm non-slip TPE yoga mat with carry strap.', 32.00, 'Sports', 95],
            ['Adjustable Dumbbells', 'SPT-0002', 'Pair of 2.5-24kg adjustable dumbbells.', 249.00, 'Sports', 15],
            ['Resistance Bands Set', 'SPT-0003', '5-piece latex resistance band set with handles.', 21.99, 'Sports', 140],
            ['Insulated Water Bottle', 'SPT-0004', '750ml double-wall stainless steel bottle.', 25.00, 'Sports', 160],
            ['Clean Code (Paperback)', 'BOK-0001', 'A Handbook of Agile Software Craftsmanship.', 34.99, 'Books', 70],
            ['Designing Data-Intensive Applications', 'BOK-0002', 'The big ideas behind reliable, scalable systems.', 44.99, 'Books', 50],
            ['The Pragmatic Programmer', 'BOK-0003', '20th Anniversary Edition, from journeyman to master.', 39.99, 'Books', 5],
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO products (name, sku, description, price, category, stock) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($samples as $row) {
            $stmt->execute($row);
        }
    }
}
