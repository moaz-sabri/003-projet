<?php

declare(strict_types=1);

/**
 * حدّ النشر وتطابق البيئات.
 *
 * ## لماذا هذه المجموعة؟
 *
 * لأنها تحرس الخطأ الذي لا يراه أي اختبار آخر: **الفرق الصامت بين
 * بيئة وأخرى**. الاختبارات تعمل على شجرة التطوير الشاملة؛ الحاوية
 * تعمل على إعداد nginx الأول؛ الخادم المباشر على الثاني. إن اختلف
 * أيٌّ منها عن الثالث، فالاختبارات خضراء والموقع مكسور عند المستخدم —
 * وقد حدث ذلك مرتين في هذا المشروع بالضبط:
 *
 *   ١) `setup/` محجوبة في nginx وغير محجوبة في الأداتين → التثبيت
 *      المحلي ينجح والاختبارات تمرّ، والحاوية ترد 404.
 *   ٢) `.htaccess` تحمي المجلدات على Apache بينما nginx لا يقرأها →
 *      حماية «موجودة» في مكان لا يعمل.
 *
 * الفحص هنا أن كل بيئة تُعلن عن القواعد نفسها بالضبط. أي إضافة في
 * واحد دون الآخر تُفشل البناء، لا الإنتاج.
 */

use App\Config\ReleaseManifest;

return static function (): void {

    /* =====================================================================
     * ١. لا ملفات .htaccess — القالب nginx فقط
     * ===================================================================== */
    Assert::group('النشر — القالب nginx فقط');

    $htaccess = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(BASE_PATH, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $skip = ['/.git', '/dist', '/storage/backups', '/storage/logs', '/storage/database', '/storage/tmp'];
    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $path = str_replace('\\', '/', $item->getPathname());
        $rel  = substr($path, strlen(str_replace('\\', '/', BASE_PATH)));
        foreach ($skip as $excluded) {
            if (str_starts_with($rel, $excluded)) {
                continue 2;
            }
        }
        if ($item->isFile() && strtolower($item->getFilename()) === '.htaccess') {
            $htaccess[] = $rel;
        }
    }
    Assert::same([], $htaccess, 'لا ملف .htaccess في أي مكان بالمشروع');

    /*
     * لا تعليمات Apache في إعداد الإنتاج: القالب يختار خادمًا واحدًا
     * ويقوله. وجود سطر Apache واحد يجعل اللوحة تُوهم بدعم لا existe.
     */
    $site = (string) file_get_contents(BASE_PATH . '/deploy/nginx/site.conf');
    foreach (['RewriteRule', 'RewriteCond', '<Files', 'AllowOverride', 'php_flag'] as $apache) {
        Assert::notContains($site, $apache, 'إعداد الإنتاج بلا تعليمة Apache: ' . $apache);
    }
    Assert::contains($site, 'nginx', 'إعداد الإنتاج مخصّص لـ nginx');

    /* =====================================================================
     * ٢. تطابق إعداد Docker وإعداد الإنتاج
     * ===================================================================== */
    Assert::group('النشر — تطابق إعدادَي nginx');

    $docker = (string) file_get_contents(BASE_PATH . '/docker/nginx.conf');

    /*
     * كل قاعدة أمان يجب أن تكون في الملفين.
     *
     * القائمة منطوبة لا حرفية: نعطي سلسلة تُميّز القاعدة في إعداد
     * Docker، وأختها في إعداد الإنتاج. لو reformatted أحدهما دون
     * تغيير المعنى، يفشل الاختبار — وهذا مقصود: تنسيق الملفين
     * ومزامنتهما مسؤولية واحدة.
     */
    $rules = [
        // [القاعدة, في docker/nginx.conf, في deploy/nginx/site.conf, الوصف]
        ['حجب مجلدات الشيفرة والبيانات',
            'location ~ ^/(src|storage|bin|tests|tools|docs|docker|deploy)(/|$)',
            'location ~ ^/(src|storage|bin|tests|tools|docs|docker|deploy)(/|$)'],
        ['حجب الملفات المخفية',
            'location ~ /\. { return 404; }',
            'location ~ /\. { return 404; }'],
        ['حجب ملفات التوثيق',
            'location ~* \.(md)$',
            'location ~* \.(md)$'],
        ['حجب قواعد البيانات والمفاتيح الحسّاسة',
            'location ~* \.(sqlite|sqlite-wal|sqlite-shm|db|key|pem|log|ini)$',
            'location ~* \.(sqlite|sqlite-wal|sqlite-shm|db|key|pem|log|ini)$'],
        ['حجب .env',
            'location ~* (^|/)\.env',
            'location ~* (^|/)\.env'],
        ['حجب الأرشيفات',
            'location ~* \.(zip|tar|gz|tgz|bz2|xz|7z|rar|iso|sql|dump)$',
            'location ~* \.(zip|tar|gz|tgz|bz2|xz|7z|rar|iso|sql|dump)$'],
        ['صفحة التهيئة متاحة',
            'location = /setup/index.php',
            'location = /setup/index.php'],
        ['رابط التدمير الطارئ متاح',
            'location = /emergency.php',
            'location = /emergency.php'],
        ['توجيه /setup',
            'location = /setup {',
            'location = /setup {'],
        ['واجهة API',
            'location = /api/index.php',
            'location = /api/index.php'],
        ['لوحة الإدارة',
            'location = /admin/index.php',
            'location = /admin/index.php'],
        ['منع تنفيذ سكربتات في مجلد الرفع',
            'app/uploads/.*\\.(php',
            'app/uploads/.*\\.(php'],
        ['تطبيق صفحة واحدة',
            'try_files $uri $uri/ @shop;',
            'try_files $uri $uri/ @shop;'],
        ['وجهة الروابط النظيفة',
            'location @shop',
            'location @shop'],
        ['حجب أي PHP غير مسموح',
            'location ~ \.php$',
            'location ~ \.php$'],
        ['ترويسة nosniff',
            'X-Content-Type-Options',
            'X-Content-Type-Options'],
        ['ترويسة منع الإطارات',
            'X-Frame-Options',
            'X-Frame-Options'],
        ['نوع ملف PWA',
            'application/manifest+json',
            'application/manifest+json'],
    ];

    foreach ($rules as [$label, $inDocker, $inSite]) {
        Assert::contains($docker, $inDocker, 'Docker: ' . $label);
        Assert::contains($site, $inSite, 'الإنتاج: ' . $label);
    }

    // setup/ غير محجوب في أيٍّ منهما (كان مصدر فشل حقيقي).
    Assert::notContains($docker, '|setup)(/|$)', 'Docker لا يحجب setup/');
    Assert::notContains($site, '|setup)(/|$)', 'الإنتاج لا يحجب setup/');

    /* =====================================================================
     * ٣. تطابق إعداد nginx مع خادم التطوير
     * ===================================================================== */
    Assert::group('النشر — تطابق nginx مع خادم التطوير');

    $serve = (string) file_get_contents(BASE_PATH . '/bin/serve.php');

    foreach (['/src/', '/storage/', '/bin/', '/tests/', '/tools/', '/docs/', '/docker/'] as $prefix) {
        Assert::contains($serve, "'" . $prefix . "'", 'خادم التطوير يحجب ' . $prefix);
    }
    foreach (['/src', '/storage', '/bin', '/tests', '/tools', '/docs', '/docker'] as $exact) {
        Assert::contains($serve, "'" . $exact . "'", 'خادم التطوير يحجب ' . $exact);
    }

    // الامتدادات الحسّاسة: نفس القائمة في nginx وفي الراوتر
    foreach (['sqlite', 'key', 'pem', 'log', 'ini'] as $ext) {
        Assert::contains($serve, $ext, 'خادم التطوير يحجب امتداد .' . $ext);
        Assert::contains($docker, $ext, 'nginx يحجب امتداد .' . $ext);
        Assert::contains($site, $ext, 'إعداد الإنتاج يحجب امتداد .' . $ext);
    }

    // مداخل PHP المسموحة — نفس المجموعة بالضبط
    Assert::contains($serve, "'/setup/index.php'", 'خادم التطوير يسمح بـ /setup/index.php');
    Assert::contains($serve, "'/api/index.php'", 'خادم التطوير يسمح بـ /api/index.php');
    Assert::contains($serve, "'/admin/index.php'", 'خادم التطوير يسمح بـ /admin/index.php');
    Assert::contains($serve, "'/emergency.php'", 'خادم التطوير يسمح بـ /emergency.php');

    /*
     * setup/ ليست في قوائم الحجب.
     *
     * الفحص على نصّ الملف كله لا يكفي: `=== '/setup'` (قاعدة إعادة
     * التوجيه) و`'/setup/index.php'` (مدخل مسموح) تحتويان النص نفسه،
     * فالبحث الساذج يقرأExist كأن setup محجوب — أو مسموح — بالطريقة
     * المعاكسة. لذلك نستخرج القائمتين ونفحصهما وحدهما.
     */
    Assert::true(
        (bool) preg_match('/\$blockedPrefixes\s*=\s*\[(.*?)\]/s', $serve, $prefixes),
        'خادم التطوير: قائمة المحظورات البادئة مقروءة'
    );
    Assert::true(
        (bool) preg_match('/\$blockedExact\s*=\s*\[(.*?)\]/s', $serve, $exact),
        'خادم التطوير: قائمة المحظورات الصريحة مقروءة'
    );
    Assert::notContains($prefixes[1] ?? '', '/setup', 'setup/ ليست في المحظورات البادئة');
    Assert::notContains($exact[1] ?? '', '/setup', 'setup/ ليست في المحظورات الصريحة');
    Assert::contains($prefixes[1] ?? '', '/storage/', 'المحظورات البادئة تحجب storage/');
    Assert::contains($exact[1] ?? '', "'/storage'", 'المحظورات الصريحة تحجب /storage');

    /* =====================================================================
     * ٤. حدّ النشر
     * ===================================================================== */
    Assert::group('النشر — ما يُشحن وما لا يُشحن');

    $files = ReleaseManifest::files(BASE_PATH);
    Assert::greater(count($files), 20, 'بيان الإصدار يحدّد ملفات كافية');

    // كل مسار من Observatory لا يجوز نشره
    foreach (['tests/', 'tools/', 'docs/', 'docker/', '.github/', 'dist/'] as $never) {
        $leaked = array_values(array_filter(
            $files,
            static fn(string $f): bool => str_starts_with($f, $never)
        ));
        Assert::same([], $leaked, 'لا شيء من ' . $never . ' في بيان الإصدار');
    }

    // ولا ملف تطوير مفرد
    foreach (ReleaseManifest::EXCLUDE_FILES as $dev) {
        Assert::false(in_array($dev, $files, true), 'غير مُشحن: ' . $dev);
    }

    // ولا بيانات — مع استثناء ملفات .gitkeep التي تُنشئ المجلدات الفارغة
    $secrets = array_values(array_filter($files, static function (string $f): bool {
        if (basename($f) === '.gitkeep') {
            return false;
        }
        return str_contains($f, 'local.php')
            || str_ends_with($f, '.sqlite')
            || str_contains($f, 'company.json')
            || str_contains($f, '/backups/')
            || str_contains($f, '/destroyed/');
    }));
    Assert::same([], $secrets, 'لا بيانات نسخة في بيان الإصدار');

    // وكل ما لا بدّ منه موجود
    foreach ([
        'index.php', 'sw.js', 'setup/index.php', 'bin/console',
        'deploy/nginx/site.conf', 'src/Config/ReleaseManifest.php',
        'app/index.html', 'app/manifest.webmanifest',
    ] as $must) {
        Assert::true(in_array($must, $files, true), 'مُشحن: ' . $must);
    }

    // أدوات البناء نفسها لا تُشحن — لا تُشحن أداةُ البناء مع المخرج
    Assert::false(in_array('tools/build-release.php', $files, true), 'أداة البناء لا تُشحن');
    Assert::false(in_array('bin/serve.php', $files, true), 'خادم التطوير لا يُشحن');

    /* =====================================================================
     * ٥. المجلدات الفارغة التي يحتاجها التثبيت
     * ===================================================================== */
    Assert::group('النشر — مجلدات جاهزة للتثبيت');

    foreach (['config', 'database', 'backups', 'logs', 'tmp', 'uploads'] as $sub) {
        Assert::true(
            in_array('storage/' . $sub . '/.gitkeep', $files, true),
            'بيان الإصدار يهيّئ storage/' . $sub
        );
    }

    /* =====================================================================
     * ٦. كل require يشير إلى ملف ضمن بيان الإصدار
     * ===================================================================== */
    Assert::group('النشر — لا مراجع مكسورة في الحزمة');

    $broken = [];
    foreach ($files as $rel) {
        if (!str_ends_with($rel, '.php')) {
            continue;
        }
        foreach (ReleaseManifest::requires($rel) as $ref) {
            if (!in_array($ref['to'], $files, true)) {
                $broken[] = $rel . ' → ' . $ref['target'];
            }
        }
    }
    Assert::same([], $broken, 'كل require داخل القالب يشير إلى ملف مُشحن');
};
