<?php

declare(strict_types=1);

/**
 * الطلبات: القواعد الملزمة في readme §6 و §7
 */

use App\Config\ProjectConfig;
use App\Core\Database;
use App\Core\Http\HttpError;
use App\Services\OrderService;

return function (): void {
    global $coffee, $tea, $juice, $loc1, $loc2;

    Assert::group('الطلبات — القواعد الملزمة');

    $service = new OrderService();

    $basePayload = static fn(array $overrides = []): array => array_merge([
        'order_type'    => 'dine_in',
        'customer_name' => 'زبون اختبار',
        'location_code' => 'T01',
        'items'         => [['product_id' => $coffee, 'quantity' => 1]],
    ], $overrides);

    /* (1) إرسال ناجح ينشئ طلبًا مستقلًا --------------------------------- */
    $order1 = $service->create($basePayload());
    Assert::true(($order1['id'] ?? 0) > 0, 'إنشاء طلب ناجح يعيد رقم طلب');
    Assert::greater($order1['number'], 0, 'الطلب يحصل على رقم ظاهر');
    Assert::same('new', $order1['status'], 'الحالة الابتدائية جديد');

    /* (2) عدة طلبات على الموقع نفسه لا تُدمج ---------------------------- */
    $order2 = $service->create($basePayload());
    Assert::notSame($order1['id'], $order2['id'], 'طلبان على الموقع نفسه = سجلان مستقلان');
    Assert::notSame($order1['number'], $order2['number'], 'لكل طلب رقم ظاهر مختلف');
    Assert::same(2, (int) Database::value('SELECT COUNT(*) FROM orders WHERE location_id = :loc', ['loc' => $loc1]),
        'كلا الطلبين محفوظان بنفس الموقع');

    /* (3) رفض الطلب بدون موقع عندما يكون الموقع إلزاميًا ------------------ */
    Assert::throws(
        fn() => $service->create($basePayload(['location_code' => ''])),
        HttpError::class,
        'الطلب بدون موقع مرفوض في نوع طلب يفرض الموقع'
    );

    /* (4) طلب بدون موقع مسموح في نوع الطلب الذي لا يفرضه ------------------ */
    $takeaway = $service->create($basePayload([
        'order_type'    => 'takeaway',
        'location_code' => '',
    ]));
    Assert::same('takeaway', $takeaway['order_type'], 'نوع الطلب الذي لا يفرض الموقع يعمل بلا موقع');
    Assert::same('', $takeaway['location'], 'لا يُخزَّن موقع فارغ');

    /* (5) موقع غير موجود أو غير نشط يُرفض -------------------------------- */
    Assert::throws(
        fn() => $service->create($basePayload(['location_code' => 'ZZZZ'])),
        HttpError::class,
        'موقع غير موجود مرفوض على الخادم'
    );

    Database::update('locations', ['is_active' => 0], 'id = :id', ['id' => $loc2]);
    Assert::throws(
        fn() => $service->create($basePayload(['location_code' => 'T02'])),
        HttpError::class,
        'موقع معطّل مرفوض على الخادم'
    );
    Database::update('locations', ['is_active' => 1], 'id = :id', ['id' => $loc2]);

    /* (6) الأسعار تُحسب على الخادم ولا تُقبل من الزبون -------------------- */
    $tampered = $service->create($basePayload([
        'items' => [['product_id' => $coffee, 'quantity' => 2, 'price_minor' => 1, 'price' => '1']],
    ]));
    Assert::same(20000, $tampered['total_minor'], 'السعر المحسوب من قاعدة البيانات (10000 × 2)');

    /* (7) حفظ لقطة السعر والاسم وقت الطلب -------------------------------- */
    $priceBefore = (int) Database::value('SELECT price_minor FROM products WHERE id = :id', ['id' => $coffee]);
    $nameBefore  = (string) Database::value('SELECT name FROM products WHERE id = :id', ['id' => $coffee]);

    // نغيّر المنتج بعد إنشاء الطلب الأول
    Database::update('products', [
        'name' => $nameBefore . '-special', 'price_minor' => 99999,
    ], 'id = :id', ['id' => $coffee]);

    // الطلب الجديد يأخذ السعر الجديد وقت الطلب
    $snapshotOrder = $service->create($basePayload());
    $item = $snapshotOrder['items'][0];
    Assert::same($nameBefore . '-special', $item['name'], 'اسم المنتج يُحفظ كما هو وقت إنشاء الطلب');
    Assert::same(99999, $item['unit_price_minor'], 'سعر المنتج الجديد يُحفظ في الطلب الجديد');

    // الطلب القديم لم يتأثر بتغيير السعر أو الاسم
    $oldItem = Database::one(
        'SELECT name, unit_price_minor FROM order_items WHERE order_id = :id LIMIT 1',
        ['id' => $order1['id']]
    );
    Assert::same($nameBefore, $oldItem['name'], 'الطلب القديم يحتفظ بالاسم الأصلي');
    Assert::same($priceBefore, (int) $oldItem['unit_price_minor'], 'الطلب القديم يحتفظ بالسعر الأصلي');

    $oldOrder = Database::one('SELECT total_minor FROM orders WHERE id = :id', ['id' => $order1['id']]);
    Assert::same($priceBefore, (int) $oldOrder['total_minor'], 'إجمالي الطلب القديم لم يتغيّر');

    // استعادة القيم
    Database::update('products', [
        'name' => $nameBefore, 'price_minor' => $priceBefore,
    ], 'id = :id', ['id' => $coffee]);

    /* (8) منع التكرار عبر idempotency ----------------------------------- */
    $key  = 'test-key-' . bin2hex(random_bytes(4));
    $first  = $service->create($basePayload(['idempotency_key' => $key]));
    $second = $service->create($basePayload(['idempotency_key' => $key]));
    $third  = $service->create($basePayload(['idempotency_key' => $key]));

    Assert::same($first['id'], $second['id'], 'إعادة الإرسال بالمفتاح نفسه لا تنشئ طلبًا جديدًا');
    Assert::same($first['id'], $third['id'], 'التكرار الثالث يعود لنفس الطلب');
    Assert::true((bool) $second['idempotent_replay'], 'الاستجابة تُعلَّم كإعادة إرسال');

    $countWithKey = (int) Database::value(
        'SELECT COUNT(*) FROM orders WHERE idempotency_key = :k', ['k' => $key]
    );
    Assert::same(1, $countWithKey, 'قاعدة البيانات تحتوي طلبًا واحدًا فقط بهذا المفتاح');

    /* (9) منتج لم يعد متاحًا — يُرفض الطلب -------------------------------- */
    Database::update('products', ['is_available' => 0], 'id = :id', ['id' => $juice]);
    Assert::throws(
        fn() => $service->create($basePayload(['items' => [['product_id' => $juice, 'quantity' => 1]]])),
        HttpError::class,
        'منتج غير متاح يُرفض من الخادم'
    );
    Database::update('products', ['is_available' => 1], 'id = :id', ['id' => $juice]);

    /* (10) تحقّق من المدخلات -------------------------------------------- */
    Assert::throws(
        fn() => $service->create($basePayload(['customer_name' => ''])),
        HttpError::class,
        'الاسم مطلوب عند إتمام الطلب'
    );
    Assert::throws(
        fn() => $service->create($basePayload(['order_type' => 'غير_موجود'])),
        HttpError::class,
        'نوع طلب غير معروف مرفوض'
    );
    Assert::throws(
        fn() => $service->create($basePayload(['items' => []])),
        HttpError::class,
        'سلة فارغة مرفوضة'
    );
    Assert::throws(
        fn() => $service->create($basePayload(['items' => [['product_id' => 999999, 'quantity' => 1]]])),
        HttpError::class,
        'منتج غير موجود مرفوض'
    );
    Assert::throws(
        fn() => $service->create($basePayload(['items' => [['product_id' => $coffee, 'quantity' => 0]]])),
        HttpError::class,
        'كمية صفر مرفوضة'
    );
    Assert::throws(
        fn() => $service->create($basePayload([
            'items' => [['product_id' => $coffee, 'quantity' => ProjectConfig::MAX_QTY_PER_ITEM + 1]],
        ])),
        HttpError::class,
        'كمية أكبر من الحد مرفوضة'
    );

    /* (11) تنظيف النصوص ------------------------------------------------- */
    $cleanOrder = $service->create($basePayload(['customer_name' => '<script>alert(1)</script>سامي']));
    Assert::notContains($cleanOrder['customer_name'], '<script>', 'الوسوم تُزال من اسم الزبون');

    /* (12) الحقول الإضافية تُحفظ في الكود لا في جدول جديد ---------------- */
    $withFields = $service->create($basePayload([
        'order_type' => 'dine_in',
        'fields'     => ['notes' => 'بدون بصل', 'served_for' => '4'],
    ]));
    Assert::same('4', (string) ($withFields['fields']['served_for'] ?? ''), 'حقل عدد الأشخاص محفوظ');
    Assert::contains($withFields['notes'], 'بدون بصل', 'ملاحظات المطبخ محفوظة');

    Assert::throws(
        fn() => $service->create($basePayload([
            'order_type' => 'dine_in',
            'fields'     => ['served_for' => 'غير رقم'],
        ])),
        HttpError::class,
        'حقل رقمي بقيمة غير رقمية مرفوض'
    );

    /* (13) رمز المتابعة عشوائي ولا يكشف بيانات داخلية ------------------- */
    Assert::same(32, strlen($order1['tracking_token']), 'رمز المتابعة بطول 32 محرفًا');
    Assert::true((bool) preg_match('/^[a-f0-9]{32}$/', $order1['tracking_token']), 'رمز المتابعة سداسي عشوائي');

    /* (14) تغيير الحالة بقواعد الانتقالات -------------------------------- */
    $orderService = new OrderService();
    $inProgress = $orderService->changeStatus($order1['id'], 'in_progress', 'admin:test');
    Assert::same('in_progress', $inProgress['status'], 'الانتقال من جديد إلى قيد التنفيذ مسموح');

    Assert::throws(
        fn() => $orderService->changeStatus($order1['id'], 'new', 'admin:test'),
        HttpError::class,
        'الرجوع من قيد التنفيذ إلى جديد غير مسموح'
    );

    $cancelled = $orderService->changeStatus($order1['id'], 'cancelled', 'admin:test', 'طلب الزبون');
    Assert::same('cancelled', $cancelled['status'], 'الإلغاء مسموح');
    Assert::same('طلب الزبون', $cancelled['cancel_reason'], 'سبب الإلغاء محفوظ');
    Assert::same('new', $order2['status'], 'إلغاء طلب لا يؤثر على طلب آخر بنفس الموقع');

    /* (15) سجل تغييرات الحالة ------------------------------------------- */
    $events = Database::all('SELECT to_status FROM order_events WHERE order_id = :id ORDER BY id', ['id' => $order1['id']]);
    Assert::greater(count($events), 1, 'كل تغيير حالة مسجَّل في order_events');

    /* (16) لا تأكيد نجاح قبل الحفظ -------------------------------------- */
    $before = (int) Database::value('SELECT COUNT(*) FROM orders');
    try {
        $service->create($basePayload(['items' => [['product_id' => 424242, 'quantity' => 1]]]));
    } catch (Throwable) {
        // متوقّع
    }
    $after = (int) Database::value('SELECT COUNT(*) FROM orders');
    Assert::same($before, $after, 'الطلب الفاشل لا يترك أي سجل جزئي');
    Assert::same(0, (int) Database::value(
        'SELECT COUNT(*) FROM order_items oi LEFT JOIN orders o ON o.id = oi.order_id WHERE o.id IS NULL'
    ), 'لا توجد عناصر يتيمة بلا طلب');
};