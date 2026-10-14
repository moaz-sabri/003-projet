<?php

declare(strict_types=1);

/**
 * أداة: نقل بيانات النسخة خارج جذر الويب + إصلاح الصلاحيات.
 *
 *   php tools/move-outside-webroot.php [--yes]
 *
 * لماذا؟
 * ------
 * حماية المجلدات الحسّاسة (قاعدة البيانات + بيانات الدخول) تعتمد على أن
 * يمنع الخادم الوصول إليها. على Apache يمكن عبر .htaccess، لكن nginx لا
 * يقرأ .htaccess إطلاقًا، وكثير من الاستضافات لا تتيح تعديل إعدادات
 * nginx. لذلك الحل الذي لا يعتمد على الخادم هو وضع الملفين خارج جذر الموقع.
 *
 * هذه الأداة:
 *   1) تنقل storage/config/local.php  →  ../private-config/local.php
 *   2) تنقل قاعدة البيانات              →  ../private-data/app.sqlite
 *   3) تحدّث قيمة db.path داخل الملف المنقول
 *   4) تضبط الصلاحيات والملكية ليقرأ مستخدم خادم الويب الملفات
 *
 * ملاحظة: لا تنقل app/uploads لأن صور المنتجات يجب أن تُقدَّم للمتصفح.
 *
 * آمنة للتكرار: إن كانت الملفات منقولة أصلًا لا تنقلها مرتين،
 * لكنها مع ذلك تُصلح الصلاحيات وتتحقق من الحالة.
 */

if (PHP_SAPI !== 'cli') {
    exit("هذه الأداة تعمل من الطرفية فقط.\n");
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

$root       = BASE_PATH;
$parent     = dirname(rtrim($root, '/\\'));
$privateDir = $parent . '/private-config';
$dataDir    = $parent . '/private-data';

$configFile = $root . '/storage/config/local.php';
$outsideConfig = $privateDir . '/local.php';
$dbPath     = $root . '/storage/database/app.sqlite';
$targetDb   = $dataDir . '/app.sqlite';

$assumeYes = in_array('--yes', $argv, true);

echo "══════════════════════════════════════════════════════════════\n";
echo "نقل بيانات النسخة خارج جذر الويب\n";
echo "══════════════════════════════════════════════════════════════\n\n";

echo "مجلد المشروع : {$root}\n";
echo "الهدف        : {$parent}\n\n";

/* ------------------------- 1) فحص الحالة ------------------------- */

/** هل يقع المسار داخل جذر الموقع؟ (ليس مجرّد بداية نص) */
$insideWebRoot = static function (string $path) use ($root): bool {
    $real   = realpath($path) ?: $path;
    $prefix = str_replace('\\', '/', (realpath($root) ?: $root)) . '/';
    return str_starts_with(str_replace('\\', '/', $real), $prefix);
};

$configAtOutside = is_file($outsideConfig);
$configAtInside  = !$configAtOutside && is_file($configFile);

if (!$configAtOutside && !$configAtInside) {
    echo "✗ لا يوجد ملف إعدادات. شغّل التثبيت أولًا: php bin/console install\n";
    exit(1);
}

$activeConfig  = $configAtOutside ? $outsideConfig : $configFile;
$config        = require $activeConfig;
$currentDb     = (string) ($config['db']['path'] ?? $dbPath);
$dbAtOutside   = !$insideWebRoot($currentDb) || is_file($targetDb);
$dbAtInside    = !$dbAtOutside && is_file($dbPath);

$needsMove = $configAtInside || $dbAtInside;

printf("ملف الإعدادات : %s\n", $configAtOutside ? 'خارج ✔' : 'داخل الموقع ⚠');
printf("قاعدة البيانات : %s\n", $dbAtOutside ? 'خارج ✔' : 'داخل الموقع ⚠');
echo "\n";

/* --------------------------- 2) الخطة --------------------------- */

if ($needsMove) {
    echo "سيتم التالي:\n";
    if ($configAtInside) {
        echo "  1) إنشاء  {$privateDir}\n";
        echo "  2) نقل    storage/config/local.php  ←  private-config/local.php\n";
    }
    if ($dbAtInside) {
        echo "  3) إنشاء  {$dataDir}\n";
        echo "  4) نقل    قاعدة البيانات  ←  private-data/app.sqlite\n";
    }
    echo "  5) تعديل  قيمة db.path داخل الملف\n";
    echo "  6) ضبط   الصلاحيات ليقرأ مستخدم خادم الويب الملفين\n";
    echo "     (مطلوبة أيضًا لأن SQLite ينشئ ملفات -wal و -shm في مجلده)\n\n";

    if (!$assumeYes) {
        echo "الملفات منقولة جزئيًا أو موجودة داخل الموقع.\n";
        echo "أعد التشغيل مع --yes للتأكيد:\n";
        echo "  php tools/move-outside-webroot.php --yes\n";
        exit(0);
    }

    if (!is_writable($parent) && !is_writable($privateDir) && !is_writable($dataDir)) {
        echo "✗ لا يمكن الكتابة في: {$parent}\n";
        echo "  تأكد من صلاحيات المجلد (755 أو 775) ثم أعد المحاولة.\n";
        exit(1);
    }
} else {
    echo "✓ الملفات خارج جذر الموقع بالفعل — سيجري فقط فحص الصلاحيات.\n\n";
}

/* -------------------------- 3) التنفيذ -------------------------- */

@mkdir($privateDir, 0775, true);
@mkdir($dataDir, 0775, true);

if ($dbAtInside) {
    if (is_file($targetDb)) {
        @unlink($targetDb);
    }
    if (!@rename($dbPath, $targetDb)) {
        echo "✗ تعذّر نقل قاعدة البيانات.\n";
        exit(1);
    }
    foreach (['-wal', '-shm'] as $suffix) {
        $side = $dbPath . $suffix;
        if (is_file($side)) {
            @rename($side, $targetDb . $suffix);
        }
    }
    echo "✓ نُقلت قاعدة البيانات ← {$targetDb}\n";
}

$config['db']['path'] = $targetDb;
$php = "<?php\n"
    . "// إعدادات النسخة — مخزّنة خارج جذر الويب عمدًا.\n"
    . "// على nginx لا يمكن حمايتها داخل الموقع، لذا هي هنا خارج public_html.\n"
    . 'return ' . var_export($config, true) . ";\n";

file_put_contents($outsideConfig, $php);

if ($configAtInside) {
    @unlink($configFile);
    echo "✓ نُقل ملف الإعدادات ← {$outsideConfig}\n";
    echo "✓ أُزيل ملف الإعدادات من داخل المشروع\n";
}

// 4) الصلاحيات والملكية
$isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
$webUser = 'www-data';

if ($isRoot) {
    $info = function_exists('posix_getpwuid') ? @posix_getpwuid(33) : false;
    if (is_array($info) && isset($info['name'])) {
        $webUser = $info['name'];
    }
    foreach ([$outsideConfig, $targetDb] as $f) {
        if (is_file($f)) {
            @chown($f, $webUser);
        }
    }
    // المجلد أيضًا: SQLite ينشئ -wal و -shm بداخله ويحتاج صلاحية كتابة
    foreach ([$privateDir, $dataDir] as $d) {
        if (is_dir($d)) {
            @chown($d, $webUser);
            @chmod($d, 0775);
        }
    }
    @chmod($outsideConfig, 0640);
    @chmod($targetDb, 0664);
    echo "✓ ضُبطت الملكية على {$webUser} والصلاحيات (تشغيل كـ root)\n";
} else {
    @chmod($privateDir, 0775);
    @chmod($dataDir, 0775);
    @chmod($outsideConfig, 0640);
    @chmod($targetDb, 0664);
    echo "✓ ضُبطت الصلاحيات (المستخدم الحالي)\n";
}

/* -------------------------- 4) التحقق -------------------------- */

$readable = is_readable($outsideConfig) && is_file($targetDb);

echo "\n" . str_repeat('─', 62) . "\n";

if ($readable) {
    echo "الحالة الآن: الإعدادات وقاعدة البيانات خارج جذر الموقع.\n\n";
    echo "تحقّق الآن:\n";
    echo "  1) افتح هذا الرابط في المتصفح — يجب أن يظهر «ممنوع» أو «غير موجود»:\n";
    echo "     /storage/database/app.sqlite\n";
    echo "  2) شغّل: php bin/console security:check\n";
    echo "  3) تأكّد أن الموقع ولوحة الإدارة يعملان كالمعتاد.\n";
} else {
    echo "⚠ تعذّر قراءة الملف المنقول — راجع صلاحيات المجلد " . $parent . "\n";
}

echo str_repeat('─', 62) . "\n";

exit($readable ? 0 : 1);