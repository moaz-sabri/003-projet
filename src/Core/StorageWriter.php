<?php

declare(strict_types=1);

namespace App\Core;

/**
 * أدوات مشتركة لكتابة ملفات التخزين بصلاحيات آمنة.
 */

final class StorageWriter
{
    /**
     * يكتب ملفًا بصلاحيات مقروءة فقط من مالكه (0600/0640 بعد chown)،
     * ويصلح الملكية تلقائيًا إذا كان التنفيذ بحساب root (حاويات Docker).
     */
    public static function write(string $path, string $contents, int $mode = 0640): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('تعذّر إنشاء المجلد: ' . $dir);
        }

        file_put_contents($path, $contents, LOCK_EX);
        self::fixOwnership($path, $mode);
    }

    /** ي-adjust الملكية إن أمكن، ثم يضبط الصلاحيات. */
    public static function fixOwnership(string $path, int $mode = 0640): void
    {
        self::chownIfRoot($path);
        @chmod($path, $mode);
    }

    /** يضبط ملكية مجلد التخزين بالكامل (مفيد بعد تهيئة داخل حاوية). */
    public static function fixTree(string $directory, int $dirMode = 0775, int $fileMode = 0664): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !function_exists('chown')) {
            return;
        }

        $user = self::webUser();
        if ($user === null) {
            return;
        }

        @chown($directory, $user);
        @chgrp($directory, $user);
        @chmod($directory, $dirMode);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @chmod($item->getPathname(), $dirMode);
            } else {
                @chmod($item->getPathname(), $fileMode);
            }
            @chown($item->getPathname(), $user);
            @chgrp($item->getPathname(), $user);
        }
    }

    /** أول مستخدم خادم ويب موجود على النظام. */
    public static function webUser(): ?string
    {
        foreach (['www-data', 'nginx', 'apache', 'httpd'] as $user) {
            if (function_exists('posix_getpwnam') && posix_getpwnam($user) !== false) {
                return $user;
            }
        }
        return null;
    }

    /** يعيد تنفيذ chown فقط عند الحاجة (صلاحيات الملف لا تكفي). */
    public static function chownIfRoot(string $path): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !function_exists('chown')) {
            return;
        }
        $user = self::webUser() ?? 'nobody';
        @chown($path, $user);
        @chgrp($path, $user);
        clearstatcache(true, $path);
    }
}