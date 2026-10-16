<?php

declare(strict_types=1);

/**
 * راوتر خادم PHP المدمج (للتطوير المحلي والاختبار فقط).
 *
 *   php -S localhost:8000 bin/serve.php
 *
 * خادم PHP المدمج لا يقرأ ملفات .htaccess، لذا نطبّق قواعد الحماية
 * البرمجية هنا: منع الوصول إلى src/ و storage/ و bin/ و tests/ و docker/،
 * ومنع تنفيذ أي سكربت داخل app/uploads، وتوجيه كل المسارات غير الملفية
 * إلى index.php — تمامًا كما يفعل Apache عبر .htaccess و nginx عبر
 * try_files. بهذه الطريقة تُختبر الروابط النظيفة (/cart، /checkout)
 * محليًا بنفس سلوك الإنتاج.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . ltrim(rawurldecode($path), '/');
$query = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '';

/* --------------------------- مسارات ممنوعة --------------------------- */
/*
 * setup/ ليست محجوبة هنا عن قصد: صفحة التهيئة تعمل مرة واحدة ثم ترفض العمل،
 * وملف .htaccess لا يحجبها أيضًا. كانت محجوبة في هذا الراوتر فصار التثبيت
 * المحلي عبر /setup/ مستحيلًا، أي أن أداة التطوير لم تطابق الإنتاج في أهم
 * خطوة على الإطلاق. الحماية الحقيقية بعد التهيئة: احذف مجلد setup/.
 */
$blockedPrefixes = ['/src/', '/storage/', '/bin/', '/tests/', '/tools/', '/docs/', '/docker/', '/vendor/'];
$blockedExact    = [
    '/src', '/storage', '/bin', '/tests', '/tools', '/docs', '/docker', '/vendor',
    '/.htaccess', '/.env', '/.git', '/composer.json', '/composer.lock', '/Dockerfile',
    '/README.md', '/CHANGELOG.md', '/AUDIT_REPORT.md',
];

if (in_array(rtrim($path, '/'), $blockedExact, true)) {
    http_response_code(404);
    echo '404 Not Found';
    return true;
}

foreach ($blockedPrefixes as $prefix) {
    if (str_starts_with($path, $prefix)) {
        http_response_code(404);
        echo '404 Not Found';
        return true;
    }
}

// منع الوصول لأي ملف مخفي
if (preg_match('#/(?:^|/)\.#', $path)) {
    http_response_code(404);
    echo '404 Not Found';
    return true;
}

// منع خدمة الأرشيفات: أرشيف في جذر الموقع يحمل نسخة كاملة من المشروع
// (تجزئة كلمة مرور المدير + قاعدة بيانات الطلبات) ولا سبب لوجوده.
if (preg_match('/\.(zip|tar|gz|tgz|bz2|xz|7z|rar|iso|sql|dump)$/i', $path)) {
    http_response_code(404);
    echo '404 Not Found';
    return true;
}

/* ------------------- منع تنفيذ السكربتات داخل الرفع ------------------- */
if (preg_match('#^/app/uploads/#', $path) && preg_match('/\.(php\d?|phtml|phar|cgi|pl|py|sh)$/i', $path)) {
    http_response_code(403);
    echo '403 Forbidden';
    return true;
}

/* --------------------------- روابط موحّدة (301) --------------------------- */
// قبلخدمة الملفات، وإلا قُدِّم /app/index.html كما هو بدل تحويله إلى الجذر.
$redirect = static function (string $target): void {
    header('Location: ' . $target, true, 301);
};

if ($path === '/app/index.html' || $path === '/app/' || $path === '/index.php') {
    $redirect('/' . ($query !== '' ? '?' . $query : ''));
    return true;
}

if ($path === '/admin') {
    $redirect('/admin/');
    return true;
}

if ($path === '/setup') {
    $redirect('/setup/');
    return true;
}

/* --------------------------- ملفات PHP مسموحة --------------------------- */
$allowedPhp = ['/api/index.php', '/admin/index.php', '/setup/index.php', '/bin/console'];

if (preg_match('/\.php$/i', $path)) {
    $script = realpath(__DIR__ . '/..' . $path);
    $base   = realpath(__DIR__ . '/..');

    if ($script === false || $base === false || !str_starts_with($script, $base)) {
        http_response_code(404);
        echo '404 Not Found';
        return true;
    }

    if (!in_array($path, $allowedPhp, true)) {
        http_response_code(404);
        echo '404 Not Found';
        return true;
    }

    $_SERVER['SCRIPT_FILENAME'] = $script;
    $_SERVER['SCRIPT_NAME']     = $path;
    require $script;
    return true;
}

/* ------------------------------ ملفات ثابتة ------------------------------ */
$root = realpath(__DIR__ . '/..');
$file = realpath($root . $path);

if ($file !== false && str_starts_with($file, $root) && is_file($file)) {
    $types = [
        'html' => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'webmanifest' => 'application/manifest+json; charset=utf-8',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'ico'  => 'image/x-icon',
        'txt'  => 'text/plain; charset=utf-8',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    if ($ext !== 'html') {
        header('Cache-Control: no-cache');
    }
    readfile($file);
    return true;
}

/* ---------------------- لوحة الإدارة (ملف PHP) ---------------------- */
if ($path === '/admin/') {
    $_SERVER['SCRIPT_FILENAME'] = realpath(__DIR__ . '/../admin/index.php');
    $_SERVER['SCRIPT_NAME']     = '/admin/index.php';
    require $_SERVER['SCRIPT_FILENAME'];
    return true;
}

/* --------- تطبيق صفحة واحدة: / و /cart و /checkout و /order/… --------- */
$_SERVER['SCRIPT_FILENAME'] = realpath(__DIR__ . '/../index.php');
$_SERVER['SCRIPT_NAME']     = '/index.php';
require $_SERVER['SCRIPT_FILENAME'];
return true;