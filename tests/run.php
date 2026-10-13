<?php

declare(strict_types=1);

/**
 * مُشغّل الاختبارات — يغطي معايير القبول في readme §13
 *
 * التشغيل:   php tests/run.php
 * مع فلتر:  php tests/run.php orders
 */

if (PHP_SAPI !== 'cli') {
    exit("الاختبارات تعمل من الطرفية فقط.\n");
}

define('BASE_PATH', dirname(__DIR__));
define('APP_START', microtime(true));

$filter = $argv[1] ?? '';

// قاعدة بيانات منفصلة للاختبارات (لا نلمس بيانات النسخة)
$testDb = sys_get_temp_dir() . '/lc-test-' . getmypid() . '.sqlite';
@unlink($testDb);

$config = [
    'installed_at' => date('c'),
    'debug'        => true,
    'app_key'      => bin2hex(random_bytes(16)),
    'app'          => ['base_url' => 'https://test.local'],
    'db'           => ['path' => $testDb],
    'admin'        => [
        'username'      => 'tester',
        'password_hash' => password_hash('TestPass123!', PASSWORD_DEFAULT),
    ],
];

$configDir = sys_get_temp_dir() . '/lc-test-config-' . getmypid();
@mkdir($configDir, 0775, true);
$configFile = $configDir . '/local.php';
file_put_contents($configFile, "<?php\nreturn " . var_export($config, true) . ";\n");

// نضع ملف الإعدادات مؤقتًا في مكان المتوقّع حتى يعمل bootstrap كما في الإنتاج
$realConfig = BASE_PATH . '/storage/config/local.php';
$realExists = is_file($realConfig);
if ($realExists) {
    copy($realConfig, $configFile . '.backup');
    @unlink($realConfig);
}
copy($configFile, $realConfig);

require BASE_PATH . '/src/bootstrap.php';
require BASE_PATH . '/tests/Assert.php';

use App\Core\Database;
use App\Core\Migrations;

Migrations::migrate();

// -----------------------------------------------------------------------------
// بيانات اختبار: تصنيفان، ثلاثة منتجات، موقعان
// -----------------------------------------------------------------------------
$cat1 = Database::insert('categories', ['name' => 'الساخنة', 'sort_order' => 10, 'is_active' => 1]);
$cat2 = Database::insert('categories', ['name' => 'الباردة', 'sort_order' => 20, 'is_active' => 1]);

$coffee = Database::insert('products', [
    'category_id' => $cat1, 'name' => 'قهوة', 'description' => 'وصف قصير',
    'price_minor' => 10000, 'is_available' => 1, 'extra_json' => '{"vegetarian":1}',
]);
$tea = Database::insert('products', [
    'category_id' => $cat2, 'name' => 'شاي', 'description' => 'شاي بالنعناع',
    'price_minor' => 5000, 'is_available' => 1,
]);
$juice = Database::insert('products', [
    'category_id' => $cat2, 'name' => 'عصير', 'description' => str_repeat('طازج وطازج وطازج ', 15),
    'price_minor' => 15000, 'is_available' => 1,
    'extra_json'  => '{"ingredients":"برتقال"}',
]);

$loc1 = Database::insert('locations', ['code' => 'T01', 'name' => 'طاولة 1', 'is_active' => 1, 'sort_order' => 10]);
$loc2 = Database::insert('locations', ['code' => 'T02', 'name' => 'طاولة 2', 'is_active' => 1, 'sort_order' => 20]);

$files = glob(BASE_PATH . '/tests/suites/*.php');
sort($files);
foreach ($files as $file) {
    $suite = require $file;
    if (is_callable($suite)) {
        if ($filter !== '' && !str_contains(strtolower(basename($file)), strtolower($filter))) {
            continue;
        }
        $suite();
    }
}

// -----------------------------------------------------------------------------
echo "\n" . str_repeat('─', 62) . "\n";
if (Assert::$failed === 0) {
    echo "\033[32m\033[1m  نجحت جميع الاختبارات: " . Assert::$passed . "\033[0m\n";
} else {
    echo "\033[31m\033[1m  فشل " . Assert::$failed . " من " . (Assert::$passed + Assert::$failed) . "\033[0m\n";
    foreach (Assert::$failures as $failure) {
        echo "   • " . $failure . "\n";
    }
}
echo "  الوقت: " . round((microtime(true) - APP_START) * 1000) . " ميلي ثانية\n";
echo str_repeat('─', 62) . "\n";

// تنظيف: نستعيد إعداد النسخة الحقيقية أولًا، ثم نحذف ملف الاختبار
if ($realExists && is_file($configFile . '.backup')) {
    copy($configFile . '.backup', $realConfig);
}

@unlink($testDb);
@unlink($configFile);
@unlink($configFile . '.backup');
@rmdir($configDir);

exit(Assert::$failed === 0 ? 0 : 1);