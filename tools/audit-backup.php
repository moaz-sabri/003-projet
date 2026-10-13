#!/usr/bin/env php
<?php
/**
 * أداة المراجعة: نسخة احتياطية كاملة قبل أي تعديل.
 *
 *   php bin/console backup --full    (أو: php tools/audit-backup.php)
 *
 * تنشئ:
 *   storage/backups/backup-<التاريخ>.sqlite      (لقطة قاعدة البيانات)
 *   storage/backups/uploads-<التاريخ>.zip        (نسخة الصور المرفوعة)
 *   storage/backups/config-<التاريخ>.php         (نسخة من إعدادات الدخول)
 *   storage/backups/MANIFEST-<التاريخ>.txt      (قائمة بالملفات والمقادير)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("هذه الأداة تعمل من الطرفية فقط.\n");
}

require __DIR__ . '/../src/Core/Autoloader.php';

$backups = BASE_PATH . '/storage/backups';
if (!is_dir($backups)) {
    mkdir($backups, 0775, true);
}

$stamp = date('Ymd-His');
$lines = [];

/* ---------------------------- قاعدة البيانات ---------------------------- */
$dbPath = (string) app_config('db.path', BASE_PATH . '/storage/database/app.sqlite');
if (is_file($dbPath)) {
    $target = $backups . '/backup-' . $stamp . '.sqlite';
    if (is_file($target)) {
        unlink($target);
    }
    try {
        \App\Core\Database::pdo()->exec("VACUUM INTO '" . str_replace("'", "''", $target) . "'");
        $lines[] = sprintf('قاعدة البيانات : %s (%s كيلوبايت)', basename($target), number_format(filesize($target) / 1024, 1));
    } catch (Throwable $e) {
        $lines[] = 'تعذّر نسخ قاعدة البيانات: ' . $e->getMessage();
    }
} else {
    $lines[] = 'قاعدة البيانات: غير موجودة بعد';
}

/* ------------------------------- الصور ------------------------------- */
$uploads = BASE_PATH . '/app/uploads';
$count   = 0;
if (is_dir($uploads)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS));
    $count = iterator_count($iterator);
}
if ($count > 0) {
    $zipPath = $backups . '/uploads-' . $stamp . '.zip';
    if (class_exists('ZipArchive')) {
        $zipPath = $backups . '/uploads-' . $stamp . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS)) as $file) {
                $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($uploads) + 1));
            }
            $zip->close();
            $lines[] = sprintf('الصور المرفوعة : %s (%d ملف)', basename($zipPath), $count);
        }
    } else {
        // بديل عند غياب ZipArchive: نسخ مجلد كامل
        $dirPath = $backups . '/uploads-' . $stamp;
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0775, true);
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($uploads))), '/');
                @mkdir(dirname($dirPath . '/' . $relative), 0775, true);
                copy($file->getPathname(), $dirPath . '/' . $relative);
            }
        }
        $lines[] = sprintf('الصور المرفوعة : %s/ (%d ملف — نسخة مجلد)', basename($dirPath), $count);
    }
} else {
    $lines[] = 'الصور المرفوعة: لا يوجد';
}

/* ----------------------------- الإعدادات ----------------------------- */
$configFile = BASE_PATH . '/storage/config/local.php';
if (is_file($configFile)) {
    $copy = $backups . '/config-' . $stamp . '.php';
    copy($configFile, $copy);
    @chmod($copy, 0600);
    $lines[] = 'إعدادات الدخول : ' . basename($copy) . ' (تحتوي تجزئة كلمة المرور)';
} else {
    $lines[] = 'إعدادات الدخول : غير موجودة';
}

/* ------------------------------ البيان ------------------------------ */
$manifest = $backups . '/MANIFEST-' . $stamp . '.txt';
file_put_contents($manifest, implode("\n", $lines) . "\n", LOCK_EX);

echo implode("\n", $lines) . "\n";
echo "البيان: " . basename($manifest) . "\n";