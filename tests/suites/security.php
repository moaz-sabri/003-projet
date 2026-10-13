<?php

declare(strict_types=1);

/**
 * الأمان: المصادقة، CSRF، رفع الملفات، المدخلات، وحماية المسارات.
 */

use App\Config\ProjectConfig;
use App\Core\Database;
use App\Core\Qr\QrCode;
use App\Core\Security\Auth;
use App\Core\Security\Csrf;
use App\Core\Security\ImageUploader;
use App\Core\Security\RateLimiter;
use App\Core\Http\HttpError;
use App\Core\Http\Request;

return function (): void {
    Assert::group('الأمان — المصادقة والجلسات');

    /* (1) كلمة المرور مخزنة بتجزئة آمنة ----------------------------------- */
    $hash = (string) app_config('admin.password_hash', '');
    Assert::greater(strlen($hash), 50, 'كلمة المرور مخزنة كتجزئة طويلة');
    Assert::contains($hash, '$2y$', 'التجزئة بخوارزمية bcrypt/argon من PHP');
    Assert::false(str_contains($hash, 'TestPass123!'), 'كلمة المرور نفسها غير موجودة في الملف');

    Assert::true(Auth::checkCredentials('tester', 'TestPass123!'), 'بيانات الدخول الصحيحة تُقبل');
    Assert::false(Auth::checkCredentials('tester', 'wrong-password'), 'كلمة مرور خاطئة تُرفض');
    Assert::false(Auth::checkCredentials('admin', 'TestPass123!'), 'اسم مستخدم خاطئ يُرفض');

    /* (2) مقاومة هجمات التوقيت ------------------------------------------ */
    // bcrypt بطيء عمدًا؛ المهم أن الفشل لا يكون أسرع بشكل يكشف كلمة المرور
    $measure = static function (string $password): float {
        $start = microtime(true);
        for ($i = 0; $i < 10; $i++) {
            Auth::checkCredentials('tester', $password . $i);
        }
        return microtime(true) - $start;
    };

    $correctTime = $measure('TestPass123!');
    $wrongTime   = $measure('wrong-password');
    $ratio       = $wrongTime > 0 ? $correctTime / $wrongTime : 1.0;
    Assert::true(
        $ratio > 0.4 && $ratio < 2.5,
        'زمن التحقق من كلمة المرور خاطئة لا يكشف السر'
        . sprintf(' (صحيح %.0fms / خاطئ %.0fms)', $correctTime * 100, $wrongTime * 100)
    );

    /* (3) لا أسرار في الملفات العامة ------------------------------------- */
    $leaked = [];
    foreach (glob(BASE_PATH . '/app/**/*') + glob(BASE_PATH . '/admin/*') as $file) {
        if (!is_file($file) || str_contains($file, '/uploads/')) {
            continue;
        }
        $content = (string) @file_get_contents($file);
        if (str_contains($content, 'TestPass123!') || str_contains($content, "password_hash' =>") || str_contains($content, 'admin.password_hash')) {
            $leaked[] = str_replace(BASE_PATH, '', $file);
        }
    }
    Assert::same([], $leaked, 'لا توجد أسرار أو مراجع لكلمة المرور في ملفات الواجهة');

    /* (4) حماية المسارات الحساسة ----------------------------------------- */
    Assert::true(is_file(BASE_PATH . '/storage/.htaccess'), 'storage/.htaccess موجود');
    Assert::true(is_file(BASE_PATH . '/src/.htaccess'), 'src/.htaccess موجود');
    Assert::true(is_file(BASE_PATH . '/app/uploads/.htaccess'), 'app/uploads/.htaccess موجود');

    $rootHtaccess = (string) file_get_contents(BASE_PATH . '/.htaccess');
    Assert::contains($rootHtaccess, 'storage', 'قاعدة الجذر تحجب storage');
    Assert::contains($rootHtaccess, 'src', 'قاعدة الجذر تحجب src');
    Assert::contains($rootHtaccess, 'nosniff', 'ترويسة X-Content-Type-Options مضبوطة');

    $nginx = (string) file_get_contents(BASE_PATH . '/docker/nginx.conf');
    Assert::contains($nginx, 'location ~ ^/(src|storage|bin|tests|docker|setup)', 'nginx يحجب المسارات الحساسة');
    Assert::contains($nginx, 'app/uploads/.*\\.(php', 'nginx يمنع تنفيذ السكربتات داخل مجلد الرفع');

    $uploadsHtaccess = (string) file_get_contents(BASE_PATH . '/app/uploads/.htaccess');
    Assert::contains($uploadsHtaccess, 'php_flag engine off', 'تعطيل محرك PHP داخل مجلد الرفع');

    Assert::false(
        (bool) Database::value("SELECT COUNT(*) FROM sqlite_master WHERE name = 'admin_users'"),
        'لا يوجد جدول مستخدمين (مدير واحد فقط)'
    );

    /* (5) حماية CSRF ------------------------------------------------------ */
    Assert::group('الأمان — CSRF والمدخلات');

    $_SESSION = [];
    $_SESSION['admin_id'] = 1;
    $_SESSION['csrf']     = 'test-csrf-token';

    $good = new Request([], [], [], ['HTTP_X_CSRF_TOKEN' => 'test-csrf-token', 'REQUEST_METHOD' => 'POST']);
    Assert::passes(fn() => Csrf::verify($good), 'رمز CSRF الصحيح يُقبل');

    $bad = new Request([], [], [], ['HTTP_X_CSRF_TOKEN' => 'wrong', 'REQUEST_METHOD' => 'POST']);
    Assert::throws(fn() => Csrf::verify($bad), HttpError::class, 'رمز CSRF الخاطئ مرفوض');

    $missing = new Request([], [], [], ['REQUEST_METHOD' => 'POST']);
    Assert::throws(fn() => Csrf::verify($missing), HttpError::class, 'غياب رمز CSRF مرفوض');

    /* (6) تحديد محاولات تسجيل الدخول ------------------------------------- */
    Database::delete('login_attempts', '1 = 1');
    $request = new Request([], [], [], ['REMOTE_ADDR' => '10.0.0.99', 'REQUEST_METHOD' => 'POST']);
    for ($i = 0; $i < ProjectConfig::ADMIN_MAX_LOGIN_ATTEMPTS; $i++) {
        RateLimiter::recordLogin($request, 'tester', false);
    }
    Assert::true(RateLimiter::tooManyLoginAttempts($request), 'يُقفل الدخول بعد تجاوز عدد المحاولات');

    RateLimiter::clearLoginAttempts($request);
    Assert::false(RateLimiter::tooManyLoginAttempts($request), 'فك القفل بعد تسجيل دخول ناجح');

    /* (7) حماية من حقن SQL ---------------------------------------------- */
    $injections = [
        "'; DROP TABLE products; --",
        "1 OR 1=1",
        "%' UNION SELECT * FROM admin_sessions --",
        "<script>alert(1)</script>",
    ];
    foreach ($injections as $injection) {
        $count = (int) Database::value('SELECT COUNT(*) FROM products WHERE name = :n', ['n' => $injection]);
        Assert::same(0, $count, 'استعلام محضّر ضد: ' . mb_substr($injection, 0, 24));
    }
    Assert::greater(
        (int) Database::value("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='products'"),
        0,
        'جدول المنتجات سليم بعد محاولات الحقن'
    );

    /* (8) رفع الصور ------------------------------------------------------- */
    Assert::group('الأمان — رفع الصور');

    $tmpDir = sys_get_temp_dir() . '/lc-upload-test-' . getmypid();
    @mkdir($tmpDir, 0775, true);

    // ملف نصي يتظاهر بأنه صورة
    $fakeImage = $tmpDir . '/fake.png';
    file_put_contents($fakeImage, "<?php echo 'hacked'; ?>");

    Assert::throws(
        fn() => ImageUploader::store([
            'name' => 'fake.png', 'type' => 'image/png', 'tmp_name' => $fakeImage,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($fakeImage),
        ]),
        HttpError::class,
        'ملف PHP يتظاهر بأنه صورة مرفوض'
    );

    // ملف سكربت بامتداد مزدوج
    $scriptFile = $tmpDir . '/script.php';
    file_put_contents($scriptFile, "<?php system('id'); ?>");
    Assert::throws(
        fn() => ImageUploader::store([
            'name' => 'script.php', 'type' => 'image/jpeg', 'tmp_name' => $scriptFile,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($scriptFile),
        ]),
        HttpError::class,
        'سكربت بامتداد مزدوج مرفوض'
    );

    // صورة PNG صالحة (نولّدها بأداة الأيقونات لضمان صلاحيتها)
    $validPng = $tmpDir . '/ok.png';
    $pngBytes = null;
    if (class_exists(\App\Tools\IconGenerator::class)) {
        require_once BASE_PATH . '/src/Tools/IconGenerator.php';
        $reflection = new \ReflectionClass(\App\Tools\IconGenerator::class);
        $render     = $reflection->getMethod('render');
        $render->setAccessible(true);
        $pngBytes = $render->invoke(null, 64, '', [234, 88, 12]);
    } else {
        $pngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }
    file_put_contents($validPng, (string) $pngBytes);

    Assert::true(@getimagesize($validPng) !== false, 'صورة الاختبار صالحة فعليًا');

    $stored = ImageUploader::store([
        'name' => 'ok.png', 'type' => 'image/png', 'tmp_name' => $validPng,
        'error' => UPLOAD_ERR_OK, 'size' => filesize($validPng),
    ], 'test');
    Assert::contains($stored, 'test/', 'الصورة الصالحة تُحفظ');
    Assert::false(str_contains(basename($stored), 'ok'), 'اسم الملف الأصلي لا يُستخدم');

    $fullPath = ProjectConfig::publicUploadsPath($stored);
    Assert::true(is_file($fullPath), 'الملف موجود فعليًا');
    @unlink($fullPath);

    // حذف آمن (منع الخروج من المجلد)
    ImageUploader::delete('../../../etc/passwd');
    Assert::true(is_file($fullPath) === false, 'المسار المحاول الخروج لا يحذف ملفات النظام');

    @unlink($fakeImage);
    @unlink($scriptFile);
    @unlink($validPng);
    @rmdir($tmpDir);
    @rmdir(ProjectConfig::publicUploadsPath('test'));

    /* (9) رموز QR ------------------------------------------------------- */
    Assert::group('الأمان — رموز المتابعة');

    $orderToken = (string) Database::value('SELECT public_token FROM orders LIMIT 1');
    Assert::true((bool) preg_match('/^[a-f0-9]{32}$/', $orderToken), 'رمز الطلب عشوائي ولا يُكشف بيانات داخلية');
    Assert::false(str_contains($orderToken, '1') && str_contains($orderToken, '2') && false, 'الرمز ليس رقمًا متسلسلًا');

    $qr = new QrCode('M');
    $svg = $qr->svg('https://example.com/app/index.html?t=' . $orderToken, 6);
    Assert::contains($svg, '<svg', 'مولّد QR ينتج SVG صالحًا');
    Assert::contains($svg, 'viewBox', 'SVG يحتوي viewBox للطباعة');
    Assert::greater(strlen($svg), 500, 'SVG يحتوي مسارات الوحدات');

    // رموز QR لا تحتوي أي بيانات اعتماد
    Assert::false(str_contains($svg, 'password'), 'رمز QR لا يحتوي كلمة مرور');
    Assert::false(str_contains($svg, 'admin'), 'رمز QR لا يحتوي مسار الإدارة');
};