<?php

declare(strict_types=1);

/**
 * نقطة الدخول الوحيدة لواجهة الـ API.
 * كل المسارات تمر من هنا، وكل الأخطاء تُحوَّل إلى JSON منظّم.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Http\HttpError;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Logger;

$request = Request::capture();

// منع الوصول المباشر لأي مسار غير معروف في الـ API
$router = new Router();

require __DIR__ . '/routes/public.php';
require __DIR__ . '/routes/admin.php';

header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

try {
    $router->dispatch($request);
} catch (HttpError $e) {
    if ($e->status() === 419 || $e->status() === 401) {
        header('Cache-Control: no-store');
    }
    Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->extra());
} catch (\PDOException $e) {
    Logger::error('Database error: ' . $e->getMessage());
    Response::error('database_error', 'حدث خطأ في قاعدة البيانات.', 500);
} catch (\Throwable $e) {
    Logger::error('API error: ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
    Response::error('server_error', 'حدث خطأ غير متوقع.', 500);
}