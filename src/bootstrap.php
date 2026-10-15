<?php

declare(strict_types=1);

/**
 * bootstrap — نقطة الدخول لكل السكربتات على الويب.
 * تضبط التحميل التلقائي، معالجة الأخطاء، ووضع التصحيح.
 */

use App\Core\Logger;

require_once __DIR__ . '/Core/Autoloader.php';

/**
 * طبّق نوع النشاط المختار في صفحة التهيئة قبل أي شيء آخر يقرأ الإعدادات.
 * بهذا تتغيّر التسميات والوضع والخصائص في كل الطلب (الواجهة، الـ API،
 * لوحة الإدارة) بتعديل واحد في جدول settings.
 *
 * آمن قبل التهيئة: يعيد نفسه بصمت إن لم تكن قاعدة البيانات موجودة بعد.
 */
App\Config\ProjectConfig::applyStoredPreset();

$debug = (bool) app_config('debug', false);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
if ($debug) {
    ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');
} else {
    ini_set('log_errors', '1');
}

set_exception_handler(static function (Throwable $e) use ($debug): void {
    Logger::error('Uncaught: ' . $e->getMessage(), [
        'file'  => $e->getFile() . ':' . $e->getLine(),
        'trace' => $debug ? explode("\n", $e->getTraceAsString()) : null,
    ]);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, ($debug ? (string) $e : $e->getMessage()) . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'error' => [
                'code'    => 'server_error',
                'message' => $debug ? $e->getMessage() : 'حدث خطأ في الخادم',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
});

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});