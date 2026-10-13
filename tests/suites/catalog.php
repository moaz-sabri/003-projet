<?php

declare(strict_types=1);

/**
 * واجهة الزبون: الكتالوج، القواعد، السلة، والمال.
 */

use App\Config\ProjectConfig;
use App\Core\Money;
use App\Repositories\ProductRepository;
use App\Services\CatalogService;

return function (): void {
    global $coffee, $tea, $juice, $cat1, $cat2;

    Assert::group('الكتالوج — العرض والتصنيفات');

    $catalog = new CatalogService();
    $snapshot = $catalog->snapshot();

    Assert::greater(count($snapshot['products']), 0, 'الكتالوج يعيد منتجات');
    Assert::greater(count($snapshot['categories']), 0, 'الكتالوج يعيد تصنيفات');
    Assert::true(array_key_exists('settings', $snapshot), 'الحمولة تتضمن إعدادات العرض');
    Assert::true(array_key_exists('config', $snapshot), 'الحمولة تتضمن إعدادات البنية');
    Assert::true(array_key_exists('locations', $snapshot), 'الحمولة تتضمن المواقع النشطة');

    // التصنيفات النشطة فقط
    foreach ($snapshot['categories'] as $category) {
        Assert::true((bool) $category['active'], 'التصنيف المعروض نشط: ' . $category['name']);
    }

    // المنتجات غير المتاحة لا تظهر في الكتالوج العام
    \App\Core\Database::update('products', ['is_available' => 0], 'id = :id', ['id' => $tea]);
    $after = $catalog->snapshot();
    $ids   = array_column($after['products'], 'id');
    Assert::false(in_array($tea, $ids, true), 'المنتج غير المتاح لا يظهر في الكتالوج');
    \App\Core\Database::update('products', ['is_available' => 1], 'id = :id', ['id' => $tea]);

    /* (1) صفحة التفاصيل تُفتح فقط عند وجود ما يستحقها ---------------------- */
    Assert::group('صفحة تفاصيل المنتج');

    $repo = new ProductRepository();
    $catalogProducts = $repo->catalog();
    $byId = [];
    foreach ($catalogProducts as $product) {
        $byId[$product['id']] = $product;
    }

    Assert::false($byId[$coffee]['has_details'], 'منتج بوصف قصير بلا حقول: بلا صفحة تفاصيل');
    Assert::true($byId[$juice]['has_details'], 'منتج بوصف طويل: صفحة تفاصيل');

    $details = $catalog->productDetails($juice);
    Assert::true($details !== null, 'تفاصيل المنتج متاحة عبر الـ API');
    Assert::greater(count($details['details']), 0, 'تفاصيل المنتج تحتوي حقولًا');

    Assert::same(null, $catalog->productDetails(999999), 'منتج غير موجود يعيد null');

    /* (2) البحث ------------------------------------------------------------ */
    Assert::group('البحث والتصفية');

    $search = $catalog->snapshot('قهوة');
    Assert::greater(count($search['products']), 0, 'البحث عن قهوة يعيد نتائج');
    foreach ($search['products'] as $product) {
        Assert::contains($product['name'] . $product['description'], 'قهوة', 'كل نتيجة مطابقة لبحث المستخدم');
    }

    $noResults = $catalog->snapshot('كلمةغيرموجودة١٢٣');
    Assert::same(0, count($noResults['products']), 'بحث بلا نتائج يعيد قائمة فارغة');

    /* (3) إعدادات البنيةREEL للواجهة ------------------------------------- */
    Assert::group('إعدادات القالب للواجهة');

    $config = $snapshot['config'];
    Assert::same('ar', $config['locale'], 'اللغة عربية');
    Assert::same('rtl', $config['direction'], 'الاتجاه RTL');
    Assert::greater(count($config['order_types']), 0, 'أنواع الطلبات معرّفة');
    Assert::greater(count($config['statuses']), 0, 'حالات الطلبات معرّفة');
    Assert::true($config['features']['locations'], 'ميزة المواقع مفعّلة');

    $dineIn = null;
    $takeaway = null;
    foreach ($config['order_types'] as $type) {
        if ($type['key'] === 'dine_in') {
            $dineIn = $type;
        }
        if ($type['key'] === 'takeaway') {
            $takeaway = $type;
        }
    }
    Assert::true($dineIn['requires_location'], 'نوع الطلب في المحل يفرض الموقع');
    Assert::false($takeaway['requires_location'], 'نوع الطلب السفري لا يفرض الموقع');
    Assert::greater(count($dineIn['fields']), 0, 'نوع الطلب في المحل يحمل حقولًا مخصصة');

    /* (4) حساب المال ------------------------------------------------------- */
    Assert::group('حساب الأسعار');

    Assert::same(10000, Money::parseToMinor('10000'), 'تحويل سعر صحيح');
    Assert::same(12000, Money::parseToMinor('12,000'), 'تحويل سعر بفاصل آلاف');
    Assert::same(12000, Money::parseToMinor('12.000'), 'فاصل آلاف بالنقطة أيضًا');
    Assert::same(15, Money::parseToMinor('15'), 'تحويل بدون فواصل');
    Assert::same(1000, Money::parseToMinor('1,000'), 'تحويل عدد صحيح مع فاصل');
    Assert::same(1234, Money::parseToMinor('1,234'), 'تحويل عدد بفاصل آلاف');
    Assert::same(12500, Money::parseToMinor('12 500'), 'تحويل مع مسافة فاصل آلاف');
    Assert::same(25000, Money::parseToMinor('25,000 ل.ل'), 'تحويل مع رمز العملة');
    Assert::same(0, Money::parseToMinor('abc'), 'نص غير رقمي = صفر');
    Assert::same(0, Money::parseToMinor(''), 'قيمة فارغة = صفر');
    Assert::same(0, Money::parseToMinor(null), 'قيمة null = صفر');

    Assert::contains(Money::format(250000), '250,000', 'تنسيق السعر بالألف فاصلة');
    Assert::contains(Money::format(250000), ProjectConfig::CURRENCY, 'رمز العملة يظهر في التنسيق');

    /* (5) حساب السلة ------------------------------------------------------- */
    Assert::group('السلة');

    $cart = [
        ['product_id' => $coffee, 'quantity' => 2],
        ['product_id' => $tea, 'quantity' => 1],
    ];
    $total = 0;
    foreach ($cart as $line) {
        $price = (int) \App\Core\Database::value('SELECT price_minor FROM products WHERE id = :id', ['id' => $line['product_id']]);
        $total += $price * $line['quantity'];
    }
    Assert::same(25000, $total, 'إجمالي السلة = مجموع (السعر × الكمية)');

    /* (6) حدود الدفع ------------------------------------------------------- */
    Assert::group('حدود الدفع والبيانات');

    Assert::same(99, ProjectConfig::MAX_QTY_PER_ITEM, 'حد الكمية لكل عنصر');
    Assert::true(ProjectConfig::MAX_ITEMS_PER_ORDER <= 100, 'حد عدد عناصر الطلب معقول');

    $defaultType = null;
    foreach (ProjectConfig::ORDER_TYPES as $key => $def) {
        if (!empty($def['default'])) {
            $defaultType = $key;
        }
    }
    Assert::true($defaultType !== null, 'يوجد نوع طلب افتراضي واحد على الأقل');
    Assert::true(ProjectConfig::orderType($defaultType) !== null, 'النوع الافتراضي معرّف في الإعدادات');

    /* (7) حالات الطلب ----------------------------------------------------- */
    Assert::group('دورة حياة الطلب');

    Assert::true(isset(ProjectConfig::ORDER_STATUSES['new']), 'الحالة الجديدة موجودة');
    Assert::true(in_array('in_progress', ProjectConfig::ORDER_STATUSES['new']['next'], true), 'جديد ← قيد التنفيذ');
    Assert::true(in_array('completed', ProjectConfig::ORDER_STATUSES['in_progress']['next'], true), 'قيد التنفيذ ← مكتمل');
    Assert::true(in_array('cancelled', ProjectConfig::ORDER_STATUSES['new']['next'], true), 'الإلغاء حالة مستقلة');
    Assert::same([], ProjectConfig::ORDER_STATUSES['completed']['next'], 'المكتمل حالة نهائية');
    Assert::same([], ProjectConfig::ORDER_STATUSES['cancelled']['next'], 'الملغى حالة نهائية');
};