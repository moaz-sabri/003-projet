<?php
/**
 * فحص سريع لكلمة مرور المدير في بيئة معيّنة.
 * الاستخدام: php tools/check-admin-pass.php [كلمة_المرور]
 */

declare(strict_types=1);

require __DIR__ . '/../src/Core/Autoloader.php';

$config = require app_config_file();
$hash   = (string) ($config['admin']['password_hash'] ?? '');
$dbPath = (string) ($config['db']['path'] ?? '');

$candidates = array_slice($argv, 1);
if ($candidates === []) {
    $envPass  = getenv('LC_ADMIN_PASS');
    $candidates = [
        is_string($envPass) && $envPass !== '' ? $envPass : 'Test1234',
        'Test1234',
        'Test1234!',
    ];
}

echo 'المستخدم: ' . ($config['admin']['username'] ?? '?') . PHP_EOL;
echo 'قاعدة البيانات: ' . $dbPath . PHP_EOL;

foreach ($candidates as $candidate) {
    printf("  %-14s => %s%s", $candidate, password_verify($candidate, $hash) ? 'صحيح' : 'غير صحيح', PHP_EOL);
}

if (is_file($dbPath)) {
    try {
        $attempts = (int) \App\Core\Database::value('SELECT COUNT(*) FROM login_attempts');
        echo 'محاولات دخول فاشلة مسجّلة: ' . $attempts . PHP_EOL;
    } catch (Throwable $e) {
        echo 'تعذّرت قراءة جدول المحاولات: ' . $e->getMessage() . PHP_EOL;
    }
}