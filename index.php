<?php

declare(strict_types=1);

/**
 * واجهة الزبون — نقطة الدخول على جذر الموقع.
 *
 * لماذا هذا الملف؟
 *   كان الموقع يقدّم الواجهة على المسار /app/index.html، فيظهر هذا الاسم في
 *   رموز QR المطبوعة وفي شريط العنوان وعند مشاركة الروابط. الآن الجذر "/"
 *   هو الصفحة الواحدة لكل الواجهة، وهذا الملف هو ما يقدّمها:
 *
 *     /                  القائمة
 *     /cart              السلة
 *     /checkout          إتمام الطلب
 *     /order/{رمز}       متابعة الطلب
 *     /product/{رقم}     القائمة مع فتح منتج
 *
 * قواعد تشغيل مقصودة:
 *   - لا يلمس قاعدة البيانات إطلاقًا: يعمل قبل التهيئة وبعدها بلا استثناء.
 *   - المسارات النسبية داخل app/index.html تُحوَّل إلى مسارات مطلقة تحسبًا من
 *     موضع السكربت، ليعمل القالب على جذر النطاق أو داخل مجلد فرعي (/shop/).
 *   - أي مسار يبدو كملف (له امتداد) يُردّ عليه 404 بدل إرجاع صفحة HTML، حتى لا
 *     يتحوّل خطأ صورة أو سكربت مفقود إلى صفحة صامتة يصعب تشخيصها.
 *   - الصفحة تُقدَّم بـ ETag وترويسة "no-cache" فلا تُخزَّن نسخة قديمة منها،
 *     بينما ملفات app/css وapp/js تُخدم بذاكرة وسيط.cache لمدة أسبوع.
 */

require_once __DIR__ . '/src/Core/Autoloader.php';

use App\Config\ProjectConfig;
use App\Core\Web\Paths;

/* ------------------------------------------------------------------ */
/* 1) المسار المطلوب                                                   */
/* ------------------------------------------------------------------ */

$route = Paths::route();

/**
 * صفحة 404 بسيطة بنفس هوية الواجهة (لا ملف خارجي ولا اعتماد على قاعدة البيانات).
 */
$notFound = static function (string $base): void {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $home = htmlspecialchars($base, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>الصفحة غير موجودة</title>
<style>
  :root { color-scheme: light dark; }
  body {
    margin: 0; min-height: 100dvh; display: grid; place-items: center; padding: 24px;
    background: #f7f6f3; color: #17181c; text-align: center;
    font-family: "Segoe UI", system-ui, "Noto Kufi Arabic", Tahoma, Arial, sans-serif;
  }
  .box { max-width: 380px; }
  .code { font-size: 3rem; font-weight: 750; letter-spacing: -.03em; color: #c2410c; }
  h1 { font-size: 1.2rem; margin: 6px 0 8px; }
  p { margin: 0 0 22px; color: #5b5f6a; font-size: .95rem; line-height: 1.7; }
  a {
    display: inline-block; padding: 12px 22px; border-radius: 14px; text-decoration: none;
    background: #c2410c; color: #fff; font-weight: 650;
  }
  @media (prefers-color-scheme: dark) {
    body { background: #131417; color: #f2f3f5; }
    p { color: #a9aeb8; }
  }
</style>
</head>
<body>
  <div class="box">
    <div class="code">404</div>
    <h1>الصفحة غير موجودة</h1>
    <p>الرابط الذي فتحته غير صحيح أو تم نقله.<br>ارجع إلى القائمة وتابع من هناك.</p>
    <a href="{$home}">العودة إلى القائمة</a>
  </div>
</body>
</html>
HTML;
};

// مسار يشبه ملفًا (صورة/سكربت/نمط مفقود) لا يجب أن يُحوَّل إلى صفحة HTML
if (Paths::looksLikeFile($route)) {
    $notFound(Paths::base());
    return true;
}

/**
 * فحص دعم المسارات النظيفة: الواجهة ترسل X-LC-Probe مرة واحدة، فيردّ الخادم
 * برأس X-Shop-Route وجسم فارغ. وجود الرأس يعني أن الخادم يوجّه المسارات،
 * فيستخدم التطبيق روابط نظيفة، وإلا تحوّل إلى وضع الهاش بدل روابط 404.
 */
if (strtoupper((string) ($_SERVER['HTTP_X_LC_PROBE'] ?? '')) !== '') {
    header('X-Shop-Route: ' . ($route === '' ? '/' : $route));
    header('Cache-Control: no-store');
    header('Content-Length: 0');
    http_response_code(204);
    return true;
}

/* ------------------------------------------------------------------ */
/* 2) القالب                                                          */
/* ------------------------------------------------------------------ */

$templatePath = BASE_PATH . '/app/index.html';

if (!is_file($templatePath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ملف الواجهة app/index.html غير موجود.';
    return true;
}

$etag = '"' . md5((string) filemtime($templatePath) . '|' . Paths::base() . '|' . ProjectConfig::VERSION) . '"';

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    return true;
}

$html = (string) file_get_contents($templatePath);

/* ------------------------------------------------------------------ */
/* 3) المسارات المطلقة + إعدادات التشغيل                               */
/* ------------------------------------------------------------------ */

$base = Paths::base();

/**
 * تحويل كل رابط نسبي في القالب إلى مسار مطلق داخل app/.
 * تُترك كما هي: المسارات المطلقة، والروابط ذات Scheme (tel:/mailto:/data:)،
 * والروابط الابتدائية (#) لأنها لا تحتاج معالجة.
 */
$html = (string) preg_replace_callback(
    '/\b(href|src)="([^"]+)"/i',
    static function (array $m) use ($base): string {
        $url = $m[2];
        if ($url === '' || $url[0] === '/' || $url[0] === '#' || str_starts_with($url, '//')
            || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            return $m[0];
        }
        return $m[1] . '="' . $base . 'app/' . $url . '"';
    },
    $html
);

// اللغة والاتجاه يأتيان من ProjectConfig (ليتغيّرا مع القالب دون لمس القالب)
$html = str_replace(
    'lang="ar" dir="rtl"',
    'lang="' . htmlspecialchars(ProjectConfig::LOCALE, ENT_QUOTES, 'UTF-8') . '" dir="'
        . htmlspecialchars(ProjectConfig::DIRECTION, ENT_QUOTES, 'UTF-8') . '"',
    $html
);

$nonce = base64_encode(random_bytes(16));

$config = [
    'base'    => $base,
    'api'     => Paths::api(),
    'sw'      => Paths::serviceWorker(),
    'home'    => Paths::home(),
    'version' => ProjectConfig::VERSION,
];

/** يُحقن قبل أول سكربت ليقرأه كل ملف لاحق. */
$bootstrap = '<script nonce="' . $nonce . '">window.APP_CONFIG='
    . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
    . ';</script>';

$html = (string) preg_replace('/<script\b/', $bootstrap . "\n" . '<script', $html, 1);

// رابط قياسي للصفحة (بلا اسم ملف داخلي) — تستفيد منه الأدوات ومحركات البحث
$canonical = Paths::home() . $route;
$html = str_replace(
    '<link rel="manifest"',
    '<link rel="canonical" href="' . htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') . '">' . "\n  " . '<link rel="manifest"',
    $html
);

/* ------------------------------------------------------------------ */
/* 4) الترويسات                                                        */
/* ------------------------------------------------------------------ */

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('ETag: ' . $etag);
// لا تُخزَّن نسخة قديمة من الواجهة (ملفاتها مقيّدة بسمة ?v=)
header('Cache-Control: no-cache, must-revalidate');

header('Content-Security-Policy: '
    . "default-src 'self'; "
    . "img-src 'self' data:; "
    . "style-src 'self' 'unsafe-inline'; "
    . "script-src 'self' 'nonce-" . $nonce . "'; "
    . "connect-src 'self'; "
    . "form-action 'self'; "
    . "base-uri 'none'; "
    . "frame-ancestors 'self'");

// رأس تشخيصي: يوضّح أي مسار داخلي قُدِّم (تستخدمه فحوص النشر)
header('X-Shop-Route: ' . ($route === '' ? '/' : $route));

echo $html;
return true;