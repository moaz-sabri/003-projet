<?php

declare(strict_types=1);

namespace App\Config;

/**
 * حدّ الإصدار: ما الذي يُنشر على الخادم وما الذي لا يُنشر أبدًا.
 *
 * ## لماذا ملف كهذا؟
 *
 * «ارفع كل شيء ثم احذف المجلدات غير اللازمة» وصفة لا تنتهي: كل من
 * يحذف يتذكر قائمة مختلفة، وأول نسيانٍ هو `storage/` أو `.git` — أي أن
 * مكان واحد، وتُنفَّذ آليًا، ويشهرها RELEASE.txt داخل الحزمة نفسها.
 * مكان واحد، وتُنفَّذ آليًا، ويشهرها `RELEASE.txt` داخل الحزمة نفسها.
 *
 * ## القاعدة التي تحكم هذا الملف
 *
 * ما لم يُذكر هنا بـ«يُنشر» فهو **لا يُنشر**. الافتراضي مُغلق، لأن الخطأ
 * في اتجاه واحد: خطأ نسيان شيء أن يُنشر ({chmod database} على الإنترنت)،
 * وخطأ نسيان حذف شيء هو ملف development لا يضرّ صاحب الموقع بل يربكه.
 *
 * ## العلاقة بالقالب
 *
 * هذا الملف يُشحن مع القالب نفسه: من لم يُنشر الأدوات يظل يملك الحدّ
 * مكتوبًا، فيعرف ما Voters من ملفاته أقلέ.
 *
 * خطأ نسيان حذف شيء هو ملف تطوير لا يضرّ صاحب الموقع بل يربكه فقط.
 */
final class ReleaseManifest
{
    /** @var string جذر المشروع على القرص */
    public const ROOT = BASE_PATH;

    /* =====================================================================
     * أ. ما يُنشر — بالمجلدات
     * ===================================================================== */

    /**
 * هذا الملف يُشحن مع القالب نفسه: فيبقى الحدّ مكتوبًا لمن رفع الحزمة،
 * فيعرف أيّ ملفاته زائد بلا أن يحتاج أدوات البناء.
     * @var array<int, string>
     */
    public const INCLUDE_PATHS = [
        'index.php',                  // نقطة دخول واجهة الزبون على الجذر
        'sw.js',                       // عامل الخدمة — يجب أن يبقى على جذر النطاق
        'emergency.php',               // رابط التدمير الطارئ — مفتاحه سريّ ويولَّد عند التهيئة
        'admin',                       // لوحة الإدارة
        'api',                         // واجهة JSON
        'app',                         // ملفات واجهة الزبون (PWA)
        'src',                         // كود الخادم
        'bin',                         // bin/console للصيانة على الخادم
        'setup',                       // صفحة التهيئة وإعادة البناء
        'deploy',                      // إعداد nginx ودليل النشر
    ];

    /**
     * مسارات تُنسخ بمجلداتها فقط، بلا محتوى وقت التشغيل.
     *
     * @var array<int, string>
     */
    public const INCLUDE_DIRS_EMPTY = ['storage'];

    /**
     * مجلدات تُنشأ في الحزمة **فارغة** ولا يُنسخ منها ملف واحد.
     *
     * ## لماذا هذا منفصل عن EXCLUDE؟
     *
     * لأن EXCLUDE تعني «لا تذكره»، وهذه تعني «أنشئه بلا محتوى».
     * والفرق مهم: app/uploads/ يجب أن **يوجد** وإلا فشل رفع أول صورة،
     * لكن محتواه صور منتجات نسختك — وهي بيانات زبائن لا قالب.
     *
     * أول من كشف هذا الخلل هو الفحص نفسه: 13 صورة منتج من نسخة
     * التطوير كانت ستُرفع كأنها جزء من القالب.
     *
     * @var array<int, string>
     */
    public const CONTENTLESS_DIRS = [
        'app/uploads',   // صور المنتجات — تُرفع على الخادم بعد التهيئة
        'app/data',      // company.json — يولّده مُعالج التهيئة لكل نسخة
    ];

    /**
     * ملفات تُنتجها أداة البناء داخل الحزمة، لا موجودة في المصدر.
     *
     * @var array<int, string>
     */
    public const GENERATED_FILES = [
        'RELEASE.txt',
    ];

    /* =====================================================================
     * ب. ما لا يُنشر أبدًا
     * ===================================================================== */

    /**
     * استثناءات صريحة: لا يُنسخ من هذا المسار شيء، ولا يُ descend داخله.
     *
     * @var array<string, string> المسار ← السبب
     */
    public const EXCLUDE = [
        'tests'        => 'اختبارات آلية — لا تعمل على خادم إنتاج بلا كود اختبار',
        'tools'        => 'أدوات فحص وصيانة تُستعمل محليًا قبل النشر لا بعده',
        'docs'         => 'توثيق داخلي',
        'docker'       => 'بيئة التطوير بالحاويات — لا وجود لها على خادم مباشر',
        'dist'         => 'ناتج البناء نفسه — لا يُبنى داخل نفسه',
        '.github'      => 'خطوط CI — لا محل لها على الخادم',
        '.git'         => 'مستودع كامل: كل تاريخ المشروع وكل كوكي ذاكرة',
    ];

    /**
     * ملفات تُستثنى من نسخها، مهما كانت داخل مسار يُنشر.
     *
     * @var array<int, string>
     */
    public const EXCLUDE_FILES = [
        'bin/serve.php',       // خادم التطوير المدمج — بيئة الإنتاج على nginx
        'app/data/company.json', // محتوى نسخة أخرى — يُولَّد من صفحة التهيئة
        'README.md',
        'CHANGELOG.md',
        'AUDIT_REPORT.md',
        '.gitignore',
        '.dockerignore',
        'Dockerfile',
        'compose.yaml',
        'test.md',
    ];

    /**
     * امتدادات لا تُنسخ أبدًا — بيانات أو أسرار أو آثار بناء.
     *
     * @var array<int, string>
     */
    public const EXCLUDE_EXTENSIONS = [
        'sqlite', 'sqlite-wal', 'sqlite-shm', 'log', 'tmp', 'bak',
        'md', 'zip', 'tar', 'gz', 'tgz', '7z', 'rar', 'iso', 'sql', 'dump',
    ];

    /**
     * أسماء لا تُنسخ أبدًا أينما وُجدت.
     *
     * @var array<int, string>
     */
    public const EXCLUDE_NAMES = [
        'local.php',      // بيانات الدخول والمفتاح — تُولَّد عند التهيئة
        '.env',
        '.gitignore',
        '.DS_Store',
        'Thumbs.db',
    ];

    /* =====================================================================
     * ج. الدوال
     * ===================================================================== */

    /** @return array<string,string> */
    public static function exclusions(): array
    {
        return self::EXCLUDE;
    }

    /**
     * يستخرج كل مراجع `require` من ملف، ويحلّها إلى مسارات نسبية نظيفة.
     *
     * الحلّ يعتمد على **العبارة** لا على النص وحده، لأن المشروع يمزج
     * نمطين:
     *   - `__DIR__ . '/../src/bootstrap.php'`  → القاعدة هي مجلد الملف
     *   - `BASE_PATH . '/src/Core/Autoloader.php'` → القاعدة هي جذر المشروع
     *
     * والعبارة `/src/…` بلا سائق لا تعني الجذر: هي في سياق
     * `__DIR__ . '/…'` تعني مجلد الملف. فلو عُوملت كجذر لأصبح مرجع
     * `setup/index.php` — وهو سليم — يبدو مكسورًا.
     *
     * @param  string $rel  مسار الملف نسبةً لجذر المشروع
     * @param  string|null $code محتوى الملف (يُقرأ إن لم يُمرَّر)
     * @return array<int, array{to:string, target:string}> أزواج (المحلَّل، الأصلي)
     */
    public static function requires(string $rel, ?string $code = null): array
    {
        $code ??= (string) @file_get_contents(self::ROOT . '/' . $rel);
        if ($code === '') {
            return [];
        }

        $out = [];
        // كل تعلي require كاملة، لا مجرد نص الاقتباس: نحتاج معرفة السائق.
        if (!preg_match_all('#require(?:_once)?\s+([^;]+);#', $code, $statements)) {
            return [];
        }

        foreach ($statements[1] as $statement) {
            if (!preg_match('#[\'"]([^\'"]+)[\'"]#', $statement, $quoted)) {
                continue;
            }
            $target = $quoted[1];
            if (!str_ends_with($target, '.php')) {
                continue;
            }

            $usesBase    = str_contains($statement, 'BASE_PATH');
            $usesDirname = str_contains($statement, '__DIR__');

            // الأولوية لـ BASE_PATH: هو جذر المشروع لا مجلد الملف.
            $resolved = ($usesBase && !$usesDirname)
                ? self::normalizeRooted($target)
                : self::resolve($rel, $target);

            $out[] = ['to' => $resolved, 'target' => $target];
        }

        return $out;
    }

    /** يحلّ مسارًا نسبةً إلى مجلد الملف الطالب (نمط `__DIR__ . '/...'`). */
    private static function resolve(string $from, string $target): string
    {
        $target = str_replace('\\', '/', $target);
        $path   = $target === '' ? '' : dirname($from) . '/' . ltrim($target, '/');

        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        return implode('/', $parts);
    }

    /** يحلّ مسارًا جذرية (بشرطة أولى) إلى جذر المشروع. */
    private static function normalizeRooted(string $target): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $target)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        return implode('/', $parts);
    }

    /**
     * كل المسارات النسبية التي يجب أن تكون في الحزمة.
     *
     * @param  string $source جذر المشروع (للاختبارات)
     * @return array<int, string> مرتّبة، بدون مجلدات فارغة
     */
    public static function files(string $source = ''): array
    {
        $source = $source !== '' ? rtrim($source, '/\\') : dirname(__DIR__, 2);
        $out    = [];

        foreach (self::INCLUDE_PATHS as $path) {
            $full = $source . '/' . $path;
            if (is_file($full)) {
                if (!self::isExcludedFile($path)) {
                    $out[$path] = true;
                }
            } elseif (is_dir($full)) {
                self::walk($full, $source, $out);
            }
        }

        foreach (self::INCLUDE_DIRS_EMPTY as $dir) {
            $full = $source . '/' . $dir;
            if (!is_dir($full)) {
                continue;
            }
            foreach (['config', 'database', 'backups', 'logs', 'tmp', 'uploads'] as $sub) {
                $out[$dir . '/' . $sub . '/.gitkeep'] = true;
            }
        }

        // مجلدات تُنشأ موجودة وفارغة: المسار ضروري ليعمل شيء، ومحتواه
        // بيانات نسخة أخرى لا مكان له في القالب.
        foreach (self::CONTENTLESS_DIRS as $dir) {
            foreach (['.gitkeep'] as $marker) {
                $out[$dir . '/' . $marker] = true;
            }
        }

        $files = array_keys($out);
        sort($files);
        return array_values($files);
    }

    /**
     * هل هذا المسار ممنوع من النشر؟ (يرجع السبب أو null)
     */
    public static function reasonForExclusion(string $relative): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        $top      = explode('/', $relative)[0];

        if (isset(self::EXCLUDE[$top])) {
            return self::EXCLUDE[$top];
        }
        foreach (self::CONTENTLESS_DIRS as $dir) {
            if (str_starts_with($relative, $dir . '/')) {
                return 'محتوى نسخة محلية — مجلد ' . $dir . ' يُنشأ في الحزمة فارغًا';
            }
        }
        if (in_array($relative, self::EXCLUDE_FILES, true)) {
            return 'ملف تطوير أو توثيق — ليس من القالب المُشحن';
        }
        return self::isExcludedFile($relative) ? self::fileExclusionReason($relative) : null;
    }

    /** هل يُستبعد أي ملف بهذه Basename أو امتداد؟ */
    public static function isExcludedFile(string $relative): bool
    {
        return self::fileExclusionReason($relative) !== null;
    }

    private static function fileExclusionReason(string $relative): ?string
    {
        $name = basename($relative);
        if (in_array($name, self::EXCLUDE_NAMES, true)) {
            return 'اسم مستثنى: ' . $name;
        }
        if (in_array($relative, self::EXCLUDE_FILES, true)) {
            return 'ملف تطوير أو توثيق';
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, self::EXCLUDE_EXTENSIONS, true)) {
            return 'امتداد بيانات أو أثر بناء: .' . $ext;
        }
        return null;
    }

    /** @param array<string,true> $out */
    private static function walk(string $dir, string $source, array &$out): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $path     = str_replace('\\', '/', $item->getPathname());
            $relative = ltrim(substr($path, strlen($source)), '/');

            $top = explode('/', $relative)[0];
            if (isset(self::EXCLUDE[$top])) {
                continue;
            }
            if (!$item->isFile()) {
                continue;
            }
            // محتوى نسخة محلية: صور المنتجات وملف الشركة. المسار ضروري
            // ليعمل شيء، لكن محتواه لا يجوز أن يسافر مع القالب.
            foreach (self::CONTENTLESS_DIRS as $contentless) {
                if (str_starts_with($relative, $contentless . '/')) {
                    continue 2;
                }
            }
            if (!self::isExcludedFile($relative)) {
                $out[$relative] = true;
            }
        }
    }
}
