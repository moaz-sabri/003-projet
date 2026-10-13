<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * طبقة قاعدة البيانات — SQLite واحدة، مع WAL واستعلامات محضّرة دائمًا.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $txDepth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $path = self::$pathOverride ?? (string) app_config('db.path', BASE_PATH . '/storage/database/app.sqlite');
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('تعذّر فتح قاعدة البيانات: ' . $e->getMessage(), 0, $e);
        }

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::$pdo = $pdo;
        return $pdo;
    }

    /** تبديل ملف قاعدة البيانات (يُستخدم في الاختبارات). */
    public static function usePath(string $path): void
    {
        self::$pdo    = null;
        self::$txDepth = 0;
        self::$pathOverride = $path;
    }

    private static ?string $pathOverride = null;

    /** إغلاق الاتصال (يُستخدم قبل الاستعادة). */
    public static function close(): void
    {
        self::$pdo = null;
    }

    /**
     * الوقت الحالي بتوقيت النسخة (وليس UTC).
     * نستخدمه في كل الأعمدة الزمنية بدل datetime('now') في SQLite
     * لأن دالة latter تُرجع UTC دائمًا، بينما تُعرض التواريخ بتوقيت محلي.
     */
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function today(): string
    {
        return date('Y-m-d');
    }

    /** تنفيذ استعلام مع معاملات. */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                is_null($value) => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(static fn(string $c): string => ':' . $c, $cols))
        );
        self::run($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = implode(', ', array_map(static fn(string $c): string => $c . ' = :set_' . $c, array_keys($data)));
        $sql  = sprintf('UPDATE %s SET %s WHERE %s', $table, $sets, $where);
        $params = [];
        foreach ($data as $k => $v) {
            $params['set_' . $k] = $v;
        }
        return self::run($sql, array_merge($params, $whereParams))->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::run(sprintf('DELETE FROM %s WHERE %s', $table, $where), $params)->rowCount();
    }

    /** معاملة، مع دعم المعاملات المتداخلة عبر SAVEPOINT. */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        if (self::$txDepth === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT sp' . self::$txDepth);
        }
        self::$txDepth++;

        try {
            $result = $callback();
            self::$txDepth--;
            if (self::$txDepth === 0) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT sp' . self::$txDepth);
            }
            return $result;
        } catch (\Throwable $e) {
            self::$txDepth--;
            if (self::$txDepth === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT sp' . self::$txDepth);
            }
            throw $e;
        }
    }
}