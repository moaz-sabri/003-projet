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
 * يُبحث بالترتيب:
 *  1) متغير البيئة LC_CONFIG_FILE — تستخدمه الاختبارات لتوجيه بيانات دخول
 *     مؤقتة إلى مجلد مؤقت، فلا تُلمس بيانات النسخة الحقيقية إطلاقًا.
 *  2) ‎private-config/local.php على مستوى المجلد الأعلى من مجلد الموقع
 *     (أي خارج جذر الويب إن كان المشروع داخل public_html).
 *  3) storage/config/local.php — الوضع الافتراضي.
 *
 * الخيار ٢ هو الحل الوحيد للاستضافة المشتركة التي لا تتيح تعديل إعدادات
 * nginx، لأنه ينقل الملف الحسّاس خارج جذر الويب فلا يعتمد على أي قاعدة
 * تحجب المسار. راجع tools/move-outside-webroot.php.
 */
function app_config_file(): string
{
    $override = getenv('LC_CONFIG_FILE');
    if (is_string($override) && $override !== '' && is_file($override)) {
        return $override;
    }

    // خارج جذر الويب: ../private-config/local.php
    $outside = dirname(rtrim(BASE_PATH, '/\\')) . '/private-config/local.php';
    if (is_file($outside)) {
        return $outside;
    }

    return BASE_PATH . '/storage/config/local.php';
}

$appConfigFile = app_config_file();

/**
 * عدد المستويات التي يجب صعودها من مجلد السكربت الحالي ليصل إلى جذر الموقع.
 *
 * يُستخدم لبناء رابط صفحة التهيئة من أي سكربت، فلا ينكسر التوجيه على
 * جذر النطاق ولا داخل مجلد فرعي ولا على مشروع وُضع في مجلد اسمه «admin».
 *
 * ## لماذا ليست قاعدة "عدد المجلدات"؟
 *
 * لأن SCRIPT_NAME وحده لا يحسمها: في تثبيت على /shop/ يكون
 * /shop/index.php هو الجذر (0 مستويات) بينما /shop/api/index.php داخله
 * (مستوى واحد). وعدّ الأجزاء يعطي 1 في الأولى — فيقفز التوجيه فوق
 * جذر المشروع ويطلب /setup/index.php بدل /shop/setup/index.php.
 *
 * ## القاعدة
 *
 * كل نقطة دخول في القالب إما index.php على الجذر، وإما في مجلد واحد
 * من مجلداته المعروفة (admin · api · setup · bin · tools · tests).
 * فنصعد مستوى واحدًا إن كان المجلد الأخير من مسار السكربت موجودًا فعلًا
 * داخل جذر المشروع على القرص، وإلا فالسكربت على الجذر مباشرة.
 *
 * @return int 0 أو 1
 */
function setupLinkLevelsUp(): int
{
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === '' || $script[0] !== '/') {
        return 0; // CLI أو إعداد غير معتاد — الجذر
    }

    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '' || $dir === '/') {
        return 0;
    }

    return is_dir(BASE_PATH . '/' . basename($dir)) ? 1 : 0;
}

/*
 * الثابت LC_IN_SETUP تعرّفه صفحة التهيئة نفسها قبل تحميل هذا الملف:
 * هي الوجهة الوحيدة التي يوجّه إليها هذا الملف عند غياب الإعدادات، فتحتاج
 * أن تحمّله دون أن تحوّل إلى نفسها.
 */
if (!is_file($appConfigFile)) {
    if (PHP_SAPI !== 'cli' && !defined('LC_IN_SETUP')) {
        /*
         * توجيه إلى معالج التهيئة بدل أي خطأ غامض.
         *
         * المسار يجب أن يكون مطلقًا لا نسبيًا: التوجيه النسبي
         * ("setup/index.php") يُفسّره المتصفح نسبةً إلى مجلد السكربت
         * الحالي، فمن /admin/index.php صار إلى /admin/setup/index.php
         * أي 404. ولم يظهر الخلل إلا لأن غياب الإعدادات كان نادرًا —
         * أما الآن فقد صار حالة طبيعية متكررة: بعد تدمير النسخة (انظر
         * App\Services\InstanceService) يعود الموقع إلى هنا عمدًا.
         */
        if (!headers_sent()) {
            header('Location: ' . App\Core\Web\Paths::url('setup/index.php', setupLinkLevelsUp()));
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