<?php

declare(strict_types=1);

/**
 * عقد القالب + دورة حياة النسخة.
 *
 * هاتانِ معًا يحددان الحدّ الفاصل في سياسة المشروع:
 *   - ما لا يتغيّره القالب أبدًا (TemplateContract)
 *   - وما يمكن محوه والعودة إلى نقطة الصفر (InstanceService)
 *
 * كل اختبارات التدمير تعمل على مجلد رمل مؤقت، لا على ملفات المشروع.
 * لو تسرب شيء هنا إلى BASE_PATH فسيمحو الاختبارات قاعدة بيانات النسخة
 * الحقيقية — وهو أسوأ ما يمكن أن يحدث في اختبار.
 */

use App\Config\BusinessPresets;
use App\Config\ProjectConfig;
use App\Config\TemplateContract;
use App\Core\Database;
use App\Core\Migrations;
use App\Services\InstanceService;

/* =====================================================================
 * مجلد الرمل — نسخة مصغّرة من شجرة المشروع
 * ===================================================================== */

/**
 * يبني شجرة ملفات تحاكي نسخة مبنية، ويعيد مسارها.
 *
 * @param array<int,string> $tables جداول تُنشأ في قاعدة الرمل.
 * @return string
 */
function makeSandbox(array $tables = ['products', 'categories', 'locations', 'orders']): string
{
    $root = sys_get_temp_dir() . '/lc-instance-' . getmypid() . '-' . bin2hex(random_bytes(3));

    foreach ([
        '/storage/config', '/storage/database', '/storage/backups',
        '/storage/logs', '/storage/tmp', '/storage/uploads',
        '/app/uploads/products', '/app/uploads/brand', '/app/data', '/src/Config', '/setup',
    ] as $dir) {
        mkdir($root . $dir, 0775, true);
    }

    file_put_contents($root . '/storage/config/local.php', "<?php\nreturn ['admin' => ['username' => 'x']];\n");

    // قاعدة بيانات حقيقية: العدّاد يجب أن يقرأ منها فعلًا لا أن يفترض.
    $pdo = new PDO('sqlite:' . $root . '/storage/database/app.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    foreach ($tables as $table) {
        $pdo->exec('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY)');
    }
    $pdo->exec('INSERT INTO products (id) VALUES (1), (2)');
    $pdo->exec('INSERT INTO orders (id) VALUES (1), (2), (3)');
    $pdo = null;

    // ملفات Journal: بقاؤها بعد الحذف يعني قاعدة قابلة للاسترجاع.
    file_put_contents($root . '/storage/database/app.sqlite-wal', 'wal');
    file_put_contents($root . '/storage/database/app.sqlite-shm', 'shm');

    file_put_contents($root . '/app/data/company.json', '{"brand":{"name":"قديم"}}');

    // .htaccess داخل الرفع: لا يُحذف — حذفه يفتح تنفيذ سكربتات في المجلد.
    file_put_contents($root . '/app/uploads/.htaccess', 'php_flag engine off');
    file_put_contents($root . '/app/uploads/products/old.png', 'x');
    file_put_contents($root . '/app/uploads/brand/logo.png', 'x');

    file_put_contents($root . '/storage/backups/backup-20260101-000000.sqlite', 'x');
    file_put_contents($root . '/storage/logs/app-2026-01-01.log', 'x');
    file_put_contents($root . '/storage/tmp/rate-abc.json', '{}');
    file_put_contents($root . '/storage/uploads/leftover.tmp', 'x');

    // بديل رمزي للقالب: ما لا يجوز أن يمسه التدمير أبدًا.
    file_put_contents($root . '/src/Config/ProjectConfig.php', '<?php // القالب');
    file_put_contents($root . '/setup/index.php', '<?php // التهيئة');

    return $root;
}

/** يحذف شجرة الرمل كاملة (Windows: نحتاج المسارات الداخلية قابلة للكتابة). */
function dropSandbox(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($root);
}

return static function (): void {
    /* =====================================================================
     * ١. عقد القالب — أنواع الحقول مجموعة مغلقة
     * ===================================================================== */
    Assert::group('عقد القالب — أنواع الحقول');

    Assert::true(TemplateContract::isKnownFieldType('text'), 'text نوع معروف');
    Assert::true(TemplateContract::isKnownFieldType('select'), 'select نوع معروف');
    Assert::false(TemplateContract::isKnownFieldType('richtext'), 'نوع غير مسجّل مرفوض');
    Assert::false(TemplateContract::isKnownFieldType(''), 'النوع الفارغ مرفوض');
    Assert::false(TemplateContract::isKnownFieldType(null), 'null مرفوض');
    Assert::same('text', TemplateContract::normalizeFieldType('richtext'), 'النوع المجهول يُسقط إلى text');
    Assert::same('number', TemplateContract::normalizeFieldType('number'), 'النوع المعروف يبقى كما هو');

    Assert::throws(
        static fn() => TemplateContract::assertFieldTypes([['key' => 'a', 'label' => 'أ', 'type' => 'richtext']]),
        InvalidArgumentException::class,
        'تعريف بنوع مجهول يرفضه العقد'
    );
    Assert::throws(
        static fn() => TemplateContract::assertFieldTypes([['label' => 'بلا مفتاح', 'type' => 'text']]),
        InvalidArgumentException::class,
        'تعريف بلا مفتاح key مرفوض'
    );
    Assert::passes(
        static fn() => TemplateContract::assertFieldTypes([['key' => 'a', 'label' => 'أ', 'type' => 'text']]),
        'تعريف سليم يمرّ'
    );

    // أهم اختبار في هذه المجموعة: القالب الحالي كله يجب أن يمرّ بالعقد.
    Assert::passes(
        static fn() => TemplateContract::assertFieldTypes(ProjectConfig::productExtraFields(), 'PRODUCT_EXTRA_FIELDS'),
        'كل حقول المنتجات الحالية تمرّ بالعقد'
    );
    Assert::passes(
        static fn() => TemplateContract::assertFieldTypes(ProjectConfig::productDetailFields(), 'PRODUCT_DETAIL_FIELDS'),
        'كل حقول التفاصيل الحالية تمرّ بالعقد'
    );
    foreach (BusinessPresets::all() as $id => $preset) {
        Assert::passes(
            static fn() => TemplateContract::assertFieldTypes(
                ProjectConfig::orderTypes()[$id]['fields'] ?? [],
                $id
            ),
            'نوع النشاط ' . $id . ': حقول طلباته تمرّ بالعقد'
        );
    }

    // العقد ليس زينة: كل مفتاح نوع في القالب يجب أن يكون ضمن المجموعة المغلقة.
    $known = array_keys(TemplateContract::FIELD_TYPES);
    $allUsed = [];
    foreach (array_merge(
        ProjectConfig::PRODUCT_EXTRA_FIELDS,
        ProjectConfig::PRODUCT_DETAIL_FIELDS,
        ProjectConfig::ORDER_TYPES['dine_in']['fields'] ?? [],
        ProjectConfig::ORDER_TYPES['takeaway']['fields'] ?? []
    ) as $field) {
        $allUsed[] = (string) ($field['type'] ?? 'text');
    }
    Assert::same([], array_values(array_diff(array_unique($allUsed), $known)),
        'كل نوع مستخدَم في القالب ضمن المجموعة المغلقة');

    Assert::true(count(TemplateContract::invariants()) >= 10, 'قائمة الثوابت معرَّفة');
    Assert::true(count(TemplateContract::mutableGroups()) > 0, 'قائمة ما يمكن تغييره معرَّفة');

    /* =====================================================================
     * ٢. القيمة الافتراضية — من مفتاح default في التعريف
     * ===================================================================== */
    Assert::group('عقد القالب — القيمة الافتراضية للحقل');

    Assert::same(0, TemplateContract::fieldDefault(['type' => 'boolean', 'default' => false]), 'boolean false ← 0');
    Assert::same(1, TemplateContract::fieldDefault(['type' => 'boolean', 'default' => true]), 'boolean true ← 1');
    Assert::same(0, TemplateContract::fieldDefault(['type' => 'boolean']), 'boolean بلا default ← 0');
    Assert::same(1, TemplateContract::fieldDefault(['type' => 'boolean', 'default' => '1']), 'boolean "1" ← 1');

    Assert::same(5, TemplateContract::fieldDefault(['type' => 'number', 'default' => '5']), 'number "5" ← 5');
    Assert::same('', TemplateContract::fieldDefault(['type' => 'number']), 'number بلا default ← فارغ');
    Assert::same('', TemplateContract::fieldDefault(['type' => 'number', 'default' => 'غير رقم']),
        'number بقيمة غير رقمية ← فارغ لا 0');

    Assert::same('كامل', TemplateContract::fieldDefault(['type' => 'text', 'default' => 'كامل']), 'text يُبقي نصّه');
    Assert::same('', TemplateContract::fieldDefault(['type' => 'text']), 'text بلا default ← فارغ');
    Assert::same('', TemplateContract::fieldDefault(['type' => 'select']), 'select بلا default ← فارغ');
    Assert::same('1', TemplateContract::fieldDefault(['type' => 'غامض', 'default' => 1]),
        'النوع المجهول يُعامل كـ text: القيمة نص لا عدد صحيح');

    /* =====================================================================
     * ٣. الترحيل يملأ الحقول الجديدة بالقيمة الافتراضية
     * ===================================================================== */
    Assert::group('الترحيل — الحقول الجديدة تأخذ القيمة الافتراضية');

    // منتج أُضيف قبل أن يُعرَّف الحقلان: extra_json لا يحمل مفتاحيهما.
    $legacyId = Database::insert('products', [
        'category_id' => null,
        'name'        => 'منتج قديم',
        'price_minor' => 1000,
        'extra_json'  => '{"vegetarian":1}',
    ]);
    $existingId = Database::insert('products', [
        'category_id' => null,
        'name'        => 'منتج يحمل القيمة أصلًا',
        'price_minor' => 1000,
        'extra_json'  => json_encode(['warranty' => 'سنة', 'gift_wrap' => 0], JSON_UNESCAPED_UNICODE),
    ]);

    ProjectConfig::setOverrides(['product_extra_fields' => [
        ['key' => 'warranty',  'label' => 'الضمان',  'type' => 'text',   'default' => 'سنة',  'sort' => 10],
        ['key' => 'gift_wrap', 'label' => 'تغليف',   'type' => 'boolean', 'default' => true,  'sort' => 20],
        ['key' => 'warranty_no', 'label' => 'رقم الضمان', 'type' => 'number', 'default' => '', 'sort' => 30],
    ]]);

    try {
        Migrations::migrate();

        $filled = json_decode(
            (string) Database::value('SELECT extra_json FROM products WHERE id = :id', ['id' => $legacyId]),
            true
        );
        Assert::true(is_array($filled), 'extra_json يبقى JSON صالحًا بعد الملء');
        Assert::same('سنة', $filled['warranty'] ?? null, 'الحقل النصي الجديد يأخذ قيمته الافتراضية');
        Assert::same(1, $filled['gift_wrap'] ?? null, 'الحقل المنطقي الجديد يأخذ قيمته الافتراضية');
        Assert::same(1, $filled['vegetarian'] ?? null, 'الحقول القديمة تبقى كما هي');
        Assert::false(array_key_exists('warranty_no', $filled),
            'الحقل الذي قيمته الافتراضية فارغ يُترك غائبًا لا محمولًا بقيمة null');

        // المنتج الذي يحمل القيمة أصلًا لا يُكتب فوقه.
        $kept = json_decode(
            (string) Database::value('SELECT extra_json FROM products WHERE id = :id', ['id' => $existingId]),
            true
        );
        Assert::same('سنة', $kept['warranty'] ?? null, 'قيمة موجودة مسبقًا لا تُستبدل');
        Assert::same(0, $kept['gift_wrap'] ?? null, 'قيمة منطقية موجودة (false) لا تُقلب إلى true');
    } finally {
        ProjectConfig::setOverrides(null);
    }

    /* =====================================================================
     * ٤. حالة النسخة — قبل التدمير
     * ===================================================================== */
    Assert::group('دورة حياة النسخة — الحالة');

    $sandbox = makeSandbox();
    $service = new InstanceService();

    Assert::true($service->isInstalled($sandbox), 'النسخة المُحاكاة مهيأة');
    Assert::false($service->isInstalled($sandbox . '-لا-يوجد'), 'مسار فارغ ليس نسخة مهيأة');

    $state = $service->state($sandbox);
    Assert::true($state['installed'], 'الحالة: مهيأة');
    Assert::same(2, $state['counts']['products'], 'الحالة: عدد المنتجات');
    Assert::same(3, $state['counts']['orders'], 'الحالة: عدد الطلبات');
    Assert::true($state['setup_available'], 'الحالة: صفحة التهيئة موجودة');

    // كشف بنية القرص للمدير عبر الويب لا فائدة منه.
    foreach ($state['paths'] as $label => $path) {
        Assert::false(str_starts_with($path, '/'), 'مسار ' . $label . ' نسبي لا مطلق');
        Assert::false(str_contains($path, $sandbox), 'مسار ' . $label . ' لا يكشف مسار الرمل');
    }

    $empty = $service->state($sandbox . '-لا-يوجد');
    Assert::false($empty['installed'], 'حالة غير مهيأة: installed = false');
    Assert::same(0, $empty['counts']['orders'], 'حالة غير مهيأة: بلا عدّادات');

    /* =====================================================================
     * ٥. التأكيد إلزامي — لا محو بلا عبارة صريحة
     * ===================================================================== */
    Assert::group('دورة حياة النسخة — التأكيد إلزامي');

    // رمل مستقل عن رمل التدمير الكامل: أي رفض خاطئ هنا يُظهر نفسه
    // كفشل لاحق بدل أن يمرّ صامتًا.
    $guarded = makeSandbox();

    Assert::throws(
        static fn() => (new InstanceService())->destroy($guarded),
        RuntimeException::class,
        'destroy بلا confirm مرفوض'
    );
    Assert::throws(
        static fn() => (new InstanceService())->destroy($guarded, ['confirm' => 'نعم']),
        RuntimeException::class,
        'destroy بكلمة خاطئة مرفوض'
    );
    Assert::throws(
        static fn() => (new InstanceService())->destroy($guarded, ['confirm' => '']),
        RuntimeException::class,
        'destroy بعبارة فارغة مرفوض'
    );
    Assert::throws(
        static fn() => (new InstanceService())->destroy($guarded, ['confirm' => 'تدمير النسخة']),
        RuntimeException::class,
        'destroy بعبارة أطول مرفوض — لا StartsAvec'
    );
    Assert::throws(
        static fn() => (new InstanceService())->destroy($guarded, ['confirm' => 'نعم']),
        RuntimeException::class,
        'destroy بعبارة مختلفة تمامًا مرفوض'
    );

    Assert::true(is_file($guarded . '/storage/config/local.php'), 'بعد رفض التأكيد: ملف الإعدادات باقٍ');
    Assert::true(is_file($guarded . '/storage/database/app.sqlite'), 'بعد رفض التأكيد: القاعدة باقية');
    Assert::true(is_file($guarded . '/app/data/company.json'), 'بعد رفض التأكيد: ملف الشركة باقٍ');
    Assert::true(is_file($guarded . '/app/uploads/products/old.png'), 'بعد رفض التأكيد: الصور باقية');

    // المسافات الطرفية تُتجاهل عمدًا: المستخدم يكتب العبارة بنفسه،
    // والمسافة الزائدة في طرفها لا تعني نيّة مختلفة.
    Assert::passes(
        static fn() => (new InstanceService())->destroy($guarded, ['confirm' => ' ' . InstanceService::CONFIRM_WORD . ' ']),
        'المسافات الطرفية حول العبارة لا تمنع التنفيذ'
    );
    Assert::false(is_file($guarded . '/storage/config/local.php'), 'التدمير تمّ بالعبارة محاطة بمسافات');
    dropSandbox($guarded);

    Assert::throws(
        static fn() => (new InstanceService())->destroy($sandbox . '-لا-يوجد', ['confirm' => 'تدمير']),
        RuntimeException::class,
        'destroy بلا نسخة مهيأة مرفوض'
    );

    /* =====================================================================
     * ٦. التدمير الكامل
     * ===================================================================== */
    Assert::group('دورة حياة النسخة — التدمير الكامل');

    $report = $service->destroy($sandbox, ['confirm' => InstanceService::CONFIRM_WORD]);

    Assert::true($report['ok'], 'التدمير أتمّ');
    Assert::same(2, $report['counts']['products'], 'التقرير يحفظ عدد المنتجات قبل الحذف');
    Assert::same(3, $report['counts']['orders'], 'التقرير يحفظ عدد الطلبات قبل الحذف');
    Assert::greater(count($report['removed']), 5, 'التقرير يسرد ما حُذف');

    Assert::false(is_file($sandbox . '/storage/config/local.php'), 'حُذف ملف الإعدادات');
    Assert::false(is_file($sandbox . '/storage/database/app.sqlite'), 'حُذفت قاعدة البيانات');
    Assert::false(is_file($sandbox . '/storage/database/app.sqlite-wal'), 'حُذف ملف WAL');
    Assert::false(is_file($sandbox . '/storage/database/app.sqlite-shm'), 'حُذف ملف SHM');
    Assert::false(is_file($sandbox . '/app/data/company.json'), 'حُذف محتوى الشركة');

    Assert::false(is_file($sandbox . '/app/uploads/products/old.png'), 'حُذفت صور المنتجات');
    Assert::false(is_file($sandbox . '/app/uploads/brand/logo.png'), 'حُذف الشعار');
    Assert::true(is_file($sandbox . '/app/uploads/.htaccess'), 'بقي ملف حماية الرفع');
    Assert::true(is_dir($sandbox . '/app/uploads'), 'بقي مجلد الرفع نفسه');

    Assert::false(is_file($sandbox . '/storage/backups/backup-20260101-000000.sqlite'), 'حُذفت النسخ الاحتياطية');
    Assert::false(is_file($sandbox . '/storage/logs/app-2026-01-01.log'), 'حُذفت السجلات');
    Assert::false(is_file($sandbox . '/storage/tmp/rate-abc.json'), 'حُذفت عدّادات المعدل');
    Assert::false(is_file($sandbox . '/storage/uploads/leftover.tmp'), 'حُذف محتوى storage/uploads');

    // القالب لا يُمَس — هذا هو الفرق بين محو البيانات ومحو المشروع.
    Assert::true(is_file($sandbox . '/src/Config/ProjectConfig.php'), 'ملف القالب لم يُحذف');
    Assert::true(is_file($sandbox . '/setup/index.php'), 'صفحة التهيئة لم تُحذف');

    foreach (['database', 'backups', 'logs', 'tmp', 'uploads'] as $folder) {
        Assert::true(is_dir($sandbox . '/storage/' . $folder),
            'أُعيد إنشاء storage/' . $folder . ' فارغًا وجاهزًا للبناء الجديد');
    }

    Assert::false($service->isInstalled($sandbox), 'بعد التدمير: النسخة غير مهيأة');
    Assert::false((new InstanceService())->state($sandbox)['installed'], 'بعد التدمير: الحالة تقول غير مهيأة');

    /* =====================================================================
     * ٧. نسخة الأمان تبقى بعد الحذف
     * ===================================================================== */
    Assert::group('دورة حياة النسخة — نسخة الأمان');

    Assert::true($report['archive'] !== '', 'التقرير يشير إلى مجلد نسخة الأمان');
    Assert::startsWith($report['archive'], InstanceService::ARCHIVE_DIR,
        'الأرشيف في مجلده المخصّص');
    Assert::notContains($report['archive'], 'storage/backups',
        'الأرشيف ليس داخل مجلد النسخ الذي تطاله عملية الحذف');

    $archiveDir = $sandbox . '/' . $report['archive'];
    Assert::true(is_file($archiveDir . '/app.sqlite'), 'الأرشيف يحفظ قاعدة البيانات');
    Assert::true(is_file($archiveDir . '/local.php'), 'الأرشيف يحفظ ملف الإعدادات');
    Assert::true(is_file($archiveDir . '/manifest.json'), 'الأرشيف يحفظ بيان الاسترجاع');

    $manifest = json_decode((string) file_get_contents($archiveDir . '/manifest.json'), true);
    Assert::true(is_array($manifest), 'البيان JSON صالح');
    Assert::same(3, $manifest['counts']['orders'] ?? null, 'البيان يسجّل عدد الطلبات قبل التدمير');
    Assert::true(!empty($manifest['restore']), 'البيان يشرح كيف تُستعاد النسخة');
    Assert::contains((string) file_get_contents($archiveDir . '/local.php'), 'username',
        'ملف الإعدادات في الأرشيف سليم');

    $archives = (new InstanceService())->state($sandbox)['archives'];
    Assert::true(count($archives) >= 1, 'الأرشيف يظهر في حالة النسخة');
    Assert::startsWith($archives[0]['name'] ?? '', 'destroyed-',
        'مجلد الأرشيف مسمى بتاريخ');
    Assert::true(!str_contains($archives[0]['path'] ?? '', $sandbox),
        'حالة النسخة لا تكشف المسار المطلق للأرشيف');

    /* =====================================================================
     * ٨. الحذف بلا نسخة أمان
     * ===================================================================== */
    Assert::group('دورة حياة النسخة — الحذف بلا نسخة أمان');

    $sandbox2 = makeSandbox();
    $report2 = (new InstanceService())->destroy($sandbox2, [
        'confirm' => InstanceService::CONFIRM_WORD,
        'archive' => false,
    ]);

    Assert::same('', $report2['archive'], 'لا مجلد أرشيف عند الطلب الصريح');
    Assert::false(is_file($sandbox2 . '/storage/config/local.php'), 'الحذف تمّ رغم غياب الأرشيف');
    dropSandbox($sandbox2);

    /* =====================================================================
     * ٩. الإبقاء على النسخ الاحتياطية
     * ===================================================================== */
    Assert::group('دورة حياة النسخة — keep_backups');

    $sandbox3 = makeSandbox();
    (new InstanceService())->destroy($sandbox3, [
        'confirm'      => InstanceService::CONFIRM_WORD,
        'keep_backups' => true,
    ]);

    Assert::true(is_file($sandbox3 . '/storage/backups/backup-20260101-000000.sqlite'),
        'النسخ الاحتياطية تبقى مع keep_backups');
    Assert::false(is_file($sandbox3 . '/storage/config/local.php'), 'بقية البيانات تُحذف رغم الإبقاء');
    dropSandbox($sandbox3);

    /* =====================================================================
     * ١٠. مسار إعادة التوجيه إلى صفحة التهيئة
     * ===================================================================== */
    Assert::group('التوجيه بعد غياب الإعدادات');

    Assert::true(function_exists('setupLinkLevelsUp'), 'دالة حساب مستويات الجذر معرَّفة');

    $previous = $_SERVER['SCRIPT_NAME'] ?? null;

    $_SERVER['SCRIPT_NAME'] = '/index.php';
    Assert::same(0, setupLinkLevelsUp(), 'سكربت الجذر: 0 مستويات');
    Assert::same('/setup/index.php',
        App\Core\Web\Paths::url('setup/index.php', setupLinkLevelsUp()),
        'التوجيه من الجذر مطلق');

    $_SERVER['SCRIPT_NAME'] = '/admin/index.php';
    Assert::same(1, setupLinkLevelsUp(), 'من admin: مستوى واحد');
    Assert::same('/setup/index.php',
        App\Core\Web\Paths::url('setup/index.php', setupLinkLevelsUp()),
        'التوجيه من /admin/ لا يصير /admin/setup/');

    $_SERVER['SCRIPT_NAME'] = '/shop/api/index.php';
    Assert::same(1, setupLinkLevelsUp(), 'من مجلد فرعي: مستوى واحد لا اثنان');
    Assert::same('/shop/setup/index.php',
        App\Core\Web\Paths::url('setup/index.php', setupLinkLevelsUp()),
        'التوجيه داخل مجلد فرعي يحفظ المسار');

    $_SERVER['SCRIPT_NAME'] = '/shop/index.php';
    Assert::same(0, setupLinkLevelsUp(), 'سكربت الجذر داخل مجلد فرعي: 0 مستويات');
    Assert::same('/shop/setup/index.php',
        App\Core\Web\Paths::url('setup/index.php', setupLinkLevelsUp()),
        'التوجيه من جذر فرعي لا يقفز فوقه');

    if ($previous === null) {
        unset($_SERVER['SCRIPT_NAME']);
    } else {
        $_SERVER['SCRIPT_NAME'] = $previous;
    }

    /* =====================================================================
     * ١١. حارس إعادة البناء
     * ===================================================================== */
    Assert::group('دورة حياة النسخة — مفتاح إعادة البناء');

    $sandbox4 = makeSandbox();
    $svc4     = new InstanceService();

    // أوّل تثبيت: لا مفتاح، فالنموذج مفتوح — بلا خطوة إضافية في أوّل مرة.
    Assert::false($svc4->rebuildPending($sandbox4), 'قبل التدمير: لا إعادة بناء معلّقة');
    Assert::true($svc4->checkRebuildKey(null, $sandbox4), 'قبل التدمير: أي مفتاح مقبول');
    Assert::true($svc4->checkRebuildKey('أي-شيء', $sandbox4), 'قبل التدمير: حتى مفتاح خاطئ يُقبل');

    $rep4 = $svc4->destroy($sandbox4, ['confirm' => InstanceService::CONFIRM_WORD]);
    $key4 = $rep4['rebuildKey'] ?? '';

    Assert::true(is_string($key4) && strlen($key4) >= 32, 'التدمير ينتج مفتاحًا عشوائيًا');
    Assert::same($key4, $svc4->rebuildKey($sandbox4), 'المفتاح محفوظ على القرص');
    Assert::true($svc4->rebuildPending($sandbox4), 'إعادة البناء معلّقة بعد التدمير');
    Assert::false($svc4->rebuildPending(), 'مفتاح الرمل لا يلوّث النسخة الحقيقية');

    // نسخة دُمّرت بإصدار سابق للميزة: أرشيف بلا مفتاح. وهي أخطر من
    // «أوّل تثبيت» لأنها تبدو كذلك تمامًا — فيفتحها أي شخص يعرف الرابط.
    $legacy = makeSandbox();
    (new InstanceService())->destroy($legacy, ['confirm' => InstanceService::CONFIRM_WORD]);
    unlink($legacy . '/' . InstanceService::REBUILD_KEY_FILE);
    Assert::same(null, (new InstanceService())->rebuildKey($legacy), 'نسخة قديمة: لا مفتاح على القرص');
    Assert::true((new InstanceService())->rebuildPending($legacy),
        'نسخة قديمة مدمَّرة: مقفلة رغم غياب المفتاح (الأرشيف دليل)');
    Assert::false((new InstanceService())->checkRebuildKey(null, $legacy),
        'نسخة قديمة: بلا مفتاح تُرفض ولا تبدو أوّل تثبيت');
    Assert::passes(
        static fn() => (new InstanceService())->destroy($legacy, ['confirm' => InstanceService::CONFIRM_WORD]),
        'التدمير الثاني يظل ممكنًا لتدوير المفتاح'
    );
    dropSandbox($legacy);

    // هذا هو الباب الذي كان nginx 404 يحرسه صدفةً: بلا مفتاح، أي شخص
    // يفتح الرابط ويضع كلمة مروره فيصبح المدير.
    Assert::false($svc4->checkRebuildKey(null, $sandbox4), 'بلا مفتاح: يُرفض');
    Assert::false($svc4->checkRebuildKey('', $sandbox4), 'بمفتاح فارغ: يُرفض');
    Assert::false($svc4->checkRebuildKey('00000000000000000000000000000000', $sandbox4), 'بمفتاح خاطئ: يُرفض');
    Assert::false($svc4->checkRebuildKey(substr($key4, 0, -1), $sandbox4), 'بمفتاح ناقص حرفًا: يُرفض');
    Assert::false($svc4->checkRebuildKey('t' . $key4, $sandbox4), 'بمفتاح زائد حرفًا: يُرفض');
    Assert::true($svc4->checkRebuildKey($key4, $sandbox4), 'بالمفتاح الصحيح: يُقبل');
    Assert::true($svc4->checkRebuildKey('  ' . $key4 . '  ', $sandbox4), 'المسافات الطرفية لا تمنع');

    // تدمير ثانٍ يدوّر المفتاح: القديم لم يعد صالحًا، وهو المخرج
    // الوحيد لمفتاح ضائع (لا جلسة ولا لوحة بعد التدمير).
    $rep4b = $svc4->destroy($sandbox4, ['confirm' => InstanceService::CONFIRM_WORD]);
    Assert::true(($rep4b['rebuildKey'] ?? '') !== '', 'التدمير الثاني ينتج مفتاحًا جديدًا');
    Assert::notSame($key4, $svc4->rebuildKey($sandbox4), 'تدمير ثانٍ يدوّر المفتاح');
    Assert::false($svc4->checkRebuildKey($key4, $sandbox4), 'المفتاح القديم يبطل بعد تدمير جديد');

    // أوّل بناء ناجح يعيد الموقع لطبيعته: يُحذف المفتاح، ووجود النسخة
    // المهيأة وحده يفتح النموذج حتى مع بقاء الأرشيف.
    file_put_contents($sandbox4 . '/storage/config/local.php', "<?php\nreturn [];\n");
    $svc4->consumeRebuildKey($sandbox4);
    Assert::false(is_file($sandbox4 . '/' . InstanceService::REBUILD_KEY_FILE),
        'أول بناء ناجح يحذف ملف المفتاح من القرص');
    Assert::false($svc4->rebuildPending($sandbox4),
        'بعد البناء: لا قفل حتى مع بقاء الأرشيف');
    Assert::true($svc4->checkRebuildKey(null, $sandbox4), 'بعد البناء: النموذج مفتوح من جديد');
    Assert::true($svc4->isInstalled($sandbox4), 'بعد البناء: النسخة مهيأة');

    dropSandbox($sandbox4);

    dropSandbox($sandbox);
};
