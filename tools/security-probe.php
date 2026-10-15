<?php

declare(strict_types=1);

/**
 * فحص أمني حيّ — يختبر النسخة المنشورة فعليًا عبر HTTP.
 *
 *   php tools/security-probe.php http://127.0.0.1:8080
 *   php tools/security-probe.php http://127.0.0.1:8080 "كلمة_المرور"
 *   php tools/security-probe.php http://127.0.0.1:8080 --pass="كلمة_المرور"
 *
 * كلمة المرور تُقرأ بالترتيب: المعامل الثاني، ثم --pass، ثم متغير البيئة
 * LC_ADMIN_PASS، ثم القيمة الافتراضية (لبيئة التطوير المحلية فقط).
 *
 * يغطي: حقن SQL، XSS، CSRF، الجلسات، الصلاحيات، رفع الملفات،
 *       تعداد المسارات، حد المحاولات، وتسرّب المعلومات.
 */

if (PHP_SAPI !== 'cli') {
    exit("هذه الأداة تعمل من الطرفية فقط.\n");
}

$base     = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');

/**
 * كلمة مرور المدير: المعامل الثاني، أو --pass=، أو متغير البيئة LC_ADMIN_PASS.
 * الهدف: ألّا تُثبَّت كلمة مرور واحدة داخل الأداة، فتفشل الفحوص على أي نسخة
 * استخدمت المدير كلمة مرور مختلفة.
 */
$password = $argv[2] ?? '';
if (str_starts_with($password, '--pass=')) {
    $password = substr($password, 7);
}
if ($password === '') {
    $envPass = getenv('LC_ADMIN_PASS');
    $password = is_string($envPass) && $envPass !== '' ? $envPass : 'Test1234';
}

$jar      = [];

$pass = 0;
$fail = 0;

function http(string $url, ?array $json = null, array $headers = [], string $method = '', ?string $rawBody = null): array
{
    global $jar;

    $isPost = $method !== '' ? strtoupper($method) : ($json !== null || $rawBody !== null);
    $httpMethod = $isPost ? 'POST' : ($method !== '' ? strtoupper($method) : 'GET');
    $body   = $rawBody ?? '';
    $hl     = ['Accept: */*'];

    if ($json !== null) {
        $body = json_encode($json, JSON_UNESCAPED_UNICODE);
        $hl[] = 'Content-Type: application/json';
        $hl[] = 'Content-Length: ' . strlen($body);
    } elseif ($rawBody !== null) {
        $hl[] = 'Content-Length: ' . strlen($rawBody);
    }
    foreach ($headers as $h) {
        $hl[] = $h;
    }
    if ($jar !== []) {
        $pairs = [];
        foreach ($jar as $k => $v) {
            $pairs[] = "$k=$v";
        }
        $hl[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $ctx = stream_context_create(['http' => [
        'method'        => $httpMethod,
        'header'        => implode("\r\n", $hl),
        'content'       => $body,
        'timeout'       => 25,
        'ignore_errors' => true,
    ]]);

    $raw    = (string) @file_get_contents($url, false, $ctx);
    $status = 0;
    $respHeaders = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        }
        if (stripos($line, 'Set-Cookie:') === 0) {
            $c = trim(substr($line, 11));
            $p = explode('=', $c, 2);
            if (isset($p[1])) {
                $jar[trim($p[0])] = trim(explode(';', $p[1])[0]);
            }
        }
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }

    return ['status' => $status, 'json' => json_decode($raw, true), 'raw' => $raw, 'headers' => $respHeaders];
}

function probe(string $label, bool $ok, string $detail = ''): void
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

function section(string $t): void
{
    echo "\n\033[1m" . $t . "\033[0m\n";
}

echo "فحص أمني على: $base\n" . str_repeat('─', 62) . "\n";

/* ==================== ١. معلومات مسرّبة ==================== */
section('١. تسرّب المعلومات');

$root = http($base . '/');
probe('الرسالة العامة لا تكشف مسارات النظام',
    !str_contains($root['raw'], 'BASE_PATH') && !str_contains($root['raw'], 'C:\\'),
    substr($root['raw'], 0, 90));

$missing = http($base . '/api/index.php?r=does.not.exist');
probe('مسار API غير معروف يعطي 404 بلا تفاصيل', $missing['status'] === 404, 'status=' . $missing['status']);

$badMethod = http($base . '/api/index.php?r=catalog.snapshot', null, [], 'DELETE');
probe('طريقة HTTP غير مدعومة مرفوضة', in_array($badMethod['status'], [404, 405], true), 'status=' . $badMethod['status']);

$adminPage = http($base . '/admin/');
probe('لوحة الإدارة: X-Frame-Options',
    strtolower($adminPage['headers']['x-frame-options'] ?? '') === 'sameorigin');
probe('CSP لا تسمح بـ inline scripts',
    !str_contains($adminPage['headers']['content-security-policy'] ?? '', "script-src 'self' 'unsafe-inline'"),
    $adminPage['headers']['content-security-policy'] ?? 'مفقود');

$consoleFile = http($base . '/bin/console');
probe('ملف bin/console غير مخدوم كنص', !str_contains($consoleFile['raw'], 'ProjectConfig'));

/* ==================== ٢. المسارات المحمية ==================== */
section('٢. المسارات المحمية');

$protected = [
    '/storage/database/app.sqlite'  => 'قاعدة البيانات',
    '/storage/config/local.php'     => 'ملف الأسرار',
    '/src/Config/ProjectConfig.php' => 'كود الخادم',
    '/storage/backups/'             => 'النسخ الاحتياطية',
    '/.env'                         => 'ملف البيئة',
    '/storage/logs/'                => 'السجلات',
    '/tests/run.php'                => 'ملفات الاختبار',
];
foreach ($protected as $path => $label) {
    $r = http($base . $path);
    probe("محجوب: $label", in_array($r['status'], [403, 404], true), $path . ' → ' . $r['status']);
}

/* ==================== ٣. المصادقة والجلسات ==================== */
section('٣. المصادقة والجلسات');

$noSession = http($base . '/api/index.php?r=admin.session');
probe('واجهة الجلسة محمية بلا دخول', $noSession['status'] === 401, 'status=' . $noSession['status']);

$login  = http($base . '/api/index.php?r=admin.login', ['username' => 'admin', 'password' => $password]);
$csrf   = $login['json']['data']['csrf'] ?? '';
$cookie = (string) ($login['headers']['set-cookie'] ?? '');
probe('تسجيل الدخول ناجح', $login['status'] === 200 && $csrf !== '');
probe('كوكي الجلسة يحمل HttpOnly و SameSite',
    str_contains($cookie, 'HttpOnly') && str_contains($cookie, 'SameSite'),
    $cookie ?: 'مفقود');

$sessionBefore = $jar['lc_admin'] ?? '';
$relogin = http($base . '/api/index.php?r=admin.login', ['username' => 'admin', 'password' => $password]);
$sessionAfter = $jar['lc_admin'] ?? '';
probe('تجديد معرّف الجلسة بعد كل دخول', $sessionAfter !== '' && $sessionAfter !== $sessionBefore);

// الدخول المتجدد يُصدر رمز CSRF جديدًا (الجلسة نفسها تغيّرت)
$csrf = $relogin['json']['data']['csrf'] ?? '';
probe('رمز CSRF يتغير مع كل جلسة', $csrf !== '', 'الرمز فارغ');
$oldTokenProbe = http($base . '/api/index.php?r=admin.category.create', ['name' => 'ب الرمز القديم'], ['X-CSRF-Token: ' . str_repeat('0', 64)]);
probe('الرمز القديم/المزيف مرفوض', $oldTokenProbe['status'] === 403, 'status=' . $oldTokenProbe['status']);

$emptyPass = http($base . '/api/index.php?r=admin.login', ['username' => 'admin', 'password' => '']);
probe('كلمة مرور فارغة مرفوضة', $emptyPass['status'] === 422, 'status=' . $emptyPass['status']);

$wrongUser = http($base . '/api/index.php?r=admin.login', ['username' => 'nope', 'password' => 'x']);
$wrongPass = http($base . '/api/index.php?r=admin.login', ['username' => 'admin', 'password' => 'x']);
probe('رسالة الخطأ واحدة لاسم مستخدم خاطئ وكلمة مرور خاطئة',
    ($wrongUser['json']['error']['message'] ?? 'أ') === ($wrongPass['json']['error']['message'] ?? 'ب'),
    ($wrongUser['json']['error']['message'] ?? '') . ' / ' . ($wrongPass['json']['error']['message'] ?? ''));

/* ==================== ٤. CSRF ==================== */
section('٤. حماية CSRF');

$noToken = http($base . '/api/index.php?r=admin.category.create', ['name' => 'هجوم']);
probe('إنشاء بدون رمز CSRF مرفوض', $noToken['status'] === 403, 'status=' . $noToken['status']);

$wrongToken = http($base . '/api/index.php?r=admin.category.create', ['name' => 'هجوم'], ['X-CSRF-Token: ' . str_repeat('a', 64)]);
probe('رمز CSRF خاطئ مرفوض', $wrongToken['status'] === 403, 'status=' . $wrongToken['status']);

$valid = http($base . '/api/index.php?r=admin.category.create', ['name' => 'تصنيف الفحص الأمني'], ['X-CSRF-Token: ' . $csrf]);
probe('طلب شرعي برمز صحيح يعمل', in_array($valid['status'], [200, 201], true), 'status=' . $valid['status']);
$attackId = $valid['json']['data']['id'] ?? 0;

/* ==================== ٥. حقن SQL ==================== */
section('٥. حقن SQL');

$payloads = [
    "'; DROP TABLE orders; --",
    "1' OR '1'='1",
    "%' UNION SELECT username,password_hash FROM sqlite_master --",
    "1; DELETE FROM products WHERE 1=1; --",
    "admin'--",
];

foreach ($payloads as $payload) {
    $r = http($base . '/api/index.php?r=admin.orders&q=' . urlencode($payload), null, ['X-CSRF-Token: ' . $csrf]);
    probe('بحث آمن مع: ' . mb_substr($payload, 0, 26),
        ($r['json']['ok'] ?? false) === true, 'status=' . $r['status']);
}

$dashboard = http($base . '/api/index.php?r=admin.dashboard', null, ['X-CSRF-Token: ' . $csrf]);
probe('الجداول سليمة بعد محاولات الحقن', ($dashboard['json']['ok'] ?? false) === true);

$oddFilter = http($base . '/api/index.php?r=admin.orders&status=' . urlencode("new' OR '1'='1"), null, ['X-CSRF-Token: ' . $csrf]);
probe('عامل تصفية غير صالح لا ينهار', ($oddFilter['json']['ok'] ?? false) === true, 'status=' . $oddFilter['status']);

/* ==================== ٦. XSS ==================== */
section('٦. XSS');

$xssName  = '<img src=x onerror=alert(1)>' . bin2hex(random_bytes(2));
$created  = http($base . '/api/index.php?r=admin.product.create',
    ['name' => $xssName, 'price' => '1000', 'is_available' => 1, 'sort_order' => 0],
    ['X-CSRF-Token: ' . $csrf]);
probe('اسم يحتوي وسمًا يُقبل كبيانات خام', in_array($created['status'], [200, 201], true), 'status=' . $created['status']);
$xssId = $created['json']['data']['id'] ?? 0;

$adminJs = http($base . '/admin/assets/admin.js')['raw'];
probe('لوحة الإدارة تهرّر النصوص قبل العرض', str_contains($adminJs, 'escapeHtml'));
$appUi   = http($base . '/app/js/ui.js')['raw'];
probe('واجهة الزبون تهرّر النصوص قبل العرض', str_contains($appUi, 'escape'));

$orderXss = http($base . '/api/index.php?r=order.create', [
    'order_type'    => 'takeaway',
    'customer_name' => '<script>alert(1)</script>سامي',
    'items'         => [['product_id' => $xssId, 'quantity' => 1]],
]);
probe('إنشاء طلب باسم يحتوي سكربت', in_array($orderXss['status'], [201], true), 'status=' . $orderXss['status']);

if ($orderXss['status'] === 201) {
    $token = $orderXss['json']['data']['tracking_token'] ?? '';
    $tracked = http($base . '/api/index.php?r=order.status&token=' . $token);
    $shown = (string) ($tracked['json']['data']['customer'] ?? '');
    probe('اسم الزبون يُنظَّف من الوسوم عند العرض', !str_contains($shown, '<script'), $shown);
}

/* ==================== ٧. رفع الملفات ==================== */
section('٧. رفع الملفات');

function multipart(string $boundary, string $filename, string $type, string $content, string $field = 'image'): string
{
    return "--$boundary\r\nContent-Disposition: form-data; name=\"$field\"; filename=\"$filename\"\r\n"
        . "Content-Type: $type\r\n\r\n" . $content . "\r\n--$boundary--\r\n";
}

$boundary = '----probe' . bin2hex(random_bytes(5));
$mpType   = 'multipart/form-data; boundary=' . $boundary;

$evil = http($base . '/api/index.php?r=admin.product.image', null,
    ['X-CSRF-Token: ' . $csrf, 'Content-Type: ' . $mpType],
    'POST', multipart($boundary, 'evil.php', 'application/x-php', '<?php system($_GET["c"]); ?>'));
probe('رفع سكربت PHP مرفوض', in_array($evil['status'], [400, 422], true), 'status=' . $evil['status']);

$polyglot = http($base . '/api/index.php?r=admin.product.image', null,
    ['X-CSRF-Token: ' . $csrf, 'Content-Type: ' . $mpType],
    'POST', multipart($boundary, 'poly.jpg', 'image/jpeg', "\xFF\xD8\xFF\xE0<?php system('id'); ?>\xFF\xD9"));
probe('صورة PHP مزدوجة مرفوضة', in_array($polyglot['status'], [400, 422], true), 'status=' . $polyglot['status']);

$svgAttack = http($base . '/api/index.php?r=admin.product.image', null,
    ['X-CSRF-Token: ' . $csrf, 'Content-Type: ' . $mpType],
    'POST', multipart($boundary, 'x.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));
probe('SVG يحمل سكربت مرفوض', in_array($svgAttack['status'], [400, 422], true), 'status=' . $svgAttack['status']);

$huge = http($base . '/api/index.php?r=admin.product.image', null,
    ['X-CSRF-Token: ' . $csrf, 'Content-Type: ' . $mpType],
    'POST', multipart($boundary, 'huge.png', 'image/png', str_repeat('A', 6 * 1024 * 1024)));
probe('ملف ضخم مرفوض', in_array($huge['status'], [400, 413, 422], true), 'status=' . $huge['status']);

$gif = http($base . '/api/index.php?r=admin.product.image', null,
    ['X-CSRF-Token: ' . $csrf, 'Content-Type: ' . $mpType],
    'POST', multipart($boundary, 'ok.gif', 'image/gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')));
probe('صورة GIF صالحة مقبولة', $gif['status'] === 201, 'status=' . $gif['status']);
if (($gif['json']['data']['image_path'] ?? '') !== '') {
    probe('اسم الملف الناتج عشوائي', !str_contains(basename($gif['json']['data']['image_path']), 'ok'),
        basename($gif['json']['data']['image_path']));
}

/* ==================== ٨. الصلاحيات ==================== */
section('٨. الصلاحيات بعد الخروج');

http($base . '/api/index.php?r=admin.logout', null, ['X-CSRF-Token: ' . $csrf], 'POST');
$afterLogout = http($base . '/api/index.php?r=admin.products');
probe('واجهات الإدارة محمية بعد الخروج', $afterLogout['status'] === 401, 'status=' . $afterLogout['status']);

$editAfterLogout = http($base . '/api/index.php?r=admin.category.create', ['name' => 'بعد الخروج']);
probe('لا يمكن التعديل بعد الخروج',
    in_array($editAfterLogout['status'], [401, 403], true), 'status=' . $editAfterLogout['status']);

$downloadAfter = http($base . '/api/index.php?r=admin.backup.download&name=backup-20260101-000000.sqlite');
probe('تنزيل نسخة احتياطية محمي', $downloadAfter['status'] === 401, 'status=' . $downloadAfter['status']);

/* ==================== ٩. رموز المتابعة ==================== */
section('٩. رموز المتابعة');

$leaked = 0;
for ($i = 0; $i < 10; $i++) {
    $r = http($base . '/api/index.php?r=order.status&token=' . bin2hex(random_bytes(16)));
    if ($r['status'] === 200) {
        $leaked++;
    }
}
probe('رموز عشوائية غير قابلة للتخمين (10 محاولات)', $leaked === 0, 'نجح=' . $leaked);
probe('رمز-all-zeros مرفوض',
    http($base . '/api/index.php?r=order.status&token=' . str_repeat('0', 32))['status'] === 404);

/* ==================== ١٠. حد المحاولات ==================== */
section('١٠. تحديد المحاولات');

$lockedAfter = 0;
for ($i = 0; $i < 10; $i++) {
    $r = http($base . '/api/index.php?r=admin.login', ['username' => 'admin', 'password' => 'wrong-' . $i]);
    if ($r['status'] === 429) {
        $lockedAfter = $i + 1;
        break;
    }
}
probe('قفل الدخول بعد محاولات فاشلة', $lockedAfter > 0, 'بعد=' . $lockedAfter . ' محاولة');

/* ==================== ١١.Cleanup ==================== */
section('١١. تنظيف بيانات الفحص');

$login2 = http($base . '/api/index.php?r=admin.login', ['username' => 'admin', 'password' => $password]);
$csrf2  = $login2['json']['data']['csrf'] ?? '';
if ($csrf2 !== '' && $attackId > 0) {
    http($base . '/api/index.php?r=admin.category.delete', ['id' => $attackId], ['X-CSRF-Token: ' . $csrf2]);
}
if ($csrf2 !== '' && $xssId > 0) {
    http($base . '/api/index.php?r=admin.product.delete', ['id' => $xssId], ['X-CSRF-Token: ' . $csrf2]);
}
probe('تم تنظيف بيانات الفحص', true);

echo "\n" . str_repeat('─', 62) . "\n";
echo "نجح: $pass — فشل: $fail\n";
echo ($fail === 0
    ? "\033[32m\033[1m  لا ثغرات مكتشفة\033[0m\n"
    : "\033[31m\033[1m  ثغرات/إخفاقات: $fail\033[0m\n");
exit($fail === 0 ? 0 : 1);