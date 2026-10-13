<?php

declare(strict_types=1);

/**
 * فحص نشر سريع — يتحقق من أي نسخة تعمل فعلًا (محلي أو على استضافة).
 *
 *   php tests/http/check.php http://localhost:8080
 *   php tests/http/check.php https://mysite.com
 *
 * يغطي: الكتالوج، إنشاء الطلب، منع التكرار، متابعة الطلب، حماية المسارات،
 *       وحاجزية لوحة الإدارة. لا يحتاج قاعدة بيانات ولا صلاحيات.
 */

if (PHP_SAPI !== 'cli') {
    exit("هذا الفحص يعمل من الطرفية فقط.\n");
}

$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$pass = 0;
$fail = 0;

/**
 * @return array{status:int, json:?array, raw:string}
 */
function req(string $url, ?array $body = null, array $headers = []): array
{
    $context = stream_context_create(['http' => [
        'method'        => $body === null ? 'GET' : 'POST',
        'header'        => implode("\r\n", array_merge(['Content-Type: application/json'], $headers)),
        'content'       => $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE),
        'timeout'       => 20,
        'ignore_errors' => true,
    ]]);

    $raw    = (string) @file_get_contents($url, false, $context);
    $status = 0;
    $headers = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        }
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    return ['status' => $status, 'json' => json_decode($raw, true), 'raw' => $raw, 'headers' => $headers];
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  \033[32m✓\033[0m " . $label . "\n";
    } else {
        $fail++;
        echo "  \033[31m✗\033[0m " . $label . ($detail ? "\n      \033[31m" . $detail . "\033[0m" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n\033[1m" . $title . "\033[0m\n";
}

echo "فحص النشر على: " . $base . "\n" . str_repeat('─', 58) . "\n";

/* ------------------------------- عام ------------------------------- */
section('١. الملفات العامة');

$home = req($base . '/app/index.html');
check('واجهة الزبون تُقدَّم', $home['status'] === 200, 'status=' . $home['status']);
check('الواجهة عربية واتجاه RTL', str_contains($home['raw'], 'dir="rtl"'));
check('الواجهة تربط ملف manifest', str_contains($home['raw'], 'manifest.webmanifest'));

$sw = req($base . '/app/sw.js');
check('Service Worker متاح', $sw['status'] === 200 && str_contains($sw['raw'], 'addEventListener'));

$manifest = json_decode(req($base . '/app/manifest.webmanifest')['raw'], true);
check('مانيفست PWA صالح', is_array($manifest) && ($manifest['dir'] ?? '') === 'rtl');

/* ----------------------------- الكتالوج ----------------------------- */
section('٢. الكتالوج والطلبات');

$catalog = req($base . '/api/index.php?r=catalog.snapshot');
check('واجهة الكتالوج تعمل', ($catalog['json']['ok'] ?? false) === true, 'status=' . $catalog['status']);

$products  = $catalog['json']['data']['products'] ?? [];
$locations = $catalog['json']['data']['locations'] ?? [];
$config    = $catalog['json']['data']['config'] ?? [];

check('المنتجات معروضة', is_array($products), 'عدد=' . count($products));
check('الأسعار منسّقة', $products !== [] && str_contains((string) ($products[0]['price'] ?? ''), (string) ($config['currency'] ?? 'x')));
check('إعدادات البنية موجودة', count($config['order_types'] ?? []) > 0);

if ($products === []) {
    echo "\n\033[33mلا توجد منتجات — تخطى فحوص الطلبات. أضف منتجًا من لوحة الإدارة ثم أعد الفحص.\033[0m\n";
    exit(0);
}

$productId = $products[0]['id'];
$item      = ['product_id' => $productId, 'quantity' => 1];

/* نوع طلب يفرض الموقع إن وُجد */
$requiresLocation = false;
$orderType = '';
foreach ($config['order_types'] ?? [] as $type) {
    if (!empty($type['requires_location'])) {
        $requiresLocation = true;
        $orderType = $type['key'];
    }
}
if ($orderType === '') {
    $orderType = (string) ($config['order_types'][0]['key'] ?? 'dine_in');
}

$payload = [
    'order_type'    => $orderType,
    'customer_name' => 'فحص نشر آلي',
    'items'         => [$item],
];

if ($requiresLocation) {
    $withoutLocation = req($base . '/api/index.php?r=order.create', $payload);
    check(
        'الخادم يرفض الطلب بدون موقع إلزامي',
        $withoutLocation['status'] === 422,
        'status=' . $withoutLocation['status']
    );
    $payload['location_code'] = $locations[0]['code'] ?? '';
}

$key      = 'deploy-check-' . bin2hex(random_bytes(6));
$headers  = ['Idempotency-Key: ' . $key];
$order    = req($base . '/api/index.php?r=order.create', $payload, $headers);
check('إنشاء طلب ناجح', $order['status'] === 201, 'status=' . $order['status'] . ' ' . substr($order['raw'], 0, 140));

$number = $order['json']['data']['number'] ?? 0;
$token  = $order['json']['data']['tracking_token'] ?? '';
check('الطلب حصل على رقم', $number > 0);

$replay = req($base . '/api/index.php?r=order.create', $payload, $headers);
check(
    'إعادة الإرسال لا تنشئ طلبًا جديدًا',
    ($replay['json']['data']['number'] ?? -1) === $number,
    'رقم=' . ($replay['json']['data']['number'] ?? 'لا يوجد')
);

$track = req($base . '/api/index.php?r=order.status&token=' . $token);
check('متابعة الطلب برمزه تعمل', $track['status'] === 200, 'status=' . $track['status']);

$badToken = req($base . '/api/index.php?r=order.status&token=00000000000000000000000000000000');
check('رمز متابعة غير صالح مرفوض', $badToken['status'] === 404);

/* --------------------------- حماية المسارات --------------------------- */
section('٣. حماية الملفات الحساسة');

$protectedPaths = [
    '/storage/database/app.sqlite',
    '/storage/config/local.php',
    '/storage/backups/',
    '/src/Config/ProjectConfig.php',
    '/src/bootstrap.php',
    '/bin/console',
    '/tests/run.php',
    '/.htaccess',
];

foreach ($protectedPaths as $path) {
    $response = req($base . $path);
    $blocked  = in_array($response['status'], [403, 404], true);
    check('محجوب: ' . $path, $blocked, 'status=' . $response['status']);
}

/* ------------------------------ الإدارة ------------------------------ */
section('٤. حماية لوحة الإدارة');

$admin = req($base . '/admin/');
check('صفحة الإدارة متاحة', $admin['status'] === 200, 'status=' . $admin['status']);
check('صفحة الإدارة مستثناة من الفهرسة', str_contains($admin['raw'], 'noindex'));
check(
    'صفحة الإدارة بدون تخزين مؤقت',
    str_contains($admin['headers']['cache-control'] ?? '', 'no-store'),
    'cache-control=' . ($admin['headers']['cache-control'] ?? 'مفقود')
);
check(
    'لوحة الإدارة ترسل سياسة CSP',
    str_contains($admin['headers']['content-security-policy'] ?? '', "default-src 'self'")
);

foreach (['admin.products', 'admin.orders', 'admin.settings', 'admin.locations'] as $route) {
    $response = req($base . '/api/index.php?r=' . $route);
    check('واجهة ' . $route . ' تتطلب جلسة', $response['status'] === 401, 'status=' . $response['status']);
}

$badLogin = req($base . '/api/index.php?r=admin.login', ['username' => 'admin', 'password' => 'خطأ-متعمد']);
check('دخول بكلمة مرور خاطئة مرفوض', $badLogin['status'] === 401);

/* ------------------------------ الخلاصة ------------------------------ */
echo "\n" . str_repeat('─', 58) . "\n";
if ($fail === 0) {
    echo "\033[32m\033[1m  النشر سليم: " . $pass . " فحص ناجح.\033[0m\n";
} else {
    echo "\033[31m\033[1m  فشل " . $fail . " من " . ($pass + $fail) . "\033[0m\n";
}
exit($fail === 0 ? 0 : 1);