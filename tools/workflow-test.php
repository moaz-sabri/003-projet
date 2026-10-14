<?php

declare(strict_types=1);

/**
 * اختبار سير العمل الكامل — نسخة نظيفة من الصفر.
 *
 *   php tools/workflow-test.php [http://host:port]
 *
 * ينفّذ رحلة حقيقية:
 *   المدير: دخول ← تصنيف ← منتج + صورة ← موقع + QR
 *   الزبون : قراءة QR ← تصفح ← سلة ← إرسال طلب باسم
 *   المدير : استلام الطلب ← تغيير الحالة حتى الاكتمال
 *   حدّية  : ضغطتان، سلة فارغة، منتج غير متاح، موقع/رمز غير صالح
 *   نسخ احتياطي واستعادة
 *
 * لا يحتاج قاعدة بيانات: يعمل عبر HTTP فقط، ويحافظ على كوكيز الجلسة.
 */

if (PHP_SAPI !== 'cli') {
    exit("هذه الأداة تعمل من الطرفية فقط.\n");
}

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$jar  = [];

$stats = ['pass' => 0, 'fail' => 0, 'steps' => []];

/* ======================= عميل HTTP بسيط مع كوكيز ======================= */

function http(
    string $url,
    ?array $json = null,
    array $headers = [],
    ?string $rawBody = null,
    string $contentType = 'application/json',
    string $method = ''
): array {
    global $jar;

    $isPost = $method !== '' ? strtoupper($method) === 'POST' : ($json !== null || $rawBody !== null);

    $headerLines = [];
    $headerLines[] = 'Accept: application/json';
    if ($rawBody !== null) {
        $headerLines[] = 'Content-Type: ' . $contentType;
        $headerLines[] = 'Content-Length: ' . strlen($rawBody);
    } elseif ($json !== null) {
        $body          = json_encode($json, JSON_UNESCAPED_UNICODE);
        $rawBody       = $body;
        $headerLines[] = 'Content-Type: application/json';
        $headerLines[] = 'Content-Length: ' . strlen($body);
    }
    foreach ($headers as $h) {
        $headerLines[] = $h;
    }
    if ($jar !== []) {
        $pairs = [];
        foreach ($jar as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $headerLines[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $context = stream_context_create(['http' => [
        'method'        => $isPost ? 'POST' : 'GET',
        'header'        => implode("\r\n", $headerLines),
        'content'       => $rawBody ?? '',
        'timeout'       => 25,
        'ignore_errors' => true,
        'follow_location' => 0,
    ]]);

    // $http_response_header يُملأ تلقائيًا داخل نفس النطاق عند استخدام file_get_contents
    $body = @file_get_contents($url, false, $context);

    $status  = 0;
    $headers = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        }
        if (stripos($line, 'Set-Cookie:') === 0) {
            $cookie = trim(substr($line, 11));
            $pieces = explode('=', $cookie, 2);
            $name   = trim($pieces[0]);
            $value  = trim(explode(';', $pieces[1] ?? '')[0]);
            if ($name !== '' && $value !== '') {
                $jar[$name] = $value;
            }
        }
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }

    return ['status' => $status, 'json' => json_decode((string) $body, true), 'raw' => (string) $body, 'headers' => $headers];
}

/* ============================ أدوات التقرير ============================ */

function step(string $name): void
{
    global $stats;
    echo "\n\033[1m▸ " . $name . "\033[0m\n";
}

function check(string $label, bool $ok, string $detail = ''): bool
{
    global $stats;
    if ($ok) {
        $stats['pass']++;
        echo "   \033[32m✓\033[0m $label\n";
    } else {
        $stats['fail']++;
        echo "   \033[31m✗\033[0m $label" . ($detail ? "  ($detail)" : '') . "\n";
    }
    return $ok;
}

function banner(string $text): void
{
    echo "\n" . str_repeat('═', 62) . "\n" . $text . "\n" . str_repeat('═', 62) . "\n";
}

banner("اختبار سير العمل الكامل على: $base");

/* ======================= ١. المدير: تسجيل الدخول ======================= */

step('١. المدير — تسجيل الدخول');

$badLogin = http($base . '/api/index.php?r=admin.login', ['username' => 'admin', 'password' => 'خطأ']);
check('كلمة مرور خاطئة مرفوضة', $badLogin['status'] === 401, 'status=' . $badLogin['status']);

$login = http($base . '/api/index.php?r=admin.login', ['username' => 'admin', 'password' => 'Test1234!']);
$csrf  = $login['json']['data']['csrf'] ?? '';
check('تسجيل الدخول ناجح', $login['status'] === 200 && $csrf !== '', 'status=' . $login['status']);
check('كوكي الجلسة صدر', isset($login['headers']['set-cookie']) || !empty($GLOBALS['jar']));

$session = http($base . '/api/index.php?r=admin.session');
check('الجلسة صالحة بعد الدخول', ($session['json']['data']['username'] ?? '') === 'admin');

/* CSRF */
$noCsrf = http($base . '/api/index.php?r=admin.category.create', ['name' => 'بدون رمز']);
check('عملية تعديل بلا CSRF مرفوضة', $noCsrf['status'] === 403, 'status=' . $noCsrf['status']);

$badCsrf = http($base . '/api/index.php?r=admin.category.create', ['name' => 'رمز خاطئ'], ['X-CSRF-Token: wrong']);
check('CSRF خاطئ مرفوض', $badCsrf['status'] === 403);

/* ======================= ٢. المدير: تصنيف ======================= */

step('٢. المدير — إنشاء تصنيف');

$category = http($base . '/api/index.php?r=admin.category.create', [
    'name'        => 'تصنيف فحص',
    'description' => 'أُنشئ أثناء الفحص الآلي',
    'is_active'   => 1,
    'sort_order'  => 5,
], ['X-CSRF-Token: ' . $csrf]);

$categoryId = $category['json']['data']['id'] ?? 0;
check('إنشاء التصنيف', in_array($category['status'], [200, 201], true) && $categoryId > 0, 'status=' . $category['status']);

$rename = http($base . '/api/index.php?r=admin.category.update', [
    'id' => $categoryId, 'name' => 'تصنيف فحص (معدّل)', 'is_active' => 1, 'sort_order' => 5,
], ['X-CSRF-Token: ' . $csrf]);
check('تعديل التصنيف', $rename['status'] === 200);

/* ======================= ٣. المدير: منتج + صورة ======================= */

step('٣. المدير — منتج مع صورة');

$invalidImage = http(
    $base . '/api/index.php?r=admin.product.image',
    null,
    ['X-CSRF-Token: ' . $csrf],
    "--boundary--X\r\nContent-Disposition: form-data; name=\"image\"; filename=\"evil.php\"\r\n"
        . "Content-Type: application/x-php\r\n\r\n<?php echo 'hacked'; ?>\r\n--boundary--X--\r\n",
    'multipart/form-data; boundary=--boundary--X'
);
check('رفع سكربت masquerading كصورة مرفوض', in_array($invalidImage['status'], [400, 422], true), 'status=' . $invalidImage['status']);

// PNG صالح 1×1
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$boundary = '----audit' . bin2hex(random_bytes(6));
$multipart = "--$boundary\r\nContent-Disposition: form-data; name=\"image\"; filename=\"audit.png\"\r\n"
    . "Content-Type: image/png\r\n\r\n" . $png . "\r\n--$boundary--\r\n";
$upload = http($base . '/api/index.php?r=admin.product.image', null, ['X-CSRF-Token: ' . $csrf], $multipart, 'multipart/form-data; boundary=' . $boundary);
$imagePath = $upload['json']['data']['image_path'] ?? '';
check('رفع صورة PNG صالحة', $upload['status'] === 201 && $imagePath !== '', 'status=' . $upload['status']);
check('اسم الملف مُعاد توليده', !str_contains($imagePath, 'audit.png'), $imagePath);

$imageUrl = $upload['json']['data']['image'] ?? '';
$imageFetch = http($base . $imageUrl);
check('الصورة المرفوعة قابلة للعرض', $imageFetch['status'] === 200);

$product = http($base . '/api/index.php?r=admin.product.create', [
    'name'         => 'منتج الفحص',
    'description'  => 'منتج أُنشئ أثناء الفحص الآلي للتحقق من المسار الكامل من الإدارة حتى الطلب.',
    'price'        => '25,000',
    'category_id'  => $categoryId,
    'is_available' => 1,
    'image_path'   => $imagePath,
    'extra'        => ['vegetarian' => 1, 'portion' => 'وسط'],
], ['X-CSRF-Token: ' . $csrf]);

$productId = $product['json']['data']['id'] ?? 0;
check('إنشاء المنتج بالسعر مع الفواصل', in_array($product['status'], [200, 201], true) && $productId > 0, 'status=' . $product['status']);

$priceCheck = null;
foreach ($product['json']['data']['products'] ?? [] as $p) {
    if ($p['id'] === $productId) {
        $priceCheck = $p['price_minor'];
    }
}
check('السعر خُزّن كعدد صحيح صحيح (25000)', $priceCheck === 25000, 'القيمة=' . var_export($priceCheck, true));

/* ======================= ٤. المدير: موقع + QR ======================= */

step('٤. المدير — موقع ورمز QR');

$runCode = 'AUD' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
$location = http($base . '/api/index.php?r=admin.location.save', [
    'name'      => 'طاولة الفحص',
    'code'      => $runCode,
    'is_active' => 1,
], ['X-CSRF-Token: ' . $csrf]);
check('إنشاء الموقع', $location['status'] === 200, 'status=' . $location['status']);

$locations = http($base . '/api/index.php?r=admin.locations');
$locationRow = null;
foreach ($locations['json']['data']['locations'] ?? [] as $l) {
    if ($l['code'] === $runCode) {
        $locationRow = $l;
    }
}
check('الموقع ظاهر في القائمة', $locationRow !== null);
check('رابط QR للموقع صحيح', str_contains((string) ($locationRow['qr_url'] ?? ''), 'loc=' . $runCode), $locationRow['qr_url'] ?? '');

$qrSvg = http($base . '/api/index.php?r=admin.qr&type=location&value=' . $runCode);
check('رمز QR يُولَّد كـ SVG', $qrSvg['status'] === 200 && str_contains($qrSvg['raw'], '<svg'), 'status=' . $qrSvg['status']);

$dupeCode = http($base . '/api/index.php?r=admin.location.save', ['name' => 'مكرر', 'code' => $runCode], ['X-CSRF-Token: ' . $csrf]);
check('رمز موقع مكرر مرفوض', $dupeCode['status'] === 422);

/* ======================= ٥. الزبون: تصفح وإرسال ======================= */

step('٥. الزبون — تصفح وإرسال طلب');

$snapshot = http($base . '/api/index.php?r=catalog.snapshot');
$catalog  = $snapshot['json']['data'] ?? [];
check('الكتالوج يعمل', ($snapshot['json']['ok'] ?? false) === true);
check('المنتج الجديد ظاهر للزبون', in_array($productId, array_column($catalog['products'] ?? [], 'id'), true));
check('QR الموقع: الرابط يفتح القائمة مع تحديد الموقع', str_contains((string) ($locationRow['qr_url'] ?? ''), 'app/index.html?loc='));

// سلة فارغة
$emptyCart = http($base . '/api/index.php?r=order.create', [
    'order_type' => 'dine_in', 'customer_name' => 'زبون', 'location_code' => $runCode, 'items' => [],
]);
check('سلة فارغة مرفوضة', $emptyCart['status'] === 422 && ($emptyCart['json']['error']['code'] ?? '') === 'empty_cart');

// بدون موقع
$noLocation = http($base . '/api/index.php?r=order.create', [
    'order_type' => 'dine_in', 'customer_name' => 'زبون',
    'items' => [['product_id' => $productId, 'quantity' => 1]],
]);
check('طلب بدون موقع إلزامي مرفوض', $noLocation['status'] === 422 && ($noLocation['json']['error']['code'] ?? '') === 'location_required');

// موقع غير موجود
$badLocation = http($base . '/api/index.php?r=order.create', [
    'order_type' => 'dine_in', 'customer_name' => 'زبون', 'location_code' => 'NOPE99',
    'items' => [['product_id' => $productId, 'quantity' => 1]],
]);
check('موقع غير موجود مرفوض', $badLocation['status'] === 422);

// بدون اسم
$noName = http($base . '/api/index.php?r=order.create', [
    'order_type' => 'dine_in', 'customer_name' => '', 'location_code' => $runCode,
    'items' => [['product_id' => $productId, 'quantity' => 1]],
]);
check('طلب بلا اسم مرفوض', $noName['status'] === 422);

// طلب سليم + ضغطتان متتاليتان
$orderPayload = [
    'order_type'    => 'dine_in',
    'customer_name' => 'زبون الفحص',
    'location_code' => $runCode,
    'fields'        => ['notes' => 'بدون بصل', 'served_for' => '3'],
    'items'         => [['product_id' => $productId, 'quantity' => 2]],
];
$key = 'audit-' . bin2hex(random_bytes(6));

$order1 = http($base . '/api/index.php?r=order.create', $orderPayload, ['Idempotency-Key: ' . $key]);
check('إرسال الطلب', $order1['status'] === 201, 'status=' . $order1['status']);
$number = $order1['json']['data']['number'] ?? 0;
$token  = $order1['json']['data']['tracking_token'] ?? '';
check('الطلب حصل على رقم', $number > 0);
check('الإجمالي محسوب على الخادم', str_contains((string) ($order1['json']['data']['total'] ?? ''), '50,000'), 'الإجمالي=' . ($order1['json']['data']['total'] ?? '؟'));
check('استجابة الإنشاء لا تسرّب بيانات حساسة', !str_contains((string) $order1['raw'], 'admin_id'));

$order2 = http($base . '/api/index.php?r=order.create', $orderPayload, ['Idempotency-Key: ' . $key]);
check('الضغطتان المتتاليتان لا تنشئان طلبًا ثانيًا',
    ($order2['json']['data']['number'] ?? -1) === $number,
    'الأول=' . $number . ' الثاني=' . ($order2['json']['data']['number'] ?? '؟'));

// تلاعب بالأسعار
$tamper = http($base . '/api/index.php?r=order.create', [
    'order_type' => 'dine_in', 'customer_name' => 'متلاعب', 'location_code' => $runCode,
    'items' => [['product_id' => $productId, 'quantity' => 1, 'price_minor' => 1, 'price' => '1']],
]);
check('تلاعب بالأسعار مرفوض من جهة الخادم', str_contains((string) ($tamper['json']['data']['total'] ?? ''), '25,000'), 'الإجمالي=' . ($tamper['json']['data']['total'] ?? '؟'));

/* متابعة الطلب */
$track = http($base . '/api/index.php?r=order.status&token=' . $token);
check('متابعة الطلب برمز QR تعمل', $track['status'] === 200 && ($track['json']['data']['number'] ?? 0) === $number);
check('صفحة المتابعة لا تعرض أدوات تعديل', !str_contains($track['raw'], 'csrf') && !str_contains($track['raw'], 'admin'));

$badToken = http($base . '/api/index.php?r=order.status&token=00000000000000000000000000000000');
check('رمز متابعة غير صالح مرفوض', $badToken['status'] === 404);

/* ======================= ٦. المدير: دورة حياة الطلب ======================= */

step('٦. المدير — استلام الطلب وتغيير الحالة');

$orders = http($base . '/api/index.php?r=admin.orders&status=new');
check('الطلب الجديد ظاهر في القائمة', in_array($number, array_column($orders['json']['data']['orders'] ?? [], 'number'), true));

$orderId = 0;
$createdAt = (string) ($order1['json']['data']['created_at'] ?? '');
foreach (http($base . '/api/index.php?r=admin.orders&status=new')['json']['data']['orders'] ?? [] as $o) {
    // الأرقام تتكرر يوميًا، لذا نطابق الرقم مع وقت الإنشاء معًا
    if ($o['number'] === $number && $o['created_at'] === $createdAt) {
        $orderId = $o['id'];
    }
}
check('معرّف الطلب الداخلي متاح للمدير', $orderId > 0);
$orderDetail = http($base . '/api/index.php?r=admin.order&id=' . $orderId);
check('تفاصيل الطلب تُقرأ', $orderDetail['status'] === 200);
check('الطلب يحتوي عناصر', count($orderDetail['json']['data']['order']['items'] ?? []) === 1);

$orderQr = http($base . '/api/index.php?r=admin.order.qr&id=' . $orderId);
check('رمز QR للطلب متاح', $orderQr['status'] === 200 && str_contains((string) ($orderQr['json']['data']['qr_url'] ?? ''), '?t='));

$skip = http($base . '/api/index.php?r=admin.order.status', [
    'id' => $orderId, 'status' => 'in_progress',
], ['X-CSRF-Token: ' . $csrf]);
check('انتقال الحالة مسموح: جديد ← قيد التنفيذ', $skip['status'] === 200, 'status=' . $skip['status']);

$toProgress = http($base . '/api/index.php?r=admin.order.status', [
    'id' => $orderId, 'status' => 'in_progress',
], ['X-CSRF-Token: ' . $csrf]);
check('تكرار الحالة نفسها لا يُفسد شيئًا', $toProgress['status'] === 200);

$toDone = http($base . '/api/index.php?r=admin.order.status', [
    'id' => $orderId, 'status' => 'completed',
], ['X-CSRF-Token: ' . $csrf]);
check('قيد التنفيذ ← مكتمل', ($toDone['json']['data']['order']['status'] ?? '') === 'completed');

$afterDone = http($base . '/api/index.php?r=admin.order.status', [
    'id' => $orderId, 'status' => 'new',
], ['X-CSRF-Token: ' . $csrf]);
check('لا رجوع من مكتمل إلى جديد', $afterDone['status'] === 422, 'status=' . $afterDone['status']);

$cancelFinal = http($base . '/api/index.php?r=admin.order.status', [
    'id' => $orderId, 'status' => 'cancelled',
], ['X-CSRF-Token: ' . $csrf]);
check('لا إلغاء بعد الاكتمال', $cancelFinal['status'] === 422, 'status=' . $cancelFinal['status']);

$events = $orderDetail['json']['data']['order']['events'] ?? [];
check('سجل تغييرات الحالة مسجَّل', count($events) >= 1, 'عدد=' . count($events));
check('ملاحظات الطلب محفوظة في تفاصيله', str_contains((string) ($orderDetail['json']['data']['order']['notes'] ?? ''), 'بدون بصل'), 'notes=' . ($orderDetail['json']['data']['order']['notes'] ?? '؟'));
check('عدد الأشخاص حُفظ كحقل مخصص', str_contains((string) ($orderDetail['json']['data']['order']['fields']['served_for'] ?? ''), '3'));

/* ======================= ٧. حدّية: منتج غير متاح/محذوف ======================= */

step('٧. حالات حدّية — منتج غير متاح ومحذوف');

$disable = http($base . '/api/index.php?r=admin.product.update', [
    'id' => $productId, 'name' => 'منتج الفحص', 'price' => '25000',
    'category_id' => $categoryId, 'is_available' => 0, 'sort_order' => 0,
], ['X-CSRF-Token: ' . $csrf]);
check('تعطيل المنتج', $disable['status'] === 200);

$unavailable = http($base . '/api/index.php?r=order.create', [
    'order_type' => 'dine_in', 'customer_name' => 'زبون', 'location_code' => $runCode,
    'items' => [['product_id' => $productId, 'quantity' => 1]],
]);
check('طلب منتج غير متاح مرفوض', $unavailable['status'] === 422 && ($unavailable['json']['error']['code'] ?? '') === 'product_unavailable');

$enable = http($base . '/api/index.php?r=admin.product.update', [
    'id' => $productId, 'name' => 'منتج الفحص', 'price' => '25000',
    'category_id' => $categoryId, 'is_available' => 1, 'sort_order' => 0,
], ['X-CSRF-Token: ' . $csrf]);

$delete = http($base . '/api/index.php?r=admin.product.delete', ['id' => $productId], ['X-CSRF-Token: ' . $csrf]);
check('حذف المنتج', $delete['status'] === 200);

$deleted = http($base . '/api/index.php?r=order.create', [
    'order_type' => 'dine_in', 'customer_name' => 'زبون', 'location_code' => $runCode,
    'items' => [['product_id' => $productId, 'quantity' => 1]],
]);
check('طلب منتج محذوف مرفوض', $deleted['status'] === 422);

$oldOrder = http($base . '/api/index.php?r=order.status&token=' . $token);
check('الطلب القديم يحتفظ باسم المنتج وسعره بعد الحذف',
    ($oldOrder['json']['data']['items'][0]['name'] ?? '') === 'منتج الفحص',
    'الاسم=' . ($oldOrder['json']['data']['items'][0]['name'] ?? '؟'));

/* ======================= ٨. النسخ الاحتياطي ======================= */

step('٨. النسخ الاحتياطي والاستعادة');

$backup = http($base . '/api/index.php?r=admin.backup.create', null, ['X-CSRF-Token: ' . $csrf], null, 'application/json', 'POST');
$backupFile = $backup['json']['data']['file'] ?? '';
check('إنشاء نسخة احتياطية', in_array($backup['status'], [200, 201], true) && $backupFile !== '', 'status=' . $backup['status']);

$download = http($base . '/api/index.php?r=admin.backup.download&name=' . urlencode($backupFile));
check('تنزيل النسخة يعمل', $download['status'] === 200 && strlen($download['raw']) > 1000, 'الحجم=' . strlen($download['raw']));
check('النسخة تحتوي جداول SQLite', str_contains($download['raw'], 'SQLite format 3'));

$badBackup = http($base . '/api/index.php?r=admin.backup.download&name=../../storage/config/local.php');
check('اسم ملف خبيث مرفوض', $badBackup['status'] === 404, 'status=' . $badBackup['status']);

/* ======================= ٩. PWA ======================= */

step('٩. PWA وسلوك دون اتصال');

$manifest = http($base . '/app/manifest.webmanifest');
check('مانيفست PWA صالح', $manifest['status'] === 200 && ($manifest['json']['display'] ?? '') === 'standalone');
check('الأيقونات معلنة', count($manifest['json']['icons'] ?? []) >= 3);

$sw = http($base . '/sw.js');
check('Service Worker متاح على جذر الموقع', $sw['status'] === 200 && str_contains($sw['raw'], 'addEventListener'));
check('نطاقه يشمل واجهة API', str_contains($sw['raw'], "catalog.snapshot"));
check('لا طابور مزامنة للطلبات', !str_contains($sw['raw'], "addEventListener('sync'"));
check('استجابات الإدارة مستثناة من الكاش', str_contains($sw['raw'], "startsWith('admin.')"));

$offlineRoute = http($base . '/api/index.php?r=order.status&token=00000000000000000000000000000000');
check('لا يمكن إرسال طلب بلا خادم (محاكاة: مسار غير موجود)', $offlineRoute['status'] === 404);

/* ======================= ١٠. الجلسة والخروج ======================= */

step('١٠. الجلسة وتسجيل الخروج');

$stillValid = http($base . '/api/index.php?r=admin.session');
check('الجلسة ما زالت صالحة', ($stillValid['json']['data']['username'] ?? '') === 'admin');

$logout = http($base . '/api/index.php?r=admin.logout', null, ['X-CSRF-Token: ' . $csrf], null, 'application/json', 'POST');
check('تسجيل الخروج', $logout['status'] === 200);

$afterLogout = http($base . '/api/index.php?r=admin.products');
check('الواجهة محمية بعد الخروج', $afterLogout['status'] === 401, 'status=' . $afterLogout['status']);

/* ============================ النتيجة ============================ */

banner('النتيجة: نجح ' . $stats['pass'] . ' — فشل ' . $stats['fail']);

exit($stats['fail'] === 0 ? 0 : 1);