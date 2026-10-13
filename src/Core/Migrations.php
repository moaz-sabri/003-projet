<?php

declare(strict_types=1);

namespace App\Core;

/**
 * المخطط الكامل لقاعدة البيانات (SQLite) + زرع الإعدادات الافتراضية.
 *
 * كل الأسعار تُخزَّن كأعداد صحيحة بالوحدة الصغرى (price_minor) لتفادي
 * أخطاء الفاصلة العائمة. example: 25000 (= 25,000 ل.ل عند scale=0)
 */
final class Migrations
{
    public static function isInstalled(): bool
    {
        try {
            $row = Database::one("SELECT name FROM sqlite_master WHERE type='table' AND name='app_meta'");
            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function migrate(): void
    {
        Database::pdo()->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS app_meta (
                key         TEXT PRIMARY KEY,
                value       TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS settings (
                key         TEXT PRIMARY KEY,
                value       TEXT NOT NULL DEFAULT '',
                updated_at  TEXT NOT NULL DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS categories (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                image_path  TEXT NOT NULL DEFAULT '',
                sort_order  INTEGER NOT NULL DEFAULT 0,
                is_active   INTEGER NOT NULL DEFAULT 1,
                created_at  TEXT NOT NULL DEFAULT '',
                updated_at  TEXT NOT NULL DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS products (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id   INTEGER NULL REFERENCES categories(id) ON DELETE SET NULL,
                name          TEXT NOT NULL,
                description   TEXT NOT NULL DEFAULT '',
                price_minor   INTEGER NOT NULL DEFAULT 0,
                image_path    TEXT NOT NULL DEFAULT '',
                extra_json    TEXT NOT NULL DEFAULT '{}',
                is_available  INTEGER NOT NULL DEFAULT 1,
                is_featured   INTEGER NOT NULL DEFAULT 0,
                sort_order    INTEGER NOT NULL DEFAULT 0,
                created_at    TEXT NOT NULL DEFAULT '',
                updated_at    TEXT NOT NULL DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS locations (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                code        TEXT NOT NULL UNIQUE,
                name        TEXT NOT NULL,
                type        TEXT NOT NULL DEFAULT '',
                note        TEXT NOT NULL DEFAULT '',
                is_active   INTEGER NOT NULL DEFAULT 1,
                sort_order  INTEGER NOT NULL DEFAULT 0,
                created_at  TEXT NOT NULL DEFAULT '',
                updated_at  TEXT NOT NULL DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS orders (
                id                 INTEGER PRIMARY KEY AUTOINCREMENT,
                public_token       TEXT NOT NULL UNIQUE,
                number             INTEGER NOT NULL,
                served_on          TEXT NOT NULL,
                order_type         TEXT NOT NULL DEFAULT 'dine_in',
                customer_name      TEXT NOT NULL DEFAULT '',
                customer_phone     TEXT NOT NULL DEFAULT '',
                location_id        INTEGER NULL REFERENCES locations(id) ON DELETE SET NULL,
                location_label     TEXT NOT NULL DEFAULT '',
                status             TEXT NOT NULL DEFAULT 'new',
                fields_json        TEXT NOT NULL DEFAULT '{}',
                notes              TEXT NOT NULL DEFAULT '',
                total_minor        INTEGER NOT NULL DEFAULT 0,
                items_count        INTEGER NOT NULL DEFAULT 0,
                idempotency_key    TEXT NULL UNIQUE,
                cancel_reason      TEXT NOT NULL DEFAULT '',
                created_at         TEXT NOT NULL DEFAULT '',
                updated_at         TEXT NOT NULL DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS order_items (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id         INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
                product_id       INTEGER NULL REFERENCES products(id) ON DELETE SET NULL,
                name             TEXT NOT NULL,
                unit_price_minor INTEGER NOT NULL DEFAULT 0,
                quantity         INTEGER NOT NULL DEFAULT 1,
                line_total_minor INTEGER NOT NULL DEFAULT 0,
                extra_json       TEXT NOT NULL DEFAULT '{}',
                created_at       TEXT NOT NULL DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS order_events (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id    INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
                from_status TEXT NOT NULL DEFAULT '',
                to_status   TEXT NOT NULL,
                actor       TEXT NOT NULL DEFAULT 'system',
                note        TEXT NOT NULL DEFAULT '',
                created_at  TEXT NOT NULL DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS daily_counters (
                day   TEXT PRIMARY KEY,
                value INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS login_attempts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                username   TEXT NOT NULL,
                ip         TEXT NOT NULL,
                success    INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT ''
            );

            CREATE INDEX IF NOT EXISTS idx_products_category  ON products(category_id, sort_order);
            CREATE INDEX IF NOT EXISTS idx_products_available ON products(is_available);
            CREATE INDEX IF NOT EXISTS idx_orders_status      ON orders(status, created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_orders_location    ON orders(location_id, status);
            CREATE INDEX IF NOT EXISTS idx_orders_token       ON orders(public_token);
            CREATE INDEX IF NOT EXISTS idx_orders_served_on   ON orders(served_on, number);
            CREATE INDEX IF NOT EXISTS idx_orders_created     ON orders(created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_items_order        ON order_items(order_id);
            CREATE INDEX IF NOT EXISTS idx_events_order       ON order_events(order_id, id);
            CREATE INDEX IF NOT EXISTS idx_login_ip           ON login_attempts(ip, created_at DESC);
        SQL);

        $version = '1';
        Database::run(
            'INSERT INTO app_meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            ['schema_version', $version]
        );

        self::ensureColumns();
        self::seedSettings();
        self::seedCategories();
    }

    /**
     * يضيف الأعمدة الناقصة على قواعد أُنشئت بإصدار أقدم.
     * (بديل خفيف عن أداة ترحيل كاملة، كافٍ لهذا المشروع)
     */
    private static function ensureColumns(): void
    {
        $required = [
            'categories' => [
                'description' => "TEXT NOT NULL DEFAULT ''",
                'image_path'  => "TEXT NOT NULL DEFAULT ''",
            ],
            'products' => [
                'is_featured' => 'INTEGER NOT NULL DEFAULT 0',
                'sort_order'  => 'INTEGER NOT NULL DEFAULT 0',
                'extra_json'  => "TEXT NOT NULL DEFAULT '{}'",
            ],
            'locations' => [
                'type'      => "TEXT NOT NULL DEFAULT ''",
                'note'      => "TEXT NOT NULL DEFAULT ''",
                'sort_order' => 'INTEGER NOT NULL DEFAULT 0',
            ],
            'orders' => [
                'customer_phone' => "TEXT NOT NULL DEFAULT ''",
                'fields_json'    => "TEXT NOT NULL DEFAULT '{}'",
                'cancel_reason'  => "TEXT NOT NULL DEFAULT ''",
                'idempotency_key' => 'TEXT NULL',
                'items_count'    => 'INTEGER NOT NULL DEFAULT 0',
            ],
            'order_items' => [
                'extra_json' => "TEXT NOT NULL DEFAULT '{}'",
                'created_at' => "TEXT NOT NULL DEFAULT ''",
            ],
            'order_events' => [
                'note' => "TEXT NOT NULL DEFAULT ''",
            ],
        ];

        foreach ($required as $table => $columns) {
            $existing = [];
            foreach (Database::all('PRAGMA table_info(' . $table . ')') as $column) {
                $existing[$column['name']] = true;
            }
            if ($existing === []) {
                continue; // الجدول غير موجود
            }
            foreach ($columns as $name => $definition) {
                if (!isset($existing[$name])) {
                    Database::pdo()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $name . ' ' . $definition);
                }
            }
        }
    }

    /** زرع الإعدادات الافتراضية دون الكتابة فوق قيم المدير. */
    public static function seedSettings(): void
    {
        foreach (\App\Config\ProjectConfig::defaultRuntimeSettings() as $key => $value) {
            Database::run(
                'INSERT INTO settings (key, value) VALUES (:k, :v) ON CONFLICT(key) DO NOTHING',
                ['k' => $key, 'v' => (string) $value]
            );
        }
    }

    /** تصنيفات ابتدائية لمطعم — يمكن للمدير حذفها أو تعديلها فورًا. */
    public static function seedCategories(): void
    {
        $count = (int) Database::value('SELECT COUNT(*) FROM categories');
        if ($count > 0) {
            return;
        }
        $defaults = ['المشروبات الساخنة', 'المشروبات الباردة', 'الأكل', 'حلويات'];
        foreach ($defaults as $i => $name) {
            Database::insert('categories', [
                'name'       => $name,
                'sort_order' => ($i + 1) * 10,
                'is_active'  => 1,
                'created_at' => Database::now(),
                'updated_at' => Database::now(),
            ]);
        }
    }

    /** حذف كل البيانات (migrate:fresh) */
    public static function reset(): void
    {
        $tables = ['order_events', 'order_items', 'orders', 'products', 'categories', 'locations', 'settings', 'daily_counters', 'login_attempts'];
        Database::pdo()->exec('PRAGMA foreign_keys = OFF');
        foreach ($tables as $t) {
            Database::pdo()->exec('DROP TABLE IF EXISTS ' . $t);
        }
        Database::pdo()->exec('PRAGMA foreign_keys = ON');
        self::migrate();
    }
}