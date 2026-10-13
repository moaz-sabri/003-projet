<?php

declare(strict_types=1);

/**
 * اختبار النسخ الاحتياطي والاستعادة — يتحقق أن النسخة لقطة سليمة
 * يمكن الاعتماد عليها، وأن الاستعادة تُرجع البيانات كما كانت لحظة النسخ.
 *
 *   php tools/restore-test.php
 */

if (PHP_SAPI !== 'cli') {
    exit("هذه الأداة تعمل من الطرفية فقط.\n");
}

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Database;
use App\Services\BackupService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  \033[32m✓\033[0m $label\n";
    } else {
        $fail++;
        echo "  \033[31m✗\033[0m $label" . ($detail ? "  ($detail)" : '') . "\n";
    }
}

echo "اختبار النسخ الاحتياطي والاستعادة\n" . str_repeat('─', 58) . "\n";

$service = new BackupService();

/* ---------------------- 1. بيانات قبل النسخة ---------------------- */
$beforeCount = (int) Database::value('SELECT COUNT(*) FROM products');
Database::insert('products', [
    'name'         => 'منتج قبل النسخة',
    'price_minor'  => 1000,
    'is_available' => 1,
    'created_at'   => Database::now(),
    'updated_at'   => Database::now(),
]);
$markerBefore = (int) Database::value("SELECT id FROM products WHERE name = 'منتج قبل النسخة'");

/* --------------------------- 2. النسخ --------------------------- */
$backupPath = $service->create();
check('إنشاء النسخة الاحتياطية', is_file($backupPath), basename($backupPath));
check('الملف غير فارغ', filesize($backupPath) > 4096, 'الحجم=' . filesize($backupPath));
// على ويندوز تُتجاهَل صلاحيات chmod، لذا نتحقق منها فقط خارج ويندوز
if (DIRECTORY_SEPARATOR !== '\\') {
    check('صلاحية الملف 600', (fileperms($backupPath) & 0o777) === 0o600, decoct(fileperms($backupPath) & 0o777));
}

/* --------------------- 3. تغييرات بعد النسخة --------------------- */
Database::insert('products', [
    'name'         => 'منتج بعد النسخة',
    'price_minor'  => 2000,
    'is_available' => 1,
    'created_at'   => Database::now(),
    'updated_at'   => Database::now(),
]);
$afterCount = (int) Database::value('SELECT COUNT(*) FROM products');
check('تغيّرت البيانات بعد النسخة', $afterCount === $beforeCount + 2, 'قبل=' . $beforeCount . ' بعد=' . $afterCount);

/* --------------- 4. فحص محتوى النسخة (نقطة زمنية) --------------- */
$verifyPath = sys_get_temp_dir() . '/lc-restore-verify-' . getmypid() . '.sqlite';
copy($backupPath, $verifyPath);

$verify = new PDO('sqlite:' . $verifyPath, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$verify->exec('PRAGMA foreign_keys = ON');

$integrity = (string) $verify->query('PRAGMA integrity_check')->fetchColumn();
check('سلامة قاعدة النسخة (integrity_check)', $integrity === 'ok', $integrity);

$tables = $verify->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
check('النسخة تحتوي كل الجداول', (int) $tables >= 9, 'عدد=' . $tables);

$hasMarker = (int) $verify->query("SELECT COUNT(*) FROM products WHERE name = 'منتج قبل النسخة'")->fetchColumn();
check('النسخة تحتوي البيانات السابقة', $hasMarker === 1);

$hasLaterData = (int) $verify->query("SELECT COUNT(*) FROM products WHERE name = 'منتج بعد النسخة'")->fetchColumn();
check('النسخة لا تحتوي بيانات لاحقة', $hasLaterData === 0, 'عدد=' . $hasLaterData);

$ordersInBackup = (int) $verify->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$ordersLive     = (int) Database::value('SELECT COUNT(*) FROM orders');
check('الطلبات محفوظة في النسخة', $ordersInBackup > 0 && $ordersInBackup <= $ordersLive,
    'في النسخة=' . $ordersInBackup . ' الحي=' . $ordersLive);

/* --------------------- 5. قيود السلامة في السجل --------------------- */
$orphanItems = (int) $verify->query(
    'SELECT COUNT(*) FROM order_items oi LEFT JOIN orders o ON o.id = oi.order_id WHERE o.id IS NULL'
)->fetchColumn();
check('لا عناصر طلبات يتيمة في النسخة', $orphanItems === 0);

/* ---------------------- 6. الاستعادة الفعلية ---------------------- */
$restoreTarget = sys_get_temp_dir() . '/lc-restore-target-' . getmypid() . '.sqlite';
copy($verifyPath, $restoreTarget);
$restored = new PDO('sqlite:' . $restoreTarget, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$restoredCount = (int) $restored->query('SELECT COUNT(*) FROM products')->fetchColumn();
check('الاستعادة تُرجع عدد المنتجات كما كانت', $restoredCount === $beforeCount + 1,
    'المتوقع=' . ($beforeCount + 1) . ' المستعاد=' . $restoredCount);

/* ------------------------- 7. التنظيف ------------------------- */
foreach ([$verifyPath, $restoreTarget, $backupPath] as $file) {
    if (is_file($file)) {
        @unlink($file);
    }
}
Database::delete('products', 'name = :a OR name = :b', ['a' => 'منتج قبل النسخة', 'b' => 'منتج بعد النسخة']);

echo str_repeat('─', 58) . "\n";
echo ($fail === 0 ? "\033[32mنجحت فحوص النسخ الاحتياطي (" . $pass . ")\033[0m\n" : "\033[31mفشل $fail فحص\033[0m\n");
exit($fail === 0 ? 0 : 1);