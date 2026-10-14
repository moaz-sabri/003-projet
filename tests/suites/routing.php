<?php

declare(strict_types=1);

/**
 * الروابط والتوجيه — متطلبات readme §2 و §11.
 *
 * الفكرة التي تحميها هذه المجموعة: رابط الموقع هو "/" بلا اسم ملف داخلي،
 * والروابط الداخلية نظيفة (/cart، /checkout، /order/{رمز})، وتعمل على
 * جذر النطاق وداخل مجلد فرعي في الوقت نفسه.
 */

use App\Services\QrService;
use App\Core\Web\Paths;

return function (): void {

    /* ==================== ١. حساب الجذر والمسارات ==================== */
    Assert::group('التوجيه — جذر الموقع');

    $original = $_SERVER;

    $_SERVER['SCRIPT_NAME'] = '/index.php';
    Assert::same('/', Paths::base(), 'root install → "/"');
    Assert::same('/', Paths::url(), 'الرابط الأساسي هو الجذر بلا اسم ملف');
    Assert::same('/app/css/app.css', Paths::shop('css/app.css'), 'ملفات الواجهة على /app/');
    Assert::same('/api/index.php', Paths::api(), 'نقطة الـ API على الجذر');

    $_SERVER['SCRIPT_NAME'] = '/shop/index.php';
    Assert::same('/shop/', Paths::base(), 'تثبيت داخل مجلد فرعي → "/shop/"');
    Assert::same('/shop/app/css/app.css', Paths::shop('css/app.css'), 'ملفات الواجهة تتبع المجلد الفرعي');
    Assert::same('/shop/api/index.php', Paths::api(), 'الـ API يتبع المجلد الفرعي');

    $_SERVER['SCRIPT_NAME'] = '/shop/admin/index.php';
    Assert::same('/shop/admin/', Paths::base(0), 'لوحة الإدارة داخل المجلد الفرعي');
    Assert::same('/shop/', Paths::base(1), 'للمجلدات الفرعية: مستوى واحد للأعلى');
    Assert::same('/shop/api/index.php', Paths::api(1), 'الـ API من داخل مجلد فرعي');
    Assert::same('/shop/', Paths::home(1), 'رابط القائمة من داخل مجلد فرعي');
    Assert::same('/shop/app/assets/icons/icon.svg', Paths::shop('assets/icons/icon.svg', 1), 'أصول الواجهة من داخل مجلد فرعي');

    // تحويل مسار الطلب إلى مسار داخلي
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['REQUEST_URI'] = '/cart';
    Assert::same('cart', Paths::route(), 'المسار الداخلي بلا الجذر');

    $_SERVER['SCRIPT_NAME'] = '/shop/index.php';
    $_SERVER['REQUEST_URI'] = '/shop/order/ABC123';
    Assert::same('order/ABC123', Paths::route(), 'مسار طلب داخل مجلد فرعي');

    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['REQUEST_URI'] = '/?loc=T01';
    Assert::same('', Paths::route(), 'معاملات الاستعلام لا تدخل في المسار الداخلي');

    $_SERVER = $original;

    /* ==================== ٢. ملف مفقود = صفحة HTML ==================== */
    Assert::group('التوجيه — لا تخفي أخطاء الملفات');

    Assert::true(Paths::looksLikeFile('assets/missing.png'), 'صورة مفقودة تُعدّ ملفًا');
    Assert::false(Paths::looksLikeFile('cart'), 'المسار cart ليس ملفًا');
    Assert::false(Paths::looksLikeFile('order/ABC123'), 'مسار طلب ليس ملفًا');

    /* ==================== ٣. روابط QR ==================== */
    Assert::group('التوجيه — روابط رموز QR');

    $qr = new QrService();
    $base = 'https://example.com';

    Assert::same($base . '/?loc=T01', $qr->locationUrl($base, 'T01'), 'رمز الموقع يفتح الجذر مع تحديد الموقع');
    Assert::same($base . '/?loc=T01', $qr->locationUrl($base . '/', 'T01'), 'شرطة مائلة زائدة لا تغيّر الرابط');
    Assert::same($base . '/order/ABC123', $qr->orderUrl($base, 'ABC123'), 'رمز الطلب يفتح مسار متابعة نظيفًا');

    Assert::notContains($qr->locationUrl($base, 'T01'), 'app/index.html', 'لا اسم ملف داخلي في رابط الموقع');
    Assert::notContains($qr->orderUrl($base, 'ABC123'), 'app/index.html', 'لا اسم ملف داخلي في رابط الطلب');

    // الرابط القديم المؤرشف يجب أن يبقى محمولًا للرموز المطبوعة
    Assert::contains($qr->locationUrl($base, 'T01'), 'loc=T01', 'رمز الموقع يحتفظ بمعامل التحديد');

    /* ==================== ٤. قواعد إعادة التوجيه ==================== */
    Assert::group('التوجيه — قواعد الخوادم');

    $htaccess = (string) file_get_contents(BASE_PATH . '/.htaccess');
    Assert::contains($htaccess, 'RewriteRule ^app/index\.html$ / [R=301,L]', 'Apache: الرابط القديم يوجّه إلى الجذر');
    Assert::contains($htaccess, 'RewriteRule ^index\.php$ / [R=301,L]', 'Apache: /index.php يوجّه إلى الجذر');
    Assert::contains($htaccess, 'RewriteRule ^admin$ admin/ [R=301,L]', 'Apache: /admin بلا شرطة مائلة');
    Assert::contains($htaccess, 'RewriteRule ^ index.php [L]', 'Apache: تطبيق صفحة واحدة لكل مسار نظيف');
    Assert::contains($htaccess, 'DirectoryIndex index.php', 'Apache: الجذر يقدّم index.php');
    Assert::contains($htaccess, '%{REQUEST_FILENAME} !-f', 'Apache: الملفات الموجودة تُقدَّم كما هي');

    $nginx = (string) file_get_contents(BASE_PATH . '/docker/nginx.conf');
    Assert::contains($nginx, 'location = /app/index.html { return 301 /$is_args$args; }', 'nginx: الرابط القديم يوجّه مع الحفاظ على المعاملات');
    Assert::contains($nginx, 'try_files $uri $uri/ @shop;', 'nginx: تطبيق صفحة واحدة');
    Assert::contains($nginx, 'fastcgi_param SCRIPT_FILENAME $document_root/index.php;', 'nginx: الجذر يمر على index.php');
    Assert::contains($nginx, 'index index.php;', 'nginx: فهرس الجذر index.php');

    $serve = (string) file_get_contents(BASE_PATH . '/bin/serve.php');
    Assert::contains($serve, '$path === \'/app/index.html\'', 'خادم التطوير: يوحّد الرابط القديم');
    Assert::contains($serve, "\$_SERVER['SCRIPT_NAME']     = '/index.php';", 'خادم التطوير: يضبط موضع السكربت');

    /* ==================== ٥. صفحة الهيكل تُقدَّم فعلًا ==================== */
    Assert::group('التوجيه — تقديم صفحة الهيكل');

    Assert::true(is_file(BASE_PATH . '/index.php'), 'نقطة دخول الجذر موجودة');

    /**
     * نقدّم index.php فعليًا داخل العملية ونفحص الناتج.
     * هذا يثبت أن الروابط المطلقة تُحسب صحيحًا وأن الصفحة تُقدَّم فعلًا،
     * لا أن الملف موجود فحسب.
     *
     * ملاحظة: نكتم تحذيرات الترويسات لأن مخرجات الاختبار السابقة تُعتبر
     * "headers sent" في وضع CLI، وindex.php يضبط ترويسات الاستجابة.
     *
     * @return array{0:string,1:int} [الـ HTML، رمز الحالة]
     */
    $render = static function (string $scriptName, string $requestUri): array {
        $savedServer = $_SERVER;
        set_error_handler(static fn (): bool => true);

        $_SERVER['SCRIPT_NAME']        = $scriptName;
        $_SERVER['REQUEST_URI']        = $requestUri;
        $_SERVER['HTTP_IF_NONE_MATCH'] = '';
        $_SERVER['REQUEST_METHOD']     = 'GET';

        ob_start();
        try {
            require BASE_PATH . '/index.php';
            $html = (string) ob_get_clean();
            $code = http_response_code();
        } catch (Throwable $e) {
            ob_end_clean();
            $html  = 'ERROR: ' . $e->getMessage();
            $code  = 0;
        }

        restore_error_handler();
        $_SERVER = $savedServer;

        return [$html, is_int($code) && $code > 0 ? $code : 200];
    };

    [$shell, $shellCode] = $render('/index.php', '/');
    Assert::same(200, $shellCode, 'الجذر يُقدَّم بحالة 200');
    Assert::contains($shell, 'dir="rtl"', 'الصفحة عربية RTL');
    Assert::contains($shell, 'href="/app/css/app.css?v=', 'ملف الأنماط بمسار مطلق');
    Assert::contains($shell, 'src="/app/js/app.js', 'ملف السكربت بمسار مطلق');
    Assert::contains($shell, '"api":"/api/index.php"', 'مسار الـ API يُمرَّر للواجهة');
    Assert::contains($shell, 'window.APP_CONFIG', 'إعدادات الصفحة محقونة للواجهة');
    Assert::notContains($shell, 'href="css/app.css"', 'لا مسارات نسبية تكسر عند تغيير الجذر');

    [$shellCart] = $render('/index.php', '/cart');
    Assert::contains($shellCart, 'rel="canonical" href="/cart"', 'رابط قياسي لكل صفحة');

    [$shellSub] = $render('/shop/index.php', '/shop/checkout');
    Assert::contains($shellSub, 'href="/shop/app/css/app.css?v=', 'المسارات المطلقة تتبع المجلد الفرعي');
    Assert::contains($shellSub, '"api":"/shop/api/index.php"', 'الـ API يتبع المجلد الفرعي');
    Assert::contains($shellSub, '"base":"/shop/"', 'جذر التطبيق يُمرَّر للواجهة');

    [$notFound] = $render('/index.php', '/app/uploads/missing.png');
    Assert::contains($notFound, '404', 'صفحة 404 مفهومة للمستخدم');
    Assert::contains($notFound, 'الصفحة غير موجودة', 'رسالة 404 بالعربية');
    Assert::notContains($notFound, 'product-grid', '404 لا تُعيد صفحة التطبيق');
    Assert::contains(
        (string) file_get_contents(BASE_PATH . '/index.php'),
        'http_response_code(404)',
        'الملفات المفقودة تردّ بحالة 404 لا 200'
    );

    /* ==================== ٦. واجهة التطبيق تستخدم الروابط النظيفة ==================== */
    Assert::group('التوجيه — تطبيق الواجهة');

    $appJs = (string) file_get_contents(BASE_PATH . '/app/js/app.js');

    Assert::contains($appJs, 'history.pushState', 'التنقل عبر History API (روابط نظيفة)');
    Assert::contains($appJs, "addEventListener('popstate'", 'زر الرجوع في المتصفح يعمل');
    Assert::contains($appJs, 'x-shop-route', 'وضع الهاش احتياطي عند خادم بلا توجيه');
    Assert::contains($appJs, "'order/'", 'مسار متابعة الطلب نظيف');

    // لا مسار app/index.html داخل ملفات الواجهة
    foreach (glob(BASE_PATH . '/app/js/*.js') ?: [] as $file) {
        Assert::notContains((string) file_get_contents($file), 'app/index.html', 'لا app/index.html في ' . basename($file));
    }

    $adminPhp = (string) file_get_contents(BASE_PATH . '/admin/index.php');
    Assert::notContains($adminPhp, '../app/index.html', 'لوحة الإدارة لا ترتبط بمسار ملف داخلي');
    Assert::contains($adminPhp, 'Paths::api(1)', 'لوحة الإدارة تستخدم مسار API مطلقًا');
    Assert::contains($adminPhp, "href=\"<?= htmlspecialchars(\$homeUrl", 'رابط «عرض الموقع» يشير إلى الجذر');

    $setupPhp = (string) file_get_contents(BASE_PATH . '/setup/index.php');
    Assert::notContains($setupPhp, '../app/index.html', 'صفحة التهيئة لا ترتبط بمسار ملف داخلي');
};