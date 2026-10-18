<?php

declare(strict_types=1);

/**
 * فحص حزمة الإنتاج قبل الرفع — dist/
 *
 *   php tools/deploy-check.php                        # يفحص dist/light-commerce-*
 *   php tools/deploy-check.php /path/to/package       # يفحص مجلدًا محددًا
 *
 * يطبع رقم نجاح/فشل مثل بقية الأدوات، ويعيد 0 عند النجاح و1 عند الفشل
 * — فيستطيع CI و build-release.xml استدعاؤه مباشرة.
 *
 * ## لماذا هذا الفحص؟
 *
 * لأن «حزمة النشر» ليست ملفًا يُبنى مرة واحدة، بل وعدٌ يتكرر: كل ملف
 * جديد يُنسخ، وكل استثناء يُضاف. الاختبارات تمرّ على شجرة التطوير
 * الشاملة، فلا ترى ما يسرّب إلى الحزمة — وهي المساحة التي لا يغطّيها
 * أي اختبار آخر.
 *
 * أعمق سؤال هنا ليس عدد الملفات، بل: هل ما في الحزمة هو بالضبط ما
 * كتبناه؟ كل ما لم يشهد له البيان يُعدّ خطأ، لا finesse.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("أداة سطر الأوامر تعمل من الطرفية فقط.\n");
}

require_once __DIR__ . '/../src/Core/Autoloader.php';

use App\Config\ProjectConfig;
use App\Config\ReleaseManifest;

$passed   = 0;
$failed   = 0;
$failures = [];

$check = static function (bool $ok, string $msg, string $detail = '') use (&$passed, &$failed, &$failures): void {
    if ($ok) {
        $passed++;
        echo "  \033[32m✓\033[0m " . $msg . "\n";
    } else {
        $failed++;
        $failures[] = $msg . ($detail !== '' ? ' (' . $detail . ')' : '');
        echo "  \033[31m✗\033[0m " . $msg . "\n      \033[31m" . $detail . "\033[0m\n";
    }
};
$group = static function (string $name): void {
    echo "\n\033[1m" . $name . "\033[0m\n";
};

/* ------------------------------------------------------------------ */
/* تحديد الحزمة                                                       */
/* ------------------------------------------------------------------ */

$pkg = $argv[1] ?? '';
if ($pkg === '') {
    $candidates = glob(BASE_PATH . '/dist/light-commerce-*', GLOB_ONLYDIR) ?: [];
    if ($candidates === []) {
        fwrite(STDERR, "لا توجد حزمة في dist/. شغّل أولًا: php tools/build-release.php\n");
        exit(1);
    }
    rsort($candidates);
    $pkg = $candidates[0];
}
$pkg = rtrim(str_replace('\\', '/', $pkg), '/');

if (!is_dir($pkg)) {
    fwrite(STDERR, "المجلد غير موجود: {$pkg}\n");
    exit(1);
}

echo "فحص حزمة الإنتاج\n";
echo str_repeat('─', 62) . "\n";
echo "  الحزمة: {$pkg}\n";

/* ------------------------------------------------------------------ */
/* ١. الملفات                                                         */
/* ------------------------------------------------------------------ */

$group('١. محتوى الحزمة مطابق للبيان');

$actual = [];
$items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pkg, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($items as $item) {
    if ($item->isFile()) {
        $actual[] = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($pkg))), '/');
    }
}
sort($actual);

$expected = array_merge(
    ReleaseManifest::files(BASE_PATH),
    ReleaseManifest::GENERATED_FILES
);

// ملف في الحزمة لا يقرّره البيان = نُسي استثناءُه.
$known       = array_flip($expected);
$unexpected  = array_values(array_filter($actual, static fn(string $f): bool => !isset($known[$f])));
$check($unexpected === [], 'لا ملف في الحزمة خارج بيان الإصدار', implode(', ', array_slice($unexpected, 0, 6)));

// ملف ينص عليه البيان ولم يُنسخ = الحزمة بُنيت من شجرة مختلفة.
$present = array_flip($actual);
$missing = array_values(array_filter($expected, static fn(string $f): bool => !isset($present[$f])));
$check($missing === [], 'كل ملف ينص عليه البيان موجود', implode(', ', array_slice($missing, 0, 6)));

$check(count($actual) > 20, 'الحزمة تحوي محتوى كافيًا', count($actual) . ' ملف');

/* ------------------------------------------------------------------ */
/* ٢. تسريبات — الأخطر                                                */
/* ------------------------------------------------------------------ */

$group('٢. لا بيانات ولا أدوات تطوير في الحزمة');

$forbidden = [
    'storage/config/local.php'  => 'بيانات دخول المدير',
    'storage/database/app.sqlite' => 'قاعدة بيانات الزبائن',
    'storage/backups'            => 'نسخ احتياطية',
    'storage/logs'              => 'سجلات',
    'storage/destroyed'         => 'نسخ أمان التدمير',
    'app/data/company.json'     => 'محتوى نسخة أخرى',
    'app/uploads/products'      => 'صور مرفوعة',
    'tests'                     => 'اختبارات آلية',
    'tools'                     => 'أدوات فحص',
    'docs'                      => 'توثيق',
    'docker'                    => 'بيئة تطوير',
    '.github'                   => 'خطوط CI',
    '.git'                      => 'مستودع كامل',
    'bin/serve.php'             => 'خادم تطوير',
    'Dockerfile'                => 'ملف بناء',
    'compose.yaml'              => 'ملف بناء',
    '.gitignore'                => 'ملف تطوير',
    'README.md'                 => 'توثيق',
    'CHANGELOG.md'              => 'توثيق',
    'AUDIT_REPORT.md'           => 'توثيق',
    '.htaccess'                 => 'ملف حماية قديم — القالب nginx فقط',
];

foreach ($forbidden as $path => $why) {
    $full   = $pkg . '/' . $path;
    $exists = is_file($full)
        || (is_dir($full) && count(array_filter(glob($full . '/*') ?: [], 'is_file')) > 0);
    $check(!$exists, 'لا ' . $path . ' (' . $why . ')');
}

$riskyExt = ['sqlite', 'sqlite-wal', 'sqlite-shm', 'zip', 'tar', 'gz', 'tgz', '7z', 'rar',
    'env', 'log', 'key', 'pem', 'sql', 'dump', 'bak', 'tmp'];
$risky = array_values(array_filter($actual, static function (string $f) use ($riskyExt): bool {
    return in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $riskyExt, true);
}));
$check($risky === [], 'لا ملف بامتداد حسّاس في الحزمة', implode(', ', array_slice($risky, 0, 6)));

$hidden = array_values(array_filter(
    $actual,
    static fn(string $f): bool => str_starts_with(basename($f), '.') && basename($f) !== '.gitkeep'
));
$check($hidden === [], 'لا ملف مخفي غير مقصود', implode(', ', array_slice($hidden, 0, 6)));

/* ------------------------------------------------------------------ */
/* ٣. ما لا بد من وجوده                                               */
/* ------------------------------------------------------------------ */

$group('٣. ما لا بد منه ليعمل الموقع');

$required = [
    'index.php'                       => 'واجهة الزبون',
    'sw.js'                           => 'عامل الخدمة',
    'api/index.php'                   => 'نقطة دخول الـ API',
    'admin/index.php'                 => 'لوحة الإدارة',
    'setup/index.php'                 => 'التهيئة وإعادة البناء',
    'src/bootstrap.php'               => 'نقطة تحميل الكود',
    'src/Config/ProjectConfig.php'    => 'إعدادات البنية',
    'src/Config/ReleaseManifest.php'  => 'حدّ الإصدار',
    'src/Config/TemplateContract.php' => 'عقد القالب',
    'src/Services/InstanceService.php' => 'دورة حياة النسخة',
    'bin/console'                     => 'الصيانة على الخادم',
    'deploy/nginx/site.conf'          => 'إعداد nginx',
    'app/index.html'                  => 'قالب الواجهة',
    'app/js/app.js'                   => 'منطق الواجهة',
    'app/css/app.css'                 => 'تنسيق الواجهة',
    'app/manifest.webmanifest'        => 'بيان PWA',
];

foreach ($required as $path => $why) {
    $check(is_file($pkg . '/' . $path), 'موجود: ' . $path . ' (' . $why . ')');
}

foreach (['config', 'database', 'backups', 'logs', 'tmp', 'uploads'] as $sub) {
    $check(is_dir($pkg . '/storage/' . $sub), 'مجلد جاهز: storage/' . $sub);
}
$check(is_dir($pkg . '/app/uploads/products'), 'مجلد جاهز: app/uploads/products');

/* ------------------------------------------------------------------ */
/* ٤. مراجع الملفات                                                   */
/* ------------------------------------------------------------------ */

$group('٤. كل require يشير إلى ملف موجود في الحزمة');

$broken = [];
foreach ($actual as $rel) {
    if (!str_ends_with($rel, '.php')) {
        continue;
    }
    foreach (ReleaseManifest::requires($rel, (string) file_get_contents($pkg . '/' . $rel)) as $ref) {
        if (!in_array($ref['to'], $actual, true)) {
            $broken[] = $rel . ' → ' . $ref['target'];
        }
    }
}
$check($broken === [], 'لا مرجع مكسور', implode(' | ', array_slice($broken, 0, 5)));

/* ------------------------------------------------------------------ */
/* ٥. إعداد nginx                                                     */
/* ------------------------------------------------------------------ */

$group('٥. إعداد nginx يحمي ما كانت تحميه .htaccess');

$conf = (string) @file_get_contents($pkg . '/deploy/nginx/site.conf');
$check($conf !== '', 'deploy/nginx/site.conf موجود ومقروء');

$mustHave = [
    'location ~ ^/(src|storage|bin|tests|tools|docs|docker|deploy)(/|$)' => 'حجب مجلدات الشيفرة والبيانات',
    'location ~ /\. { return 404; }'                                => 'حجب الملفات المخفية',
    '(sqlite|sqlite-wal|sqlite-shm|db|key|pem|log|ini)'              => 'حجب قواعد البيانات والمفاتيح الحسّاسة',
    '(zip|tar|gz|tgz'                                                => 'حجب الأرشيفات',
    'location = /setup/index.php'                                    => 'إتاحة صفحة التهيئة',
    'fastcgi_pass'                                                   => 'تمرير الطلبات إلى PHP-FPM',
    'try_files $uri $uri/ @shop'                                     => 'تطبيق صفحة واحدة',
    'location @shop'                                                 => 'وجهة الروابط النظيفة',
    'location ~ \.php$'                                              => 'حجب أي PHP خارج المسموح',
    'app/uploads/.*\\.(php'                                          => 'منع تنفيذ سكربتات في الرفع',
    'application/manifest+json'                                      => 'نوع ملف PWA',
    'X-Content-Type-Options'                                         => 'ترويسة nosniff',
    'X-Frame-Options'                                                => 'ترويسة منع الإطارات',
    'autoindex off'                                                  => 'منع استعراض المجلدات',
];
foreach ($mustHave as $needle => $why) {
    $check(str_contains($conf, $needle), 'nginx: ' . $why);
}

// لا تعليمات Apache: القالب يختار خادمًا واحدًا ويقوله
foreach (['RewriteRule', 'RewriteCond', '<Files', 'AllowOverride', 'php_flag'] as $apache) {
    $check(!str_contains($conf, $apache), 'لا تعليمة Apache في الإعداد: ' . $apache);
}

/* ------------------------------------------------------------------ */
/* ٦. البيان                                                          */
/* ------------------------------------------------------------------ */

$group('٦. بيان الحزمة');

$release = $pkg . '/RELEASE.txt';
$check(is_file($release), 'RELEASE.txt موجود');

if (is_file($release)) {
    $text = (string) file_get_contents($release);
    $check(str_contains($text, ProjectConfig::VERSION), 'البيان يذكر رقم الإصدار');
    $check(str_contains($text, 'nginx'), 'البيان يشرح إعداد nginx');
    $check(str_contains($text, 'setup'), 'البيان يشرح التهيئة');
    foreach (array_keys(ReleaseManifest::exclusions()) as $path) {
        $check(str_contains($text, $path), 'البيان يذكر المستبعَد: ' . $path);
    }
}

/* ------------------------------------------------------------------ */
/* النتيجة                                                            */
/* ------------------------------------------------------------------ */

echo "\n" . str_repeat('─', 62) . "\n";
if ($failed === 0) {
    echo "\033[32m\033[1m  الحزمة صالحة للنشر: {$passed} فحصًا ناجحًا\033[0m\n";
} else {
    echo "\033[31m\033[1m  فشل {$failed} من " . ($passed + $failed) . "\033[0m\n";
    foreach ($failures as $f) {
        echo "   • {$f}\n";
    }
}
echo str_repeat('─', 62) . "\n";

exit($failed === 0 ? 0 : 1);
