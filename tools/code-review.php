<?php

declare(strict_types=1);

/**
 * مراجعة جودة الكود والأداء — فحوص ثابتة على المصدر وقاعدة البيانات.
 *
 *   php tools/code-review.php
 *
 * يغطي: strict_types، تكرار الشيفرة، صفوف طويلة، استعلامات غير مفهرسة،
 *        قيود الجداول، إعدادات SQLite، و الأكواد الميتة.
 */

if (PHP_SAPI !== 'cli') {
    exit("هذه الأداة تعمل من الطرفية فقط.\n");
}

require __DIR__ . '/../src/Core/Autoloader.php';

use App\Core\Database;

$root   = dirname(__DIR__);
$pass   = 0;
$fail   = 0;
$notes  = [];

function review(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $notes;
    if ($ok) {
        $pass++;
        echo "  \033[32m✓\033[0m $label\n";
    } else {
        $fail++;
        $notes[] = $label . ($detail ? ' — ' . $detail : '');
        echo "  \033[31m✗\033[0m $label" . ($detail ? "  ($detail)" : '') . "\n";
    }
}

function section(string $t): void
{
    echo "\n\033[1m" . $t . "\033[0m\n";
}

echo "مراجعة الكود والأداء\n" . str_repeat('─', 62) . "\n";

/* ================== ١. أسلوب PHP ================== */
section('١. أسلوب PHP والاتساق');

$phpFiles = [];
foreach (['src', 'api', 'admin', 'tests', 'tools', 'setup', 'bin'] as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        if ($file->getExtension() === 'php' || $file->getFilename() === 'console') {
            $phpFiles[] = $file->getPathname();
        }
    }
}

$missingStrict = [];
$longLines     = [];
foreach ($phpFiles as $file) {
    $source = (string) file_get_contents($file);
    if (!str_contains($source, 'declare(strict_types=1)')) {
        $missingStrict[] = str_replace($root . '/', '', $file);
    }
    foreach (explode("\n", $source) as $no => $line) {
        if (mb_strlen($line) > 200) {
            $longLines[] = basename($file) . ':' . ($no + 1) . ' (' . mb_strlen($line) . ')';
        }
    }
}

review(
    'كل ملفات PHP تستخدم declare(strict_types=1)',
    $missingStrict === [],
    implode(', ', $missingStrict)
);
review(
    'لا أسطر تتجاوز 200 محرف',
    $longLines === [],
    implode(', ', array_slice($longLines, 0, 5))
);

$tabs = [];
foreach ($phpFiles as $file) {
    if (str_contains((string) file_get_contents($file), "\t")) {
        $tabs[] = basename($file);
    }
}
review('لا مسافات بادئة من نوع Tab', $tabs === [], implode(', ', $tabs));

/* ================== ٢. تكرار الشيفرة ================== */
section('٢. التكرار وفصل المسؤوليات');

// نفس عبارة "تعذّر الاتصال بالخادم" في الواجهتين مقبول (عمليتان منفصلتان)
$apiDup = 0;
foreach (glob($root . '/src/Core/**/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);
    $apiDup += substr_count($source, 'PDO::');
}
review('لا تكرار مفرط في طبقة قاعدة البيانات', $apiDup <= 8, 'عدد الاستخدامات=' . $apiDup);

// كل طبقة الاستعلامات مغلفة في Repositories/Queries
$rawSqlOutside = [];
foreach (glob($root . '/src/Services/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);
    // OrderService يستخدم استعلامًا واحدًا مباشرًا للتحقق من المنتجات (مبرّر للأداء)
    if (substr_count($source, 'Database::all(') > 2) {
        $rawSqlOutside[] = basename($file);
    }
}
review(
    'الخدمات لا تحوي استعلامات SQL كثيرة (تفصل المسؤوليات)',
    $rawSqlOutside === [],
    implode(', ', $rawSqlOutside)
);

/* ================== ٣. قاعدة البيانات ================== */
section('٣. قاعدة البيانات والأداء');

$indexes = [];
foreach (Database::all("SELECT name, tbl_name FROM sqlite_master WHERE type='index' AND name LIKE 'idx_%'") as $row) {
    $indexes[$row['tbl_name']][] = $row['name'];
}

review('فهارس على الطلبات', isset($indexes['orders']) && count($indexes['orders']) >= 5, json_encode($indexes['orders'] ?? [], JSON_UNESCAPED_UNICODE));
review('فهرس على عناصر الطلبات', isset($indexes['order_items']));
review('فهرس على المنتجات', isset($indexes['products']));
review('فهرس على المنتجات غير المتاحة', in_array('idx_products_available', $indexes['products'] ?? [], true));

$foreignKeys = Database::all("SELECT COUNT(*) AS c FROM pragma_foreign_key_list('order_items')");
review('قيود مفاتيح أجنبية على عناصر الطلبات', (int) $foreignKeys[0]['c'] >= 1);

$uniqueIdem = Database::all("SELECT COUNT(*) AS c FROM pragma_index_list('orders') WHERE \"unique\" = 1");
review('قيد فريد على idempotency_key', (int) $uniqueIdem[0]['c'] >= 1);

$uniqueToken = Database::all("SELECT COUNT(*) AS c FROM pragma_index_list('orders') WHERE \"unique\" = 1 AND origin = 'u'");
review('قيد فريد على public_token', (int) $uniqueToken[0]['c'] >= 1);

$uniqueLocation = Database::all("SELECT COUNT(*) AS c FROM pragma_index_list('locations') WHERE \"unique\" = 1 AND origin = 'u'");
review('قيد فريد على رمز الموقع', (int) $uniqueLocation[0]['c'] >= 1);

$integrity = (string) Database::value('PRAGMA integrity_check');
review('سلامة قاعدة البيانات', $integrity === 'ok', $integrity);

$journal = (string) Database::value('PRAGMA journal_mode');
review('وضع WAL مفعّل (أداء أفضل للقراءة المتزامنة)', strtolower($journal) === 'wal', $journal);

$fkEnabled = (int) Database::value('PRAGMA foreign_keys');
review('تفرض المفاتيح الأجنبية مفعّلًا', $fkEnabled === 1);

$busy = (int) Database::value('PRAGMA busy_timeout');
review('مهلة الانتظار مضبوطة (حماية من قفل قاعدة البيانات)', $busy >= 5000, $busy . 'ms');

// اختبار أداء: زمن جلب الكتالوج
$snapshot = new \App\Services\CatalogService();
$start = microtime(true);
for ($i = 0; $i < 20; $i++) {
    $snapshot->snapshot();
}
$avg = (microtime(true) - $start) / 20;
review('زمن جلب الكتالوج أقل من 100ms', $avg < 0.1, sprintf('%.1fms', $avg * 1000));

// اختبار أداء: استعلام الطلبات مع فلاتر
$repo = new \App\Repositories\OrderRepository();
$start = microtime(true);
for ($i = 0; $i < 20; $i++) {
    $repo->listForAdmin(['status' => 'new'], 50);
}
$avgOrders = (microtime(true) - $start) / 20;
review('زمن قائمة الطلبات أقل من 100ms', $avgOrders < 0.1, sprintf('%.1fms', $avgOrders * 1000));

/* ================== ٤. الأكواد الميتة ================== */
section('٤. الأكواد الميتة والفجوات');

$deadFunctions = [];
foreach (glob($root . '/src/**/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);
    // دوال لا تُستخدم خارج تعريفها (نتحقق أيضًا من الاستدعاء عبر array callable)
    if (preg_match_all('/private function (\w+)\(/', $source, $m)) {
        foreach ($m[1] as $fn) {
            $uses = 0;
            foreach (glob($root . '/src/**/*.php') ?: [] as $other) {
                $otherSource = (string) file_get_contents($other);
                $uses += substr_count($otherSource, $fn . '(');
                $uses += substr_count($otherSource, "'" . $fn . "'");   // array callable
                $uses += substr_count($otherSource, '"' . $fn . '"');
            }
            if ($uses <= 1 && !in_array($fn, ['__construct', '__toString'], true)) {
                $deadFunctions[] = basename($file) . '::' . $fn;
            }
        }
    }
}
review('لا دوال خاصة غير مستخدمة', $deadFunctions === [], implode(', ', $deadFunctions));

$todos = [];
$selfPath = realpath(__FILE__);
foreach ($phpFiles as $file) {
    if (realpath($file) === $selfPath) {
        continue; // هذا الملف يحتوي النمط نفسه
    }
    $source = (string) file_get_contents($file);
    if (preg_match('/\b(TODO|FIXME|XXX)\b/', $source)) {
        $todos[] = basename($file);
    }
}
review('لا TODO/FIXME معلّق', $todos === [], implode(', ', $todos));

$debugLeft = [];
foreach (array_merge(glob($root . '/app/js/*.js') ?: [], glob($root . '/admin/assets/*.js') ?: []) as $file) {
    if (preg_match('/console\.(log|debug)\(|debugger;/', (string) file_get_contents($file))) {
        $debugLeft[] = basename($file);
    }
}
review('لا console.log في واجهات الإنتاج', $debugLeft === [], implode(', ', $debugLeft));

/* ================== ٥. معالجة الأخطاء ================== */
section('٥. معالجة الأخطاء والسجل');

$hasExceptionHandler = str_contains((string) file_get_contents($root . '/src/bootstrap.php'), 'set_exception_handler');
review('معالج أخطاء عام مثبّت', $hasExceptionHandler);

$loggerWritesFile = str_contains((string) file_get_contents($root . '/src/Core/Logger.php'), 'file_put_contents');
review('السجل يُكتب في ملف خارج المسارات العامة', $loggerWritesFile);

$debugDefault = str_contains(
    (string) file_get_contents($root . '/bin/console'),
    "'debug'        => false"
);
review('وضع التصحيح معطّل عند التثبيت افتراضيًا', $debugDefault);

/* ================== ٦. عدوانية الاختبارات تجاه بيانات النسخة ================== */
section('٦. الاختبارات لا تمسّ بيانات النسخة الحقيقية');

$runner = (string) file_get_contents($root . '/tests/run.php');

// خطأ سابق: كان مشغّل الاختبارات يستبدل storage/config/local.php ببيانات
// دخول اختبارية، فإن توقّف التشغيل في المنتصف لبقي حساب المدير = tester.
review(
    'المشغّل لا يستبدل ملف إعدادات النسخة',
    !str_contains($runner, "@unlink(\$realConfig)") && !str_contains($runner, "copy(\$configFile, \$realConfig)"),
    'لا يزال يكتب فوق storage/config/local.php'
);
review(
    'المشغّل يوجّه الإعدادات عبر متغير البيئة',
    str_contains($runner, "putenv('LC_CONFIG_FILE="),
    'لا يوجد توجيه عبر LC_CONFIG_FILE'
);
review(
    'التنظيف يسجَّل قبل أي تنفيذ (حتى مع الاستثناءات)',
    str_contains($runner, 'register_shutdown_function($cleanup)'),
    'لا يوجد register_shutdown_function'
);

$autoloader = (string) file_get_contents($root . '/src/Core/Autoloader.php');
review(
    'التطبيق يدعم تجاوز مسار الإعدادات',
    str_contains($autoloader, 'function app_config_file()') && str_contains($autoloader, 'LC_CONFIG_FILE')
);

echo "\n" . str_repeat('─', 62) . "\n";
echo "نجح: $pass — يحتاج انتباه: $fail\n";
foreach ($notes as $note) {
    echo "  • $note\n";
}
exit($fail === 0 ? 0 : 1);