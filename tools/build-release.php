<?php

declare(strict_types=1);

/**
 * بناء حزمة الإنتاج — dist/
 *
 *   php tools/build-release.php            # بناء + فحص
 *   php tools/build-release.php --check    # فحص فقط، بلا كتابة
 *
 * المخرج: dist/light-commerce-<الإصدار>/
 *   - ملفات الإنتاج فقط (حدّها في src/Config/ReleaseManifest.php)
 *   - RELEASE.txt يسرد المحتوى والمستبعَد وسبب كل استبعاد
 *
 * لا يُشحن شيء خارج القائمة: الافتراضي مُغلق، لأن الخطأ في اتجاه واحد —
 * نسيان ملف بيانات ونشره على الإنترنت أخطر بكثير من نسيان ملف تطوير.
 *
 * الأداة لا تحذف شيئًا خارج dist/، ولا تلمس بيانات النسخة.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("أداة سطر الأوامر تعمل من الطرفية فقط.\n");
}

require_once __DIR__ . '/../src/Core/Autoloader.php';

use App\Config\ProjectConfig;
use App\Config\ReleaseManifest;

$checkOnly = in_array('--check', $argv, true);
$say       = static function (string $m, string $t = 'info'): void {
    $c = ['info' => "\033[36m", 'ok' => "\033[32m", 'warn' => "\033[33m", 'err' => "\033[31m"][$t] ?? '';
    fwrite(STDOUT, $c . $m . "\033[0m\n");
};

$source = BASE_PATH;
$name   = 'light-commerce-' . ProjectConfig::VERSION;
$dist   = $source . '/dist';
$out    = $dist . '/' . $name;

/* ------------------------------------------------------------------ */
/* ١. جمع الملفات                                                     */
/* ------------------------------------------------------------------ */

$files = ReleaseManifest::files($source);

if ($files === []) {
    $say('لم يُعثر على أي ملف — تحقّق من مسار المشروع.', 'err');
    exit(1);
}

$bytes = 0;
foreach ($files as $rel) {
    // ملفات .gitkeep يولّدها البيان نفسه ولا وجود لها في المصدر:
    // هي وسيلة إنشاء مجلد فارغ في الحزمة، والمجلد لا يسافر في git.
    if (basename($rel) !== '.gitkeep') {
        $bytes += (int) @filesize($source . '/' . $rel);
    }
}

$say('الإصدار            : ' . ProjectConfig::VERSION . '  (' . ProjectConfig::RELEASED_AT . ')');
$say('الملفات المُنسخة  : ' . count($files));
$say('الحجم              : ' . number_format($bytes / 1024, 1) . ' ك.ب');
$say('المخرج             : dist/' . $name);

if ($checkOnly) {
    $say('');
    $say('وضع الفحص فقط — لم يُكتب شيء.', 'ok');
    exit(0);
}

/* ------------------------------------------------------------------ */
/* ٢. تنظيف dest ثم النسخ                                              */
/* ------------------------------------------------------------------ */

// نمسح مجلد الإصدار هذا فقط. dist/ والأ-II من محتوياته لا يُمسان.
if (is_dir($out)) {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($out);
}
if (!is_dir($out) && !mkdir($out, 0775, true) && !is_dir($out)) {
    $say('تعذّر إنشاء ' . $out, 'err');
    exit(1);
}

$copied = 0;
foreach ($files as $rel) {
    $dest = $out . '/' . $rel;
    $dir  = dirname($dest);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        $say('تعذّر إنشاء ' . $dir, 'err');
        exit(1);
    }
    if (basename($rel) === '.gitkeep') {
        // مجلد فارغ معلَن: ننشئ الملف لا ننسخه
        file_put_contents($dest, '');
        $copied++;
        continue;
    }
    if (!copy($source . '/' . $rel, $dest)) {
        $say('تعذّر نسخ ' . $rel, 'err');
        exit(1);
    }
    $copied++;
}

// المجلدات الفارغة التي يحتاجها التثبيت (storage/{config,database,…})
foreach (['config', 'database', 'backups', 'logs', 'tmp', 'uploads'] as $sub) {
    @mkdir($out . '/storage/' . $sub, 0775, true);
    file_put_contents($out . '/storage/' . $sub . '/.gitkeep', '');
}
foreach (ReleaseManifest::CONTENTLESS_DIRS as $dir) {
    @mkdir($out . '/' . $dir, 0775, true);
    file_put_contents($out . '/' . $dir . '/.gitkeep', '');
}
// app/uploads/products هوWhere ترفع صور المنتجات
@mkdir($out . '/app/uploads/products', 0775, true);

$say('نُسخ ' . $copied . ' ملف.', 'ok');

/* ------------------------------------------------------------------ */
/* ٣. RELEASE.txt — بيان الحزمة                                       */
/* ------------------------------------------------------------------ */

$grouped = [];
foreach ($files as $rel) {
    $grouped[explode('/', $rel)[0]][] = $rel;
}
ksort($grouped);

$lines = [];
$lines[] = 'القالب التجاري الخفيف — حزمة الإنتاج';
$lines[] = str_repeat('=', 62);
$lines[] = '';
$lines[] = 'الإصدار   : ' . ProjectConfig::VERSION;
$lines[] = 'التاريخ   : ' . ProjectConfig::RELEASED_AT;
$lines[] = 'الملفات   : ' . count($files);
$lines[] = 'الحجم     : ' . number_format($bytes / 1024, 1) . ' ك.ب';
$lines[] = '';
$lines[] = str_repeat('-', 62);
$lines[] = 'التركيب';
$lines[] = str_repeat('-', 62);
$lines[] = '';
$lines[] = '١) ارفع محتويات هذا المجلد إلى جذر موقعك.';
$lines[] = '٢) انسخ deploy/nginx/site.conf إلى إعدادات nginx، واضبط server_name';
$lines[] = '   و root و fastcgi_pass على بيئتك. الملف هو المرجع الوحيد للحماية:';
$lines[] = '   لا ملفات .htaccess في القالب، ولا دعم لـ Apache.';
$lines[] = '٣) امنح الصلاحيات:';
$lines[] = '       chown -R www-data:www-data storage app/uploads';
$lines[] = '       chmod -R 775 storage app/uploads';
$lines[] = '٤) افتح  https://نطاقك/setup/  وأكمل التهيئة.';
$lines[] = '٥) بعد نجاح التهيئة احذف مجلد setup/ من الخادم.';
$lines[] = '';
$lines[] = str_repeat('-', 62);
$lines[] = 'ما بداخل الحزمة';
$lines[] = str_repeat('-', 62);
$lines[] = '';
foreach ($grouped as $top => $items) {
    $label = is_dir($source . '/' . $top) ? $top . '/' : $top;
    $lines[] = sprintf('  %-16s %d ملف', $label, count($items));
}
$lines[] = '';
$lines[] = str_repeat('-', 62);
$lines[] = 'ما لم يُنشر — ولماذا';
$lines[] = str_repeat('-', 62);
$lines[] = '';
foreach (ReleaseManifest::exclusions() as $path => $why) {
    $lines[] = sprintf('  %-14s %s', $path . '/', $why);
}
foreach (ReleaseManifest::EXCLUDE_FILES as $file) {
    $lines[] = sprintf('  %-14s %s', $file, 'ملف تطوير أو توثيق — ليس من القالب المُشحن');
}
$lines[] = '';
$lines[] = 'بيانات النسخة (قاعدة، إعدادات دخول، صور، نسخ) لا تُنسخ أبدًا:';
$lines[] = 'تُولَّد على الخادم عند التهيئة، ولا يجوز أن تنتقل من نسخة إلى أخرى.';
$lines[] = '';
$lines[] = str_repeat('=', 62);
$lines[] = 'فحص ما لم يُنسخ صالح: php tools/deploy-check.php';

file_put_contents($out . '/RELEASE.txt', implode("\n", $lines) . "\n");

/* ------------------------------------------------------------------ */
/* ٤. فحص الحزمة قبل تسليمها                                         */
/* ------------------------------------------------------------------ */

$checker = $source . '/tools/deploy-check.php';
if (is_file($checker)) {
    $say('');
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($checker) . ' ' . escapeshellarg($out), $code);
    exit($code);
}

$say('');
$say('تم البناء.', 'ok');
exit(0);
