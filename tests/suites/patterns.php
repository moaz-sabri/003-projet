<?php

declare(strict_types=1);

use App\Config\ProjectConfig;
use App\Config\TemplatePatterns;
use App\Core\CompanyProfile;
use App\Core\Http\Request;
use App\Core\RuntimeSettings;
use App\Repositories\ProductRepository;

/**
 * أنماط القدرات: ليست أنواع أنشطة، بل ما يستطيعه القالب فعلًا.
 * الحماية المهمة هنا أن النمط يحفظ لقطة blueprint تبقى بعد ترقية القالب،
 * وأن النمط المخصص لا يسمح بقيم قدرات خارج المجموعات المغلقة.
 */
return static function (): void {
    Assert::group('أنماط القدرات');

    $all = TemplatePatterns::all();
    foreach (['orders_tables', 'orders', 'reservations', 'showcase', 'showcase_details', 'showcase_order', 'custom'] as $id) {
        Assert::true(isset($all[$id]), 'النمط موجود: ' . $id);
    }
    Assert::same('showcase_details', TemplatePatterns::defaultId(), 'النمط الافتراضي عرض مع تفاصيل');

    /* ------------------------- الأنماط الجاهزة ------------------------- */

    $tables = TemplatePatterns::toOverrides('orders_tables');
    Assert::same(TemplatePatterns::MODE_ORDERS, $tables['mode'], 'طلبات وطاولات: وضع الطلبات');
    Assert::true((bool) $tables['multi_item_orders'], 'طلبات وطاولات: عدة عناصر');
    Assert::true((bool) $tables['location_required'], 'طلبات وطاولات: الموقع إلزامي');
    Assert::true((bool) $tables['feature_overrides']['product_gallery'], 'طلبات وطاولات: معرض صور');
    Assert::same(
        TemplatePatterns::PLACE_REQUIRED,
        $tables['template_blueprint']['places'],
        'طلبات وطاولات: blueprint يحفظ المواقع الإلزامية'
    );

    $reservations = TemplatePatterns::toOverrides('reservations');
    Assert::true(isset($reservations['order_types']['reservation']), 'حجوزات: نوع الحجز موجود');
    Assert::false((bool) $reservations['multi_item_orders'], 'حجوزات: بلا سلة متعددة');
    Assert::same(
        TemplatePatterns::ORDER_RESERVATION,
        $reservations['template_blueprint']['ordering'],
        'حجوزات: blueprint يحفظ وضع الحجز'
    );

    $showcase = TemplatePatterns::toOverrides('showcase');
    Assert::same(TemplatePatterns::MODE_CATALOG, $showcase['mode'], 'عرض فقط: وضع الكتالوج');
    Assert::false((bool) $showcase['feature_overrides']['product_details'], 'عرض فقط: بلا تفاصيل');
    Assert::same([], $showcase['order_types'], 'عرض فقط: بلا أنواع طلبات');

    $showcaseDetails = TemplatePatterns::toOverrides('showcase_details');
    Assert::true(
        (bool) $showcaseDetails['feature_overrides']['product_gallery'],
        'عرض مع تفاصيل: معرض الصور مفعّل'
    );

    $single = TemplatePatterns::toOverrides('showcase_order');
    Assert::same(
        TemplatePatterns::ORDER_SINGLE,
        $single['template_blueprint']['ordering'],
        'عرض وطلب لمرة واحدة: طلب عنصر واحد'
    );

    // الوصف يُستخدم في CLI ولوحة الإدارة، فلا يكرّر «المعرض» مرتين.
    Assert::same(
        'عرض فقط بلا طلبات · صفحة تفاصيل ومعرض صور',
        TemplatePatterns::describe($showcaseDetails['template_blueprint']),
        'الوصف لا يكرّر المعرض حين تكون التفاصيل معرضًا'
    );

    /* -------------------------- النمط المخصص -------------------------- */

    $custom = TemplatePatterns::toOverrides('custom', [
        'ordering'    => TemplatePatterns::ORDER_CART,
        'multi_items' => true,
        'places'      => TemplatePatterns::PLACE_OPTIONAL,
        'details'     => TemplatePatterns::DETAIL_PAGE,
        'media'       => TemplatePatterns::MEDIA_GALLERY,
        'tracking'    => true,
        'singular'    => 'مركبة',
        'plural'      => 'المركبات',
    ]);

    Assert::same(TemplatePatterns::ORDER_CART, $custom['template_blueprint']['ordering'], 'مخصص: سلة');
    Assert::true((bool) $custom['multi_item_orders'], 'مخصص: عدة عناصر');
    Assert::same('مركبة', $custom['product_singular'], 'مخصص: المفرد كما كتبه المستخدم');
    Assert::same('المركبات', $custom['product_plural'], 'مخصص: الجمع كما كتبه المستخدم');
    Assert::true((bool) $custom['feature_overrides']['locations'], 'مخصص: المواقع الاختيارية تفعّل الميزة');

    /* ------------------------ التطبيع والقيم المغلقة ------------------------ */

    $normalized = TemplatePatterns::normalizeBlueprint([
        'ordering' => 'anything',
        'places'   => 'everywhere',
        'details'  => 'full',
        'media'    => 'many',
        'reservation' => false,
    ]);
    Assert::same(TemplatePatterns::ORDER_NONE, $normalized['ordering'], 'قيمة طلب غير معروفة تسقط إلى none');
    Assert::same(TemplatePatterns::PLACE_NONE, $normalized['places'], 'قيمة مواقع غير معروفة تسقط إلى none');
    Assert::same(TemplatePatterns::DETAIL_NONE, $normalized['details'], 'قيمة تفاصيل غير معروفة تسقط إلى none');
    Assert::same(TemplatePatterns::MEDIA_SINGLE, $normalized['media'], 'قيمة وسائط غير معروفة تسقط إلى single');

    // الحجز يفرض نفسه حتى لو أرسل العميل نوعًا آخر
    $reservationWins = TemplatePatterns::normalizeBlueprint([
        'ordering' => TemplatePatterns::ORDER_CART,
        'reservation' => true,
    ]);
    Assert::same(TemplatePatterns::ORDER_RESERVATION, $reservationWins['ordering'], 'الحجز يتقدّم على السلة');
    Assert::false($reservationWins['multi_items'], 'الحجز يلغي تعدد العناصر');

    // التفاصيل والمعرض لا يتعارضان في النمط المخصص
    $galleryDetails = TemplatePatterns::normalizeBlueprint([
        'details' => TemplatePatterns::DETAIL_GALLERY,
        'media'   => TemplatePatterns::MEDIA_SINGLE,
    ]);
    Assert::same(TemplatePatterns::MEDIA_GALLERY, $galleryDetails['media'], 'تفاصيل + معرض تفرض المعرض');

    $galleryOnly = TemplatePatterns::normalizeBlueprint([
        'details' => TemplatePatterns::DETAIL_NONE,
        'media'   => TemplatePatterns::MEDIA_GALLERY,
    ]);
    Assert::same(TemplatePatterns::DETAIL_PAGE, $galleryOnly['details'], 'معرض بلا تفاصيل يفتح صفحة تفاصيل');

    /* ------------------------ التوافق مع الأنشطة القديمة ------------------------ */

    Assert::same('orders_tables', TemplatePatterns::legacyPatternMap()['restaurant'], 'restaurant → طلبات وطاولات');
    Assert::same('orders', TemplatePatterns::legacyPatternMap()['store'], 'store → طلبات فقط');
    $legacy = TemplatePatterns::toOverrides('restaurant');
    Assert::true(
        isset($legacy['template_blueprint']['ordering']),
        'المعرّف القديم يُنتج blueprint أيضًا'
    );

    /* ------------------------- الصفحة الرئيسية ------------------------- */

    $home = CompanyProfile::payload()['home'];
    foreach ([
        'layout', 'height', 'hero', 'show_highlights', 'show_sections',
        'show_gallery', 'show_stats', 'show_testimonials', 'show_faq',
        'show_cta', 'show_contact', 'stats', 'testimonials', 'faq',
    ] as $key) {
        Assert::true(array_key_exists($key, $home), 'حمولة الرئيسية تحوي: ' . $key);
    }
    Assert::true(in_array($home['layout'], ['classic', 'split', 'cover', 'spotlight'], true), 'تخطيط الرئيسية ضمن القيم المغلقة');
    Assert::true(in_array($home['height'], ['compact', 'large', 'full'], true), 'ارتفاع الرئيسية ضمن القيم المغلقة');

    // تجاوز إعدادات الإدارة: '' يرث، '0' يخفي، '1' يظهر
    $keys = ['home_show_stats', 'home_hero_title'];
    $before = [];
    foreach ($keys as $key) {
        $before[$key] = RuntimeSettings::get($key, '');
    }
    try {
        RuntimeSettings::setMany([
            'home_show_stats'  => '0',
            'home_hero_title'  => 'عنوان من الإدارة',
        ]);
        RuntimeSettings::flush();
        CompanyProfile::flush();

        $overridden = CompanyProfile::payload()['home'];
        Assert::same(false, $overridden['show_stats'], 'إعداد الإدارة 0 يخفي الأرقام');
        Assert::same('عنوان من الإدارة', $overridden['hero']['title'], 'إعداد الإدارة يتقدّم على الملف');

        RuntimeSettings::setMany(['home_show_stats' => '']);
        RuntimeSettings::flush();
        CompanyProfile::flush();
        Assert::same(true, CompanyProfile::payload()['home']['show_stats'], 'القيمة الفارغة ترث من company.json');
    } finally {
        RuntimeSettings::setMany($before);
        RuntimeSettings::flush();
        CompanyProfile::flush();
    }

    /* ------------------- روابط صور لوحة الإدارة ------------------- */

    // مسار مخزَّن مثل brand/x.jpg يجب أن يُبنى مرة واحدة فقط:
    // /app/uploads/brand/x.jpg لا /app/uploads/app/uploads/brand/x.jpg.
    $imageKeys = ['logo_path', 'hero_image_path'];
    $beforeImages = [];
    foreach ($imageKeys as $key) {
        $beforeImages[$key] = RuntimeSettings::get($key, '');
    }
    try {
        RuntimeSettings::setMany([
            'logo_path'       => 'brand/logo-test.png',
            'hero_image_path' => 'brand/hero-test.jpg',
        ]);
        RuntimeSettings::flush();
        CompanyProfile::flush();

        $images = CompanyProfile::payload()['brand'];
        Assert::same(
            1,
            substr_count($images['logo'], 'app/uploads/'),
            'شعار الإدارة لا يتضاعف مساره'
        );
        Assert::same(
            1,
            substr_count($images['hero_image'], 'app/uploads/'),
            'غلاف الإدارة لا يتضاعف مساره'
        );
        Assert::false(
            str_contains($images['logo'], 'app/uploads/app/uploads/'),
            'شعار الإدارة ليس /app/uploads/app/uploads/'
        );
    } finally {
        RuntimeSettings::setMany($beforeImages);
        RuntimeSettings::flush();
        CompanyProfile::flush();
    }

    /* ------------------------ معرض صور المنتج ------------------------ */

    ProjectConfig::setOverrides(TemplatePatterns::toOverrides('showcase_details'));
    try {
        // لوحة الإدارة تعرض وصف النمط وقدراته من نفس الحمولة العامة.
        $config = (new \App\Services\CatalogService())->configPayload();
        Assert::same('showcase_details', $config['pattern_id'], 'حمولة الإعدادات تحمل معرّف النمط');
        Assert::same(
            'عرض فقط بلا طلبات · صفحة تفاصيل ومعرض صور',
            $config['pattern_description'],
            'حمولة الإعدادات تحمل وصف القدرات'
        );

        $repo = new ProductRepository();
        $paths = $repo->galleryPaths([
            'gallery' => [
                'products/a.jpg',
                '../evil.png',
                '/etc/passwd',
                'https://example.com/x.png',
                'products/b.webp',
                'products/a.jpg',
            ],
        ]);
        Assert::same(['products/a.jpg', 'products/b.webp'], $paths, 'المعرض يقبل مسارات الرفع فقط ويزيل التكرار');

        $many = [];
        for ($i = 1; $i <= 12; $i++) {
            $many[] = 'products/' . $i . '.jpg';
        }
        Assert::same(8, count($repo->galleryPaths(['gallery' => $many])), 'الحد الأقصى للمعرض ثماني صور');
    } finally {
        ProjectConfig::setOverrides(null);
    }

    /* ---------------------- رفع عدة ملفات من حقل واحد ---------------------- */

    $multi = new Request([], [], ['gallery' => [
        'name'     => ['a.png', 'b.png', ''],
        'type'     => ['image/png', 'image/jpeg', ''],
        'tmp_name' => ['/tmp/a', '/tmp/b', ''],
        'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
        'size'     => [10, 20, 0],
    ]], []);
    $list = $multi->files('gallery');
    Assert::same(2, count($list), 'رفع متعدد يتجاهل الملفات الفارغة');
    Assert::same('b.png', $list[1]['name'], 'ترتيب الملفات محفوظ');

    $single = new Request([], [], ['logo' => [
        'name' => 'x.png', 'type' => 'image/png', 'tmp_name' => '/tmp/x',
        'error' => UPLOAD_ERR_OK, 'size' => 5,
    ]], []);
    Assert::same(1, count($single->files('logo')), 'الحقل المفرد يعمل مع files()');
    Assert::same([], $single->files('missing'), 'حقل غير موجود يعيد قائمة فارغة');
};
