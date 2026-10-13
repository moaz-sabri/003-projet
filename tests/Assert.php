<?php

declare(strict_types=1);

/**
 * إطار اختبار صغير بلا أي اعتمادات خارجية.
 * يعمل على أي استضافة PHP: php tests/run.php
 */

final class Assert
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static array $failures = [];
    public static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n\033[1m" . $name . "\033[0m\n";
    }

    public static function true(bool $value, string $message): void
    {
        self::record($value, $message, 'expected true, got false');
    }

    public static function false(bool $value, string $message): void
    {
        self::record(!$value, $message, 'expected false, got true');
    }

    public static function same(mixed $expected, mixed $actual, string $message): void
    {
        self::record(
            $expected === $actual,
            $message,
            'expected ' . self::show($expected) . ', got ' . self::show($actual)
        );
    }

    public static function equals(mixed $expected, mixed $actual, string $message): void
    {
        self::record(
            $expected == $actual,
            $message,
            'expected ' . self::show($expected) . ', got ' . self::show($actual)
        );
    }

    public static function notSame(mixed $unexpected, mixed $actual, string $message): void
    {
        self::record(
            $unexpected !== $actual,
            $message,
            'value should differ from ' . self::show($unexpected)
        );
    }

    public static function contains(string $haystack, string $needle, string $message): void
    {
        self::record(
            str_contains($haystack, $needle),
            $message,
            'missing: ' . $needle
        );
    }

    public static function notContains(string $haystack, string $needle, string $message): void
    {
        self::record(
            !str_contains($haystack, $needle),
            $message,
            'unexpectedly contains: ' . $needle
        );
    }

    public static function greater(int|float $actual, int|float $limit, string $message): void
    {
        self::record($actual > $limit, $message, 'expected > ' . $limit . ', got ' . $actual);
    }

    public static function atLeast(int|float $actual, int|float $limit, string $message): void
    {
        self::record($actual >= $limit, $message, 'expected >= ' . $limit . ', got ' . $actual);
    }

    /** يتوقع أن يرمي الكود استثناء من نوع محدد. */
    public static function throws(callable $fn, string $expectedClass, string $message): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            self::record(
                $e instanceof $expectedClass,
                $message,
                'threw ' . $e::class . ': ' . $e->getMessage()
            );
            return;
        }
        self::record(false, $message, 'no exception thrown (expected ' . $expectedClass . ')');
    }

    /** لا يرمي استثناء. */
    public static function passes(callable $fn, string $message): void
    {
        try {
            $fn();
            self::record(true, $message);
        } catch (\Throwable $e) {
            self::record(false, $message, 'threw ' . $e::class . ': ' . $e->getMessage());
        }
    }

    private static function record(bool $ok, string $message, string $detail = ''): void
    {
        if ($ok) {
            self::$passed++;
            echo "  \033[32m✓\033[0m " . $message . "\n";
        } else {
            self::$failed++;
            self::$failures[] = self::$group . ' → ' . $message . ' (' . $detail . ')';
            echo "  \033[31m✗\033[0m " . $message . "\n      \033[31m" . $detail . "\033[0m\n";
        }
    }

    private static function show(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_null($value)) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: 'complex';
    }
}