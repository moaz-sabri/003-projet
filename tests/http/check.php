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
 * @return array{status:int, json:?array, raw:string, headers:array}
 */
function req(string $url, ?array $body = null, array $headers = [], bool $followRedirects = true): array
{
    $attempt = static function () use ($url, $body, $headers, $followRedirects): array {
        $context = stream_context_create(['http' => [
            'method'        => $body === null ? 'GET' : 'POST',
            'header'        => implode("\r\n", array_merge(['Content-Type: application/json'], $headers)),
            'content'       => $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE),
            'timeout'       => 20,
            'ignore_errors' => true,
            'follow_location' => $followRedirects ? 1 : 0,
            'max_redirects'   => $followRedirects ? 5 : 1,
        ]]);

        $raw    = (string) @file_get_contents($url, false, $context);
        $status = 0;
        $responseHeaders = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        return ['status' => $status, 'json' => json_decode($raw, true), 'raw' => $raw, 'headers' => $responseHeaders];
    };

    $result = $attempt();

    // حد الطلبات قد يتفعّل أثناء الفحص المتكرر — ننتظر ونعيد المحاولة مرة واحدة
    if ($result['status'] === 429) {
        sleep(3);
        $result = $attempt();
    }

    return $result;
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
section('١. الروابط العامة');

// خطأ سابق: كانت الواجهة تُقدَّم على /app/index.html فيظهر ذلك المسار في
// روابط QR المطبوعة وفي شريط العنوان. الآن الجذر "/" هو صفحة واحدة،
// والروابط الداخلية نظيفة (/cart، /checkout، /order/...).
$root = req($base . '/');
check('جذر الموقع (/) يقدّم واجهة الزبون مباشرة', $root['status'] === 200, 'status=' . $root['status']);
check('الواجهة عربية واتجاه RTL', str_contains($root['raw'], 'dir="rtl"'));
check('الواجهة تربط ملف manifest', str_contains($root['raw'], 'manifest.webmanifest'));
check(
    'ملفات الواجهة بمسارات مطلقة',
    str_contains($root['raw'], '/app/css/app.css') && str_contains($root['raw'], '/app/js/app.js'),
    'مسار نسبي في الصفحة = تحميل مكسور عند تغيير الجذر'
);
check('الواجهة تستقبل مسار الـ API الصحيح', str_contains($root['raw'], '/api/index.php'), 'APP_CONFIG.api مفقود');

// المسارات النظيفة تُقدَّم من الملف نفسه بلا 404
foreach (['/cart', '/checkout', '/order/abc123'] as $route) {
    $response = req($base . $route);
    check('المسار النظيف يعمل: ' . $route, $response['status'] === 200, 'status=' . $response['status']);
}

// الرابط القديم لم يعد يظهر، ويحافظ على معاملات رموز QR المطبوعة سابقًا
$legacy = req($base . '/app/index.html?loc=T01', null, [], false);
check('الرابط القديم يُوجَّه إلى الجذر', $legacy['status'] === 301, 'status=' . $legacy['status']);
check(
    'إعادة التوجيه تحافظ على ?loc=',
    str_contains((string) ($legacy['headers']['location'] ?? ''), 'loc=T01'),
    'Location=' . ($legacy['headers']['location'] ?? 'مفقود')
);

$adminNoSlash = req($base . '/admin', null, [], false);
check('/admin بلا شرطة يوجّه إلى /admin/', $adminNoSlash['status'] === 301, 'status=' . $adminNoSlash['status']);

// ملف مفقود يجب أن يبقى 404 (لا صفحة HTML مكانه)
$missingAsset = req($base . '/app/uploads/does-not-exist.png');
check('الملف المفقود يعطي 404 حقيقيًا', $missingAsset['status'] === 404, 'status=' . $missingAsset['status']);

// المسارات داخل الواجهة يجب أن تُخدم من /app/ لا من الجذر
foreach (['/app/js/app.js', '/app/css/app.css', '/app/js/api.js'] as $asset) {
    check('الملف يُقدَّم: ' . basename($asset), req($base . $asset)['status'] === 200);
}

$sw = req($base . '/sw.js');
check('Service Worker متاح على جذر الموقع', $sw['status'] === 200 && str_contains($sw['raw'], 'addEventListener'));

$manifest = json_decode(req($base . '/app/manifest.webmanifest')['raw'], true);
check('مانيفست PWA صالح', is_array($manifest) && ($manifest['dir'] ?? '') === 'rtl');
check(
    'التطبيق مثبَّت يبدأ من الجذر لا من app/index.html',
    ($manifest['start_url'] ?? '') === '../' && !str_contains($manifest['start_url'] ?? '', 'index.html'),
    'start_url=' . ($manifest['start_url'] ?? 'مفقود')
);

/* ----------------------------- الكتالوج ----------------------------- */
section('٢. الكتالوج والطلبات');

$catalog = req($base . '/api/index.php?r=catalog.snapshot');
check('واجهة الكتالوج تعمل', ($catalog['json']['ok'] ?? false) === true, 'status=' . $catalog['status']);

$products  = $catalog['json']['data']['products'] ?? [];
$locations = $catalog['json']['data']['locations'] ?? [];
$config    = $catalog['json']['data']['config'] ?? [];

check('المنتجات معروضة', is_array($products), 'عدد=' . count($products));
check('الأسعار منسّقة', $products !== [] && str_contains((string) ($products[0]['price'] ?? ''), (string) ($config['currency'] ?? 'x')));

// وضع «العرض فقط» نوع نشاط معتمد (general / showroom) يرفض الطلبات
// عمدًا بـ 404 ordering_disabled. كان هذا الفحص يفترض وجود الطلبات دائمًا
// فيسجّل 5 إخفاقات كاذبة على كل نسخة عرض — أي أن الأداة كانت تُبلّغ عن
// «موقع معطوب» والموقع سليم تمامًا. نعتمد الفحوص على ما تعتمده النسخة.
$isCatalogOnly = ($config['mode'] ?? '') === 'catalog'
    || ($config['order_types'] ?? []) === [];

check(
    'إعدادات البنية موجودة',
    $isCatalogOnly || count($config['order_types'] ?? []) > 0,
    $isCatalogOnly ? 'وضع العرض فقط — لا أنواع طلب (سلوك صحيح)' : ''
);

if ($isCatalogOnly) {
    echo "\n\033[33mوضع العرض فقط (لا طلبات) — تخطّى فحوص الطلبات. هذا سلوك النسخة المعتمد.\033[0m\n";
}

if ($products === []) {
    echo "\n\033[33mلا توجد منتجات — تخطى فحوص الطلبات. أضف منتجًا من لوحة الإدارة ثم أعد الفحص.\033[0m\n";
    exit($fail === 0 ? 0 : 1);
}

$productId = $products[0]['id'];
$item      = ['product_id' => $productId, 'quantity' => 1];

if ($isCatalogOnly) {
    // لا نتجاهل الفحوص: نتحقق أن الرفض على الخادم صحيح (404 لا 500 ولا 200).
    $denied = req($base . '/api/index.php?r=order.create', [
        'order_type'    => 'dine_in',
        'customer_name' => 'فحص نشر آلي',
        'items'         => [$item],
    ]);
    check(
        'وضع العرض فقط يرفض إنشاء الطلب على الخادم',
        $denied['status'] === 404,
        'status=' . $denied['status']
    );
    // لا نخرج هنا: بقية الفحوص (حماية المسارات، حماية الإدارة) تهمّ
    // وضع العرض تمامًا، بل هي أهم ما يُفحص فيه.
    $skipOrderChecks = true;
}

/* ------------------- فحوص الطلبات (وضع الطلبات فقط) ------------------- */
if (isset($skipOrderChecks)) {
    // نسخة عرض فقط: نتخطّى بقية فحوص الطلبات، لا الفحص كله.
    // بقية الفحوص أدناه (حماية المسارات والإدارة) تهمّ هذه النسخة أكثر.
} else {

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

} // end فحوص الطلبات

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
    // أرشيف في جذر الموقع = نزّل storage كاملًا بترويسة واحدة
    '/setup.zip',
    '/backup.zip',
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
    'لوحة الإدارة لا تشير إلى مسار ملف داخلي',
    !str_contains($admin['raw'], '../app/index.html'),
    'رابط «عرض الموقع» ما زال يشير إلى app/index.html'
);
// إعدادات الصفحة داخل JSON: نتحقق أن مسار الـ API مطلق لا نسبي
preg_match('#<script type="application/json" id="app-config">(.*?)</script>#s', $admin['raw'], $configMatch);
$adminConfig = json_decode($configMatch[1] ?? '', true);
check(
    'لوحة الإدارة تستخدم مسار API مطلقًا',
    is_array($adminConfig)
        && str_starts_with((string) ($adminConfig['api'] ?? ''), '/')
        && str_ends_with((string) ($adminConfig['api'] ?? ''), 'api/index.php'),
    'api=' . ($adminConfig['api'] ?? 'مفقود')
);
check(
    'رابط «عرض الموقع» يشير إلى جذر الموقع',
    ($adminConfig['home'] ?? '') === (parse_url($base, PHP_URL_PATH) ?: '/'),
    'home=' . ($adminConfig['home'] ?? 'مفقود')
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