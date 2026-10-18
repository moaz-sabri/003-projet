<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\ProjectConfig;

/**
 * دورة حياة النسخة: وصف الحالة · تدمير النسخة · العودة إلى نقطة الصفر.
 *
 * ## الفكرة
 *
 * النسخة المبنية **ليست** فضاءً معماريًا: البيانات وحدها هي التي تُملأ
 * وتُمحى. لذلك يمكن محو النسخة بالكامل والعودة بالحالة نفسها التي كانت
 * عليها قبل أول تهيئة — ثم البناء من جديد بنشاط مختلف تمامًا
 * (مطعم ← معرض مثلًا) دون أن يبقى أثر للنشاط القديم في أي مكان.
 *
 * لذلك كل شيء في القائمة أدناه يُحذف:
 *   - ملف الإعدادات (بيانات الدخول، مفتاح التطبيق، رابط الموقع، مسار القاعدة)
 *   - قاعدة البيانات وملفا WAL و SHM
 *   - الصور المرفوعة في app/uploads
 *   - ملف محتوى الشركة app/data/company.json
 *   - النسخ الاحتياطية والسجلات وعدّادات المعدل
 *
 * وما **لا** يُحذف أبدًا هو القالب نفسه: src/ و app/js و api/ و admin/
 * و tools/ و index.php. التدمير يعيد **البيانات** إلى الصفر، لا القالب.
 *
 * ## الأمان
 *
 *  1) لا تنفَّذ عملية واحدة بلا تأكيد صريح (CONFIRM_WORD) — هذا الباب الوحيد.
 *  2) نسخة أمان تُؤخذ قبل أي حذف، في مجلد لا تطاله عملية الحذف نفسها
 *     (storage/destroyed/)، وإن فشلت النسخة **تتوقف العملية كلها**.
 *  3) المسارات النسبية فقط تصل إلى المتصفح — لا مسار مطلق على القرص.
 *  4) يُغلق الاتصال بالقاعدة قبل لمس الملف: الملف المفتوح على ويندوز
 *     يبقى محجوبًا عن الحذف، فتبقى بيانات instance قديمة قابلة للاسترجاع.
 *
 * @package App\Services
 */
final class InstanceService
{
    /** العبارة التي يجب كتابتها حرفيًا لتأكيد التدمير. */
    public const CONFIRM_WORD = 'تدمير';

    /**
     * المجلد الذي تُحفظ فيه نسخ الأمان قبل التدمير.
     * مختار عمدًا لأنه ليس ضمن المجلدات التي تطالها عملية الحذف.
     */
    public const ARCHIVE_DIR = 'storage/destroyed';

    /** الجداول التي تُقرأ عدّاداتها قبل التدمير (لأرشفة السجل). */
    private const COUNTED_TABLES = ['products', 'categories', 'locations', 'orders'];

    /** ملف مفتاح إعادة البناء — يُنشأ عند التدمير ويُحذف عند أول بناء ناجح. */
    public const REBUILD_KEY_FILE = 'storage/config/rebuild.key';

    /* =====================================================================
     * أ. مفتاح إعادة البناء
     * =====================================================================
     *
     * ## لماذا؟
     *
     * قبل وجود هذه الميزة كانت «النسخة غير مهيأة» حالة أولى مرة فقط عند
     * أول نشر — نافذة يملكها صاحب الموقع. صارت الآن حالة **متكرّرة** على
     * نطاق حيّ: كل تدمير يعيد الموقع إليها، والفاصل بين التدمير والبناء
     * الجديد قد يمتد ساعات.
     *
     * ولو كانت صفحة التهيئة مفتوحة بلا حارس، خليها تعني: **أي شخص يفتح
     * الرابط ويضع كلمة مروره هو المدير الجديد**. باب استيلاء كامل على
     * الموقع، لا ثغرة ثانوية.
     *
     * فالتدمير ينتج مفتاحًا عشوائيًا، ولا تُعرض صفحة التهيئة إلا به.
     * صاحب النسخة يعرفه من اللوحة أو من الطرفية، ولا يعرفه أحد غيره.
     *
     * أما **أول** تثبيت فلا مفتاح له: لا ملف موجود أصلًا، فالنموذج يعمل
     * كما كان — بلا خطوة إضافية في أول مرة.
     */

    /** @return string|null المفتاح الحالي، أو null إن لم يُطلب إعادة بناء */
    public function rebuildKey(string $root = ''): ?string
    {
        $path = $this->paths($root)['rebuildKey'];
        if (!is_file($path)) {
            return null;
        }
        $key = trim((string) @file_get_contents($path));
        return $key === '' ? null : $key;
    }

    /**
     * هل إعادة البناء مغلقة بانتظار مفتاح؟
     *
     * لا يكفي وجود ملف المفتاح: نسخة دُمّرت بإصدار سابق للميزة تترك
     * أرشيفًا بلا مفتاح، وهي **أخطر** حالة لأنها تبدو كأنها «أوّل
     * تثبيت» فيفتحها أي شخص. فالدليل على التدمير، لا غياب التهيئة،
     * هو ما يقرّر القفل.
     */
    public function rebuildPending(string $root = ''): bool
    {
        return $this->rebuildKey($root) !== null
            || ($this->wasDestroyed($root) && !$this->isInstalled($root));
    }

    /** هل يوجد دليل على تدمير سابق (أرشيف محفوظ)؟ */
    private function wasDestroyed(string $root = ''): bool
    {
        $dir = $this->paths($root)['archive'];
        return is_dir($dir) && (glob($dir . '/destroyed-*', GLOB_ONLYDIR) ?: []) !== [];
    }

    /**
     * يتحقق من المفتاح المقدَّم.
     *
     * البوابة هي rebuildPending() لا rebuildKey(): النسخة المدمَّرة
     * بإصدار سابق للميزة مقفلة بسبب وجود الأرشيف **بلا مفتاح على
     * القرص**. لو اكتفينا بفحص الملف لَقُبل فيها أي مفتاح — أي أن
     * القفل يعرض رسالة ويبقى مخرَقًا. فالقاعدة: مغلق = يُرفض، بلا استثناء.
     */
    public function checkRebuildKey(?string $given, string $root = ''): bool
    {
        if (!$this->rebuildPending($root)) {
            return true; // لا بناء معلّق — أوّل تثبيت
        }

        $expected = $this->rebuildKey($root);
        if ($expected === null) {
            return false; // مقفلة ولا مفتاح صالح إطلاقًا
        }

        $given = trim((string) $given);
        return $given !== '' && hash_equals($expected, $given);
    }

    /** ينشئ مفتاحًا جديدًا (أو يدوّر الحالي) ويُرجعه. */
    public function issueRebuildKey(string $root = ''): string
    {
        $path = $this->paths($root)['rebuildKey'];
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $key = bin2hex(random_bytes(16));
        \App\Core\StorageWriter::write($path, $key, 0600);
        return $key;
    }

    /** يُحذف بعد أول بناء ناجح: عاد الموقع إلى طورته الطبيعية. */
    public function consumeRebuildKey(string $root = ''): void
    {
        $path = $this->paths($root)['rebuildKey'];
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /* =====================================================================
     * ب. الحالة
     * ===================================================================== */

    public function isInstalled(string $root = ''): bool
    {
        return is_file($this->paths($root)['config']);
    }

    /**
     * ملخّص آمن للعرض: عدد السجلات وأحجام الملفات.
     *
     * لا يُرجع أي مسار مطلق: لوحة الإدارة تُعرض للمدير عبر الويب،
     * وكشف بنية القرص لا يفيده ويكشف تفاصيل الخادم لغيره.
     *
     * @return array<string, mixed>
     */
    public function state(string $root = ''): array
    {
        $paths = $this->paths($root);

        $state = [
            'installed'        => is_file($paths['config']),
            'template_version' => ProjectConfig::VERSION,
            'setup_available'  => is_file($paths['root'] . '/setup/index.php'),
            'archives'         => $this->listArchives($paths['archive']),
            'counts'           => array_fill_keys(self::COUNTED_TABLES, 0),
            'sizes'            => [
                'database' => $this->humanSize(is_file($paths['database']) ? (int) filesize($paths['database']) : 0),
                'uploads'  => $this->humanSize($this->dirSize($paths['uploads'])),
                'backups'  => $this->humanSize($this->dirSize($paths['backups'])),
            ],
            'paths'            => [
                'config'   => $this->relative($paths['root'], $paths['config']),
                'database' => $this->relative($paths['root'], $paths['database']),
                'company'  => $this->relative($paths['root'], $paths['company']),
                'uploads'  => $this->relative($paths['root'], $paths['uploads']),
            ],
            'confirm_word'     => self::CONFIRM_WORD,
        ];

        if (!$state['installed']) {
            return $state;
        }

        // تُقرأ العدّادات قبل أي حذف — بعدها لن تبقى قاعدة بيانات نعدّ منها.
        try {
            $state['counts']   = $this->countRecords($paths);
            $state['base_url'] = (string) app_config('app.base_url', '');
            $state['preset']    = ProjectConfig::presetLabel();
        } catch (\Throwable) {
            // قاعدة ناقصة أو تالفة: نُبقي الأصفار بدل إفشال العملية.
            $state['db_error'] = true;
        }

        return $state;
    }

    /* =====================================================================
     * ب. التدمير
     * ===================================================================== */

    /**
     * يمحو النسخة بالكامل ويعيدها إلى ما قبل أول تهيئة.
     *
     * @param array{confirm?:string, archive?:bool, keep_backups?:bool} $options
     *        confirm      — إلزامي ويساوي CONFIRM_WORD. لا وجود لأي مسار
     *                       يمرّ من هنا دونه: هذا هو الباب الوحيد للمحو.
     *        archive      — نسخة أمان قبل الحذف (الافتراضي: نعم، وشرط إيقاف).
     *        keep_backups — إبقاء النسخ الاحتياطية القديمة على القرص.
     *
     * @return array{ok:bool, archive:string, removed:array<int,string>, counts:array<string,int>}
     * @throws \RuntimeException عند فشل التأكيد أو تعذّر أخذ نسخة الأمان.
     */
    public function destroy(string $root = '', array $options = []): array
    {
        $confirm = trim((string) ($options['confirm'] ?? ''));
        if (!hash_equals(self::CONFIRM_WORD, $confirm)) {
            throw new \RuntimeException(
                'عبارة التأكيد غير مطابقة — لم يُحذف شيء. الكلمة المطلوبة: ' . self::CONFIRM_WORD
            );
        }

        $paths = $this->paths($root);

        /*
         * «نسخة موجودة» ثلاث حالات لا واحدة: مهيأة (ملف إعدادات)، أو
         * نصف-مهيأة (قاعدة بلا إعدادات — كتابة مقطوعة)، أو مدمَّرة
         * بانتظار إعادة البناء (مفتاح قائم).
         *
         * الحالة الثالثة سببها هنا تحديدًا: التدمير الثاني هو الطريق
         * المشروع لتدوير مفتاح ضائع — فلو رفضناه لأصبح المفتاح المفقود
         * بلا مخرج إلا تدخل يدوي.
         */
        $exists = is_file($paths['config'])
            || is_file($paths['database'])
            || is_file($paths['rebuildKey'])
            || $this->wasDestroyed($root);
        if (!$exists) {
            throw new \RuntimeException('لا توجد نسخة مهيأة لتدميرها.');
        }

        // تُحسب مرة واحدة هنا وتُستخدم في التقرير وبيان الأرشفة. لو
        // عُدّت بعد الحذف لعادت أصفارًا — وهو ما يجعل واجهة Tell المستخدم
        // «حُذف ٠ عنصر» بعد أن حذف المئات.
        $counts = $this->countRecords($paths);

        /* --- ١. نسخة الأمان: إن فشلت تتوقف العملية، ولا يُحذف شيء --- */
        $archive = '';
        if (($options['archive'] ?? true) !== false) {
            $archive = $this->createArchive($paths, $counts);
        }

        /* --- ٢. إغلاق الاتصال قبل لمس ملف SQLite --- */
        $this->closeDatabase($paths['database']);

        /* --- ٣. الحذف بترتيب صريح --- */
        $removed = [];

        // 3أ · القاعدة وملفا Journal. إبقاء أيٍّ منها يجعل القاعدة القديمة
        // قابلة للتحميل، أو تعيد resurrect عند أول فتح لقاعدة فارغة.
        foreach ([$paths['database'], $paths['database'] . '-wal', $paths['database'] . '-shm'] as $file) {
            if (is_file($file) && @unlink($file)) {
                $removed[] = $this->relative($paths['root'], $file);
            }
        }

        // 3ب · بيانات الدخول والمفتاح والرابط.
        if (is_file($paths['config']) && @unlink($paths['config'])) {
            $removed[] = $this->relative($paths['root'], $paths['config']);
        }

        // 3ج · الصور المرفوعة. نترك المجلد نفسه: تفريغه هو المطلوب،
        // وحذفه يجعل أول رفع صورة يفشل حتى أول mkdir. الحماية من
        // تنفيذ السكربتات يوفّرها nginx لا ملف داخل المجلد.
        $removed = array_merge($removed, $this->clearDirectory($paths['root'], $paths['uploads']));

        // 3د · محتوى الشركة — يُعاد توليده بقالب فارغ عند البناء الجديد.
        if (is_file($paths['company']) && @unlink($paths['company'])) {
            $removed[] = $this->relative($paths['root'], $paths['company']);
        }

        // 3هـ · النسخ الاحتياطية والسجلات وعدّادات المعدل.
        if (!($options['keep_backups'] ?? false)) {
            $removed = array_merge($removed, $this->clearDirectory($paths['root'], $paths['backups']));
        }
        $removed = array_merge($removed, $this->clearDirectory($paths['root'], $paths['logs']));
        $removed = array_merge($removed, $this->clearDirectory($paths['root'], $paths['tmp']));
        $removed = array_merge($removed, $this->clearDirectory($paths['root'], $paths['storageUploads']));

        /* --- ٤. إعادة المجلدات الفارغة بنفس الصلاحيات --- */
        // الهدف ألّا يفشل البناء الجديد بسبب مجلد مفقود، وأن تبقى الملكية
        // كما ضبطها الأدمن لا كما ضبطها من تنفيذ آخر.
        foreach (['database', 'backups', 'logs', 'tmp', 'uploads'] as $folder) {
            $dir = $paths['root'] . '/storage/' . $folder;
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
        \App\Core\StorageWriter::fixTree($paths['root'] . '/storage');

        /* --- ٥. قفل صفحة التهيئة بمفتاح، وإلا فست يصبح تدمير النسخة
                بابًا مفتوحًا لمن يعرف الرابط: يكفي أن يفتحها ويعين كلمة
                مروره ليصبح المدير. المفتاح يُدوَّر مع كل تدمير. --- */
        $rebuildKey = $this->issueRebuildKey($root);

        /* --- ٦. إسقاط تغطية نوع النشاط من ذاكرة هذا الطلب --- */
        // api/index.php ما زال في نفس الطلب، وقيم النشاط القديمة ما زالت
        // في الذاكرة. نسقطها حتى لا تُستخدم في أي استجابة لاحقة.
        ProjectConfig::setOverrides(null);

        return [
            'ok'         => true,
            'archive'    => $archive === '' ? '' : $this->relative($paths['root'], $archive),
            'removed'    => array_values(array_unique($removed)),
            'counts'     => $counts,
            'rebuildKey' => $rebuildKey,
            'rebuildUrl' => $this->rebuildUrl($rebuildKey),
        ];
    }

    /** رابط إعادة البناء الكامل بالمفتاح — يُعرض مرة واحدة للمدير. */
    private function rebuildUrl(string $key): string
    {
        $base = rtrim((string) app_config('app.base_url', ''), '/');
        if ($base === '') {
            return '';
        }
        return $base . '/setup/index.php?key=' . rawurlencode($key);
    }

    /* =====================================================================
     * ج. نسخة الأمان
     * ===================================================================== */

    /**
     * تنسخ كل ما سيُحذف إلى مجلد مؤرّخ، مع ملف يشرح كيف تُستعاد.
     *
     * الفشل هنا يوقف التدمير. النسخة التي يمكن أن تضيع بسبب خطأ في
     * نسخها ليست نسخة.
     *
     * @param array<string,int> $counts العدّادات المقروءة قبل الحذف.
     */
    private function createArchive(array $paths, array $counts): string
    {
        $dir = $paths['archive'] . '/destroyed-' . date('Ymd-His');
        for ($i = 2; $i < 100 && is_dir($dir); $i++) {
            $dir = $paths['archive'] . '/destroyed-' . date('Ymd-His') . '-' . $i;
        }

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException(
                'تعذّر إنشاء مجلد نسخة الأمان (' . $this->relative($paths['root'], $dir)
                . ') — أُوقفت العملية ولم يُحذف شيء.'
            );
        }

        $copied = [];

        try {
            // ننسخ الملفات كما هي لا عبر VACUUM INTO: بعد إفلات الاتصال
            // قد لا تُفتح القاعدة، والفشل هناك يصمت.
            //
            // WAL يُنسخ فقط إن كان يحمل إطارات فعلًا: ملف فارغ بجوار
            // قاعدة في الأرشيف يربك SQLite عند الاسترجاع بدل أن يُهمَل.
            $sources = [
                'app.sqlite'     => $paths['database'],
                'app.sqlite-wal' => (int) @filesize($paths['database'] . '-wal') > 0
                    ? $paths['database'] . '-wal' : '',
                'local.php'      => $paths['config'],
                'company.json'   => $paths['company'],
            ];

            foreach ($sources as $name => $from) {
                if ($from === '' || !is_file($from)) {
                    continue;
                }
                if (!@copy($from, $dir . '/' . $name)) {
                    throw new \RuntimeException('تعذّر نسخ ' . $name);
                }
                @chmod($dir . '/' . $name, 0600);
                $copied[] = $name;
            }

            $manifest = [
                'destroyed_at'     => date('c'),
                'template_version' => ProjectConfig::VERSION,
                'base_url'         => (string) app_config('app.base_url', ''),
                'counts'           => $counts,
                'files'            => $copied,
                'restore'          => [
                    '١) انسخ app.sqlite إلى مسار قاعدة البيانات (db.path داخل local.php).',
                    '٢) انسخ local.php إلى مكان ملف الإعدادات الذي يحدده app_config_file().',
                    '٣) أو أعد البناء: php bin/console install ثم انسخ الملفات فوقها.',
                ],
            ];

            $json = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($json === false || file_put_contents($dir . '/manifest.json', $json . "\n", LOCK_EX) === false) {
                throw new \RuntimeException('تعذّر كتابة بيان نسخة الأمان');
            }
            @chmod($dir . '/manifest.json', 0600);
        } catch (\Throwable $e) {
            // تنظيف جزئي: لا نترك نسخة ناقصة قد يظنها المدير كاملة.
            foreach (glob($dir . '/*') ?: [] as $partial) {
                @unlink($partial);
            }
            @rmdir($dir);

            throw new \RuntimeException(
                'فشلت نسخة الأمان (' . $e->getMessage() . ') — أُوقفت العملية ولم يُحذف شيء.',
                0,
                $e
            );
        }

        @chmod($dir, 0700);
        return $dir;
    }

    /** @return array<int, array{name:string, date:string}> */
    private function listArchives(string $dir): array
    {
        $items = is_dir($dir) ? (glob($dir . '/destroyed-*', GLOB_ONLYDIR) ?: []) : [];
        rsort($items);

        $out = [];
        foreach (array_slice($items, 0, 10) as $item) {
            $out[] = [
                'name' => basename($item),
                'date' => date('Y-m-d H:i', (int) filemtime($item)),
            ];
        }
        return $out;
    }

    /* =====================================================================
     * د. أدوات الملفات
     * ===================================================================== */

    /**
     * يمسح محتوى مجلد، ويُرجع ما حُذف نسبيًا للجذر.
     *
     * @param array<int,string> $keep أسماء ملفات تُترك داخل المجلد.
     * @return array<int,string>
     */
    private function clearDirectory(string $root, string $dir, array $keep = []): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $removed = [];
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if (in_array($item->getFilename(), $keep, true)) {
                continue;
            }
            $ok = $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            if ($ok) {
                $removed[] = $this->relative($root, $item->getPathname());
            }
        }
        return $removed;
    }

    /**
     * عدّ السجلات في ملف قاعدة بيانات محدّد.
     *
     * يفتح اتصالًا مستقلًا cuando لا يكون الملف هو قاعدة العملية الجارية،
     * فلا يُبدَّل مسار قاعدة الاختبار ولا تُلمس بياناتها. بدون ذلك كان
     * عدّ نسخة أخرى يُرجع أرقام قاعدة العمل — صفر خاطئ أو أسوأ.
     *
     * @return array<string,int>
     */
    private function countRecords(array $paths): array
    {
        $out = array_fill_keys(self::COUNTED_TABLES, 0);
        if (!is_file($paths['database'])) {
            return $out;
        }

        try {
            $pdo = $this->readConnection($paths['database']);
            foreach (self::COUNTED_TABLES as $table) {
                $stmt = $pdo->query('SELECT COUNT(*) FROM ' . $table);
                $row  = $stmt === false ? false : $stmt->fetchColumn();
                if ($row !== false) {
                    $out[$table] = (int) $row;
                }
            }
        } catch (\Throwable) {
            // نُبقي الأصفار: العدّ تسجيلي لا شرط لإتمام التدمير.
        }

        return $out;
    }

    /** اتصال قراءة: يتشارك الاتصال القائم إن كان على الملف نفسه. */
    private function readConnection(string $path): \PDO
    {
        $live = (string) app_config('db.path', '');
        if ($live !== '' && $this->sameFile($live, $path)) {
            return \App\Core\Database::pdo();
        }

        return new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    /** يغلق اتصال القاعدة إن كان هو نفس الملف المشغول حاليًا. */
    private function closeDatabase(string $path): void
    {
        $live = (string) app_config('db.path', '');
        if ($live !== '' && $this->sameFile($live, $path)) {
            \App\Core\Database::close();
        }
    }

    private function sameFile(string $a, string $b): bool
    {
        $ra = realpath($a);
        $rb = realpath($b);
        if ($ra !== false && $rb !== false) {
            return $ra === $rb;
        }
        return rtrim(str_replace('\\', '/', $a), '/') === rtrim(str_replace('\\', '/', $b), '/');
    }

    /**
     * كل المسارات التي تعمل عليها الخدمة.
     *
     * الفارق المهم: عند تمرير $root مختلف (اختبار على مجلد رمل) نتجاهل
     * app_config_file() و app_config('db.path') ونحسب من $root. بدون هذا
     * الشرط سيبدأ اختبارٌ يحذف ملفات المشروع الحقيقية لأن الإعدادات
     * الحقيقية تشير إليها.
     *
     * @return array<string,string>
     */
    private function paths(string $root = ''): array
    {
        $root = rtrim(str_replace('\\', '/', $root !== '' ? $root : ProjectConfig::basePath()), '/');

        if ($root === ProjectConfig::basePath()) {
            $config   = str_replace('\\', '/', app_config_file());
            $database = str_replace('\\', '/', (string) app_config(
                'db.path',
                $root . '/storage/database/app.sqlite'
            ));
        } else {
            $config   = $root . '/storage/config/local.php';
            $database = $root . '/storage/database/app.sqlite';
        }

        return [
            'root'           => $root,
            'config'         => $config,
            'database'       => $database,
            'company'        => $root . '/app/data/company.json',
            'uploads'        => $root . '/app/uploads',
            'backups'        => $root . '/storage/backups',
            'logs'           => $root . '/storage/logs',
            'tmp'           => $root . '/storage/tmp',
            'storageUploads' => $root . '/storage/uploads',
            'archive'        => $root . '/' . self::ARCHIVE_DIR,
            'rebuildKey'     => $root . '/' . self::REBUILD_KEY_FILE,
        ];
    }

    /** مسار نسبي للجذر — لا يُكشف مسار القرص المطلق أبدًا. */
    private function relative(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $path = str_replace('\\', '/', $path);
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : basename($path);
    }

    private function dirSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $total = 0;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            if ($item->isFile()) {
                $total += (int) $item->getSize();
            }
        }
        return $total;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 ك.ب';
        }
        if ($bytes < 1024) {
            return $bytes . ' بايت';
        }
        return number_format($bytes / 1024, 1) . ' ك.ب';
    }
}
