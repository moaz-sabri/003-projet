<?php

declare(strict_types=1);

namespace App\Core;

/**
 * سجلّ بسيط للأخطاء في ملف داخل storage/logs (غير متاح عبر الويب).
 */
final class Logger
{
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARN', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        try {
            $dir = BASE_PATH . '/storage/logs';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $line = sprintf(
                "[%s] %s: %s %s\n",
                date('Y-m-d H:i:s'),
                $level,
                $message,
                $context === [] ? '' : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // لا نُفشل الطلب بسبب فشل التسجيل.
        }
    }
}