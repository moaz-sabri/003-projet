<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\ProjectConfig;

/**
 * النسخ الاحتياطي والاستعادة.
 * نستخدم VACUUM INTO المدمج في SQLite للحصول على نسخة سليمة حتى مع وجود WAL.
 */
final class BackupService
{
    public function databasePath(): string
    {
        return (string) app_config('db.path', ProjectConfig::storagePath('database/app.sqlite'));
    }

    /** ينشئ نسخة احتياطية ويُرجع مسار الملف. */
    public function create(): string
    {
        $dir = ProjectConfig::storagePath('backups');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name   = 'backup-' . date('Ymd-His') . '.sqlite';
        $target = $dir . '/' . $name;

        // نسختان في الثانية نفسها تصطدمان في نفس الاسم، و VACUUM INTO
        // يفشل عند وجود الملف مسبقًا — نضيف لاحقة رقمية عند الحاجة.
        if (is_file($target)) {
            for ($i = 2; $i < 100; $i++) {
                $candidate = $dir . '/backup-' . date('Ymd-His') . '-' . $i . '.sqlite';
                if (!is_file($candidate)) {
                    $name   = 'backup-' . date('Ymd-His') . '-' . $i . '.sqlite';
                    $target = $candidate;
                    break;
                }
            }
        }

        \App\Core\Database::pdo()->exec("VACUUM INTO '" . str_replace("'", "''", $target) . "'");

        @chmod($target, 0600);
        return $target;
    }

    /** قائمة النسخ الاحتياطية المتاحة (اسم + الحجم + التاريخ). */
    public function listBackups(): array
    {
        $dir   = ProjectConfig::storagePath('backups');
        $files = is_dir($dir) ? glob($dir . '/backup-*.sqlite') ?: [] : [];
        rsort($files);

        $out = [];
        foreach ($files as $file) {
            $out[] = [
                'name' => basename($file),
                'size' => number_format((int) filesize($file) / 1024, 1) . ' كيلوبايت',
                'date' => date('Y-m-d H:i', (int) filemtime($file)),
            ];
        }
        return $out;
    }

    /**
     * النمط القانوني لاسم النسخة (بدون أدوات التثبيت).
     *
     * لاحقة «-N» إلزامية: create() يولّدها عند تصادم نسختين في الثانية نفسها،
     * ولم يكن هذا النمط يسمح بها ⇒ النسخة التي أُنشئت في لحظة التصادم — وهي
     * بالضبط النسخة الأهم — كانت تُدرج في القائمة ثم تُرفض عند التنزيل
     * والاستعادة بـ 404.
     *
     * نستخدم \A و \z لا ^ و $: الأخير يطابق قبل سطر جديد، فيمرّر
     * "backup-20261013-131813.sqlite\n" بلا رفض.
     */
    private const NAME_PATTERN = '\Abackup-\d{8}-\d{6}(-\d{1,3})?\.sqlite\z';

    public function backupPath(string $name): ?string
    {
        if (!preg_match('/' . self::NAME_PATTERN . '/', $name)) {
            return null;
        }
        $path = ProjectConfig::storagePath('backups/' . $name);
        return is_file($path) ? $path : null;
    }

    /** يُرجع محتوى ملف النسخة كسلسلة جاهزة للتنزيل. */
    public function read(string $name): ?string
    {
        $path = $this->backupPath($name);
        if ($path === null) {
            return null;
        }
        return (string) file_get_contents($path);
    }

    /** استعادة نسخة (تتوقف قاعدة البيانات قبل الاستبدال). */
    public function restore(string $name): bool
    {
        $path = $this->backupPath($name);
        if ($path === null) {
            return false;
        }

        // نسخة أمان قبل الاستبدال
        $this->create();

        $dbPath = $this->databasePath();
        \App\Core\Database::close();

        foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        return copy($path, $dbPath);
    }

    /**
     * تصدير الإعدادات **مبهمة**.
     *
     * كان يُرجع محتوى ملف الإعدادات كاملاً — بما فيه admin.password_hash
     * (تجزئة bcrypt قابلة للكسر بلا اتصال) و app_key (سر طويل العمر) —
     * رغم أن تعليق المسار وصفه بـ«نسخة مبهمة». الآن تُحذف الأسرار صراحةً.
     *
     * نستخدم app_config_file() لا storagePath()، وإلا أعاد هذا التصدير
     * ملفًا فارغًا على التثبيتات التي نفّذت توصية المشروع نفسها
     * (نقل البيانات خارج جذر الموقع).
     */
    public function exportSettings(): string
    {
        $configFile = app_config_file();
        if (!is_file($configFile)) {
            return '';
        }

        $config = require $configFile;
        if (!is_array($config)) {
            return '';
        }

        // أسرار لا تُصدَّر أبدًا.
        unset($config['admin']['password_hash'], $config['app_key'], $config['admin_password_hash']);

        $out  = "<?php\n";
        $out .= "// نسخة مبهمة من إعدادات النسخة — بلا تجزئة كلمة المرور ولا مفتاح التطبيق.\n";
        $out .= "// أُنتجت في " . date('Y-m-d H:i:s') . "\n\nreturn ";
        $out .= var_export($config, true) . ";\n";
        return $out;
    }
}