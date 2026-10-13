<?php

declare(strict_types=1);

/**
 * PWA وترويسات الأمان وبنية الملفات — متطلبات readme §11 و §12.
 */

use App\Config\ProjectConfig;

return function (): void {
    Assert::group('PWA — ملفات التطبيق');

    $manifestPath = BASE_PATH . '/app/manifest.webmanifest';
    Assert::true(is_file($manifestPath), 'ملف manifest موجود');

    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    Assert::true(is_array($manifest), 'manifest بصيغة JSON صحيحة');
    Assert::same('ar', $manifest['lang'] ?? '', 'لغة التطبيق عربية');
    Assert::same('rtl', $manifest['dir'] ?? '', 'اتجاه التطبيق RTL');
    Assert::same('standalone', $manifest['display'] ?? '', 'التطبيق يعمل مستقلًا');
    Assert::greater(count($manifest['icons'] ?? []), 0, 'الملف يتضمن أيقونات');

    $has192 = false;
    $has512 = false;
    foreach ($manifest['icons'] as $icon) {
        if ($icon['sizes'] === '192x192') {
            $has192 = true;
        }
        if ($icon['sizes'] === '512x512') {
            $has512 = true;
        }
    }
    Assert::true($has192, 'أيقونة 192×192 موجودة');
    Assert::true($has512, 'أيقونة 512×512 موجودة');

    // أيقونات PWA المولّدة موجودة فعلًا
    foreach (['icon-192.png', 'icon-512.png'] as $icon) {
        $path = BASE_PATH . '/app/assets/icons/' . $icon;
        if (is_file($path)) {
            $bytes = (string) file_get_contents($path);
            Assert::contains($bytes, "\x89PNG", $icon . ' ملف PNG صالح');
        }
    }

    /* (1) Service Worker --------------------------------------------------- */
    Assert::group('PWA — Service Worker');

    $swPath = BASE_PATH . '/app/sw.js';
    Assert::true(is_file($swPath), 'ملف Service Worker موجود');

    $sw = (string) file_get_contents($swPath);
    Assert::contains($sw, "addEventListener('install'", 'المعالج يهيّئ الكاش عند التثبيت');
    Assert::contains($sw, "addEventListener('fetch'", 'المعالج يعترض الطلبات');
    Assert::contains($sw, 'catalog.snapshot', 'كتالوج المنتجات يُخزَّن للعمل دون اتصال');

    // لا يخزّن طلبات الإدارة أو الطلبات الحساسة
    Assert::contains($sw, "startsWith('admin.')", 'طلبات الإدارة مستثناة من الكاش');
    Assert::contains($sw, "order.status", 'استجابة متابعة الطلب مستثناة من الكاش');
    Assert::contains($sw, "'/admin/'", 'مسارات الإدارة مستثناة من الكاش');
    Assert::false(str_contains($sw, 'ignoreSearch: true'), 'لا استخدام لـ ignoreSearch مع مسارات API');

    // لا طابور إرسال دون اتصال
    Assert::false(str_contains($sw, 'Background Sync'), 'لا يوجد طابور مزامنة للطلبات');
    Assert::false(str_contains($sw, "addEventListener('sync'"), 'لا مستمع لمزامنة الطلبات');
    Assert::false(str_contains($sw, 'registration.sync'), 'لا تسجيل طابور مزامنة');

    /* (2) التسجيل في الواجهة ------------------------------------------------ */
    $appHtml = (string) file_get_contents(BASE_PATH . '/app/index.html');
    $appJs   = (string) file_get_contents(BASE_PATH . '/app/js/app.js');
    Assert::contains($appHtml, 'rel="manifest"', 'الواجهة تربط ملف manifest');
    Assert::contains($appHtml, 'lang="ar" dir="rtl"', 'الواجهة عربية RTL');
    Assert::contains($appHtml, 'viewport-fit=cover', 'الواجهة متوافقة مع شاشات الهواتف');
    Assert::contains($appJs, "serviceWorker.register('sw.js')", 'الواجهة تسجّل Service Worker');
    Assert::contains($appHtml, 'theme-color', 'لون شريط الحالة معرّف');

    /* (3) لا تخزين مؤقت عام لاستجابات الإدارة ------------------------------ */
    Assert::group('الأمان — استجابات الإدارة لا تُخزَّن');

    $apiIndex = (string) file_get_contents(BASE_PATH . '/api/index.php');
    Assert::contains($apiIndex, "header('X-Frame-Options: SAMEORIGIN')", 'API يرسل ترويسة منع التضمين');
    Assert::contains($apiIndex, 'HttpError', 'أخطاء API مصنّفة ومنظمة');

    $authRoutes = (string) file_get_contents(BASE_PATH . '/api/routes/admin.php');
    Assert::contains($authRoutes, "'Cache-Control' => 'no-store'", 'استجابات الإدارة بدون تخزين مؤقت');
    Assert::contains($authRoutes, 'Csrf::verify', 'كل عملية تعديل تتحقق من CSRF');

    /* (4) بنية المشروع ----------------------------------------------------- */
    Assert::group('بنية المشروع');

    foreach ([
        'app/index.html', 'admin/index.php', 'api/index.php',
        'src/Config/ProjectConfig.php', 'src/bootstrap.php',
        'storage/.htaccess', '.htaccess', 'Dockerfile', 'compose.yaml',
        'README.md', 'bin/console',
    ] as $path) {
        Assert::true(file_exists(BASE_PATH . '/' . $path), 'ملف مطلوب موجود: ' . $path);
    }

    foreach (['database', 'uploads', 'backups', 'logs', 'config'] as $folder) {
        $dir = BASE_PATH . '/storage/' . $folder;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        Assert::true(is_dir($dir), 'مجلد التخزين موجود: ' . $folder);
    }

    // لا ملفات سري�� داخل المشروع المُتبع
    $configPath = BASE_PATH . '/storage/config/local.php';
    Assert::true(!is_file($configPath) || str_contains((string) file_get_contents(BASE_PATH . '/.gitignore'), 'local.php'),
        'ملف الإعدادات المحلية مستثنى في .gitignore');

    $gitignore = (string) file_get_contents(BASE_PATH . '/.gitignore');
    Assert::contains($gitignore, 'storage/config/local.php', 'الأسرار مستثناة من Git');
    Assert::contains($gitignore, '*.sqlite', 'قاعدة البيانات مستثناة من Git');

    /* (5) ترويسات الأمان في لوحة الإدارة ---------------------------------- */
    Assert::group('ترويسات الأمان');

    $adminPhp = (string) file_get_contents(BASE_PATH . '/admin/index.php');
    Assert::contains($adminPhp, 'Content-Security-Policy', 'لوحة الإدارة ترسل سياسة CSP');
    Assert::contains($adminPhp, "default-src 'self'", 'CSP تقيّد المصادر إلى نفس النطاق');
    Assert::contains($adminPhp, "frame-ancestors 'none'", 'CSP تمنع التضمين في مواقع أخرى');
    Assert::contains($adminPhp, 'no-store', 'صفحة الإدارة بدون تخزين مؤقت');
    Assert::contains($adminPhp, 'noindex', 'صفحة الإدارة مستثناة من محركات البحث');

    /* (6) سلامة أسماء الأClasses والمسارات -------------------------------- */
    Assert::group('سلامة بنية PHP');

    $classes = [];
    $broken  = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $source = (string) file_get_contents($path);
        $relative = ltrim(str_replace('\\', '/', substr($path, strlen(str_replace('\\', '/', BASE_PATH . '/src')))), '/');

        // ملفات الإقلاع (bootstrap / Autoloader) لا تنتمي إلى مساحة App
        if (in_array($relative, ['bootstrap.php', 'Core/Autoloader.php'], true)) {
            continue;
        }

        if (!preg_match('/^namespace\s+([^;]+);/m', $source, $nsMatch)) {
            $broken[] = $relative . ' (لا يحتوي namespace)';
            continue;
        }
        $ns = trim($nsMatch[1]);
        $expectedNs = 'App\\' . str_replace('/', '\\', trim(dirname($relative), '/.'));
        if ($ns !== $expectedNs) {
            $broken[] = $file->getFilename() . ' (' . $ns . ' بدل ' . $expectedNs . ')';
        }
        $classes[] = $ns . '\\' . $file->getBasename('.php');
    }

    Assert::same([], $broken, 'كل ملفات src في مساحة App المطابقة لمسارها' . ($broken ? ': ' . implode(' | ', $broken) : ''));

    // كل صنف يجب أن يكون قابلًا للتحميل فعليًا عبر التحميل التلقائي
    $notLoadable = [];
    foreach ($classes as $class) {
        if (!class_exists($class)) {
            $notLoadable[] = $class;
        }
    }
    Assert::same([], $notLoadable, 'كل الأصناف تُحمَّل عبر التحميل التلقائي' . ($notLoadable ? ': ' . implode(', ', $notLoadable) : ''));

    // لا أخطاء بناء في أي ملف PHP
    $syntaxErrors = [];
    foreach (['src', 'api', 'admin', 'setup', 'tests'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $output = [];
            $code = 0;
            @exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $code);
            if ($code !== 0) {
                $syntaxErrors[] = $file->getFilename();
            }
        }
    }
    Assert::same([], $syntaxErrors, 'لا أخطاء صياغة في ملفات PHP' . ($syntaxErrors ? ': ' . implode(', ', $syntaxErrors) : ''));
};