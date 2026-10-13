<?php

declare(strict_types=1);

/**
 * مُحمّل تلقائي بسيط (PSR-4): App\ → src/
 * يُستخدم من أدوات الطرفية قبل تحميل bootstrap.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file     = BASE_PATH . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

date_default_timezone_set(App\Config\ProjectConfig::TIMEZONE);
mb_internal_encoding('UTF-8');

/**
 * مسار ملف الإعدادات المحلي.
 *
 * يمكن تجاوزه بمتغير البيئة LC_CONFIG_FILE — تستخدمه الاختبارات لتوجيه
 * بيانات دخول مؤقتة إلى مجلد مؤقت، فلا تُلمس بيانات الدخول الحقيقية إطلاقًا.
 */
function app_config_file(): string
{
    $override = getenv('LC_CONFIG_FILE');
    if (is_string($override) && $override !== '' && is_file($override)) {
        return $override;
    }

    return BASE_PATH . '/storage/config/local.php';
}

$appConfigFile = app_config_file();

if (!is_file($appConfigFile)) {
    if (PHP_SAPI !== 'cli') {
        // توجيه إلى معالج التهيئة بدل أي خطأ غامض
        if (!headers_sent()) {
            header('Location: setup/index.php');
        }
        echo '<!DOCTYPE html><meta charset="utf-8"><title>التهيئة</title>'
           . '<p style="font-family:sans-serif;direction:rtl;text-align:center;padding:40px">'
           . 'النسخة غير مهيأة بعد. افتح <a href="setup/index.php">صفحة التهيئة</a>.</p>';
        exit;
    }
} else {
    require_once $appConfigFile;
}

/** إعدادات التشغيل المحلية (أسرار ومسارات) — لا تُوضع في أي ملف عام. */
function app_config(?string $key = null, mixed $default = null): mixed
{
    static $config = null;
    if ($config === null) {
        $file = app_config_file();
        $config = is_file($file) ? require $file : [];
    }
    if ($key === null) {
        return $config;
    }
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}