<?php

declare(strict_types=1);

/**
 * اختبار حِمل — يولّد حجم بيانات واقعيًا ويقيس الأداء عند التشغيل الفعلي.
 *
 *   php tools/load-test.php [عدد الطلبات] [أيام]
 *
 * يعمل على قاعدة بيانات مؤقتة (عبر LC_CONFIG_FILE) فلا يمسّ بيانات نسختك.
 * الهدف: إثبات أن الفهارس والصياغة تكفي لحجم نشاط حقيقي،
 * لا عشرات الصفوف كما في بقية الاختبارات.
 *
 * مثال: php tools/load-test.php 50000 90
 */

if (PHP_SAPI !== 'cli') {
    exit("هذه الأداة تعمل من الطرفية فقط.\n");
}

// bootstrap.php يتوقع أن يكون BASE_PATH معرّفًا مسبقًا
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

$orderCount = (int) ($argv[1] ?? 20000);
$days       = (int) ($argv[2] ?? 90);

/* ---------------------- قاعدة بيانات مؤقتة ---------------------- */
$tempDir  = sys_get_temp_dir() . '/lc-load-' . getmypid();
$tempDb   = $tempDir . '/load.sqlite';
$tempConf = $tempDir . '/local.php';

if (!is_dir($tempDir)) {
    mkdir($tempDir, 0775, true);
}

$config = [
    'installed_at' => date('c'),
    'debug'        => false,
    'app_key'      => bin2hex(random_bytes(16)),
    'app'          => ['base_url' => 'http://localhost:9999'],
    'db'           => ['path' => $tempDb],
    'admin'        => [
        'username'      => 'load',
        'password_hash' => password_hash('load-test', PASSWORD_DEFAULT),
    ],
];

file_put_contents($tempConf, "<?php\nreturn " . var_export($config, true) . ";\n");
putenv('LC_CONFIG_FILE=' . $tempConf);

$cleanup = static function () use ($tempDir, $tempDb, $tempConf): void {
    @unlink($tempDb);
    @unlink($tempConf . '-wal');
    @unlink($tempConf . '-shm');
    @unlink($tempDb . '-wal');
    @unlink($tempDb . '-shm');
    @unlink($tempConf);
    @rmdir($tempDir);
};
register_shutdown_function($cleanup);

require BASE_PATH . '/src/bootstrap.php';

use App\Core\Database;
use App\Core\Migrations;
use App\Repositories\LocationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Repositories\CategoryRepository;
use App\Services\CatalogService;
use App\Services\OrderService;

$pdo = Database::pdo();
Migrations::migrate();

echo "══════════════════════════════════════════════════════════════\n";
echo "اختبار الحمل: {$orderCount} طلب على مدى {$days} يومًا\n";
echo "══════════════════════════════════════════════════════════════\n\n";

/* ------------------------ 1. البيانات الأساسية ------------------------ */

$categoryRepo = new CategoryRepository();
$productRepo  = new ProductRepository();
$locationRepo = new LocationRepository();

$categories = [];
foreach (['الساخنة', 'الباردة', 'الحلويات', 'الأكل', 'المشروبات'] as $i => $name) {
    $categories[] = $categoryRepo->create(['name' => $name, 'sort_order' => $i * 10, 'is_active' => 1]);
}

$products = [];
for ($i = 1; $i <= 40; $i++) {
    $id = $productRepo->create([
        'name'         => sprintf('منتج رقم %d', $i),
        'price_minor'  => 5000 + ($i * 750),
        'category_id'  => $categories[$i % count($categories)],
        'is_available' => 1,
        'sort_order'   => $i,
        'description'  => 'منتج مولّد لاختبار الحمل',
    ]);
    // نحتاج السعر أيضًا عند إنشاء عناصر الطلبات
    $products[] = ['id' => (int) $id, 'name' => sprintf('منتج رقم %d', $i), 'price_minor' => 5000 + ($i * 750)];
}

$locations = [];
for ($i = 1; $i <= 25; $i++) {
    $locations[] = $locationRepo->save(null, [
        'name'       => 'طاولة ' . $i,
        'code'       => 'T' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
        'is_active'  => 1,
    ]);
}

$locationId = $locations[0];
$locationCode = 'T001';

echo "تم إنشاء: " . count($products) . " منتجًا، "
    . count($locations) . " موقعًا\n";

/* -------------------------- 2. توليد الطلبات -------------------------- */

$statuses = ['completed' => 70, 'cancelled' => 12, 'new' => 10, 'in_progress' => 8];
$statusPool = [];
foreach ($statuses as $status => $weight) {
    for ($i = 0; $i < $weight; $i++) {
        $statusPool[] = $status;
    }
}

$types = ['dine_in', 'dine_in', 'dine_in', 'takeaway', 'delivery'];

$start = microtime(true);
$pdo->beginTransaction();

$insertOrder = $pdo->prepare(
    'INSERT INTO orders (served_on, number, customer_name, customer_phone, order_type, location_id,'
    . ' status, items_count, total_minor, notes, fields_json, cancel_reason,'
    . ' idempotency_key, created_at, updated_at, public_token)'
    . ' VALUES (:served_on, :number, :customer_name, :customer_phone, :order_type, :location_id,'
    . ' :status, :items_count, :total_minor, :notes, :fields_json, :cancel_reason,'
    . ' :idempotency_key, :created_at, :updated_at, :public_token)'
);

$insertItem = $pdo->prepare(
    'INSERT INTO order_items (order_id, product_id, name, unit_price_minor, quantity, line_total_minor, created_at)'
    . ' VALUES (:order_id, :product_id, :name, :unit_price_minor, :quantity, :line_total_minor, :created_at)'
);

$insertEvent = $pdo->prepare(
    'INSERT INTO order_events (order_id, from_status, to_status, actor, note, created_at)'
    . ' VALUES (:order_id, :from_status, :to_status, :actor, :note, :created_at)'
);

$dayCounters = [];
$firstName = ['سامي', 'ليلى', 'أحمد', 'فاطمة', 'خالد', 'نور', 'علي', 'مريم', 'حسن', 'رنا'];
$today = date('Y-m-d');

for ($n = 1; $n <= $orderCount; $n++) {
    // توزيع الطلبات على الأيام مع ذروة مسائية
    $dayOffset = (int) floor($orderCount === 0 ? 0 : ($n * $days) / max(1, $orderCount));
    $dayOffset = min($days, $dayOffset);
    $servedOn  = date('Y-m-d', strtotime("-" . $dayOffset . " days"));

    $dayCounters[$servedOn] = ($dayCounters[$servedOn] ?? 0) + 1;
    $number = $dayCounters[$servedOn];

    // ساعة الذروة: 60% من الطلبات بين 19:00 و 23:00
    $hour = random_int(0, 23);
    if (random_int(1, 100) <= 60) {
        $hour = random_int(19, 23);
    }
    $createdAt = $servedOn . ' ' . sprintf('%02d:%02d:%02d', $hour, random_int(0, 59), random_int(0, 59));

    $status = $statusPool[array_rand($statusPool)];
    $type   = $types[array_rand($types)];

    // نحسب عناصر الطلب أولًا لنُدخل الطلب مرة واحدة فقط
    $lineCount = random_int(1, 4);
    $lines     = [];
    $itemsCount = 0;
    $total = 0;

    for ($k = 0; $k < $lineCount; $k++) {
        $product  = $products[array_rand($products)];
        $quantity = random_int(1, 3);
        $itemsCount += $quantity;
        $total += $product['price_minor'] * $quantity;
        $lines[] = ['product' => $product, 'quantity' => $quantity];
    }

    $name = $firstName[array_rand($firstName)] . ' ' . random_int(1, 99);

    $insertOrder->execute([
        'served_on'       => $servedOn,
        'number'          => $number,
        'customer_name'   => $name,
        'customer_phone'  => random_int(0, 3) === 0 ? '09' . random_int(1000000, 9999999) : '',
        'order_type'      => $type,
        'location_id'     => $locationId,
        'status'          => $status,
        'items_count'     => $itemsCount,
        'total_minor'     => $total,
        'notes'           => random_int(0, 4) === 0 ? 'بدون بصل' : '',
        'fields_json'     => '{}',
        'cancel_reason'   => $status === 'cancelled' ? 'طلب الزبون' : '',
        'idempotency_key' => 'load-' . $servedOn . '-' . $number,
        'created_at'      => $createdAt,
        'updated_at'      => $createdAt,
        'public_token'    => bin2hex(random_bytes(16)),
    ]);
    $orderId = (int) $pdo->lastInsertId();

    foreach ($lines as $line) {
        $product  = $line['product'];
        $quantity = $line['quantity'];

        $insertItem->execute([
            'order_id'         => $orderId,
            'product_id'       => $product['id'],
            'name'             => $product['name'],
            'unit_price_minor' => $product['price_minor'],
            'quantity'         => $quantity,
            'line_total_minor' => $product['price_minor'] * $quantity,
            'created_at'       => $createdAt,
        ]);
    }

    $insertEvent->execute([
        'order_id'    => $orderId,
        'from_status' => '',
        'to_status'   => 'new',
        'actor'       => 'system',
        'note'        => '',
        'created_at'  => $createdAt,
    ]);
}

$pdo->exec(
    'INSERT OR REPLACE INTO daily_counters (day, value) SELECT served_on, COUNT(*) FROM orders GROUP BY served_on'
);

$pdo->commit();

$genSeconds = microtime(true) - $start;
$realItems  = (int) Database::value('SELECT COUNT(*) FROM order_items');

echo "تم توليد " . Database::value('SELECT COUNT(*) FROM orders') . " طلبًا و {$realItems} عنصرًا"
    . sprintf(" في %.1f ثانية\n\n", $genSeconds);

/* --------------------------- 3. القياسات --------------------------- */

$pdo->exec('ANALYZE');

$results = [];

function measure(string $label, callable $fn, float $budgetMs, float $repeat = 5): void
{
    global $results;

    // إحماء
    $fn();

    $samples = [];
    for ($i = 0; $i < (int) $repeat; $i++) {
        $start = microtime(true);
        $fn();
        $samples[] = (microtime(true) - $start) * 1000;
    }

    sort($samples);
    $median = $samples[(int) floor(count($samples) / 2)];
    $max    = max($samples);
    $ok     = $median <= $budgetMs;

    $results[] = ['label' => $label, 'median' => $median, 'max' => $max, 'budget' => $budgetMs, 'ok' => $ok];
}

echo "قياسات الأداء (الوسيط من 5 تكرارات)\n";
echo str_repeat('─', 74) . "\n";

$catalog = new CatalogService();
measure('كتالوج القائمة (يقرأه الزبون كل زيارة)', fn() => $catalog->snapshot(), 150);

$orderRepo = new OrderRepository();
measure('قائمة طلبات جديدة (اللوحة الافتراضية)', fn() => $orderRepo->listForAdmin(['status' => 'new'], 50), 200);
measure('قائمة كل الطلبات (بدون تصفية)', fn() => $orderRepo->listForAdmin([], 50), 300);
measure('قائمة طلبات اليوم', fn() => $orderRepo->listForAdmin(['date' => $today], 50), 250);
measure('بحث في الطلبات بجزء من الاسم', fn() => $orderRepo->listForAdmin(['q' => 'سامي'], 50), 300);

$sampleId = (int) Database::value('SELECT id FROM orders ORDER BY id DESC LIMIT 1');
measure('تفاصيل طلب واحد', fn() => $orderRepo->find($sampleId), 100);

$sampleToken = (string) Database::value('SELECT public_token FROM orders WHERE id = :id', ['id' => $sampleId]);
measure('متابعة الطلب برمزه (واجهة الزبون)', fn() => $orderRepo->findByToken($sampleToken), 100);

$service = new OrderService();
$productForOrder = $products[0];
$locationForOrder = $locationId;

measure('إنشاء طلب جديد (كتابة كاملة)', function () use ($service, $productForOrder, $locationForOrder) {
    $service->create([
        'order_type'    => 'dine_in',
        'customer_name' => 'زبون القياس',
        'location_id'   => $locationForOrder,
        'items'         => [['product_id' => $productForOrder['id'], 'quantity' => 2]],
    ]);
}, 400, 3);

echo str_repeat('─', 74) . "\n";
$failed = 0;
foreach ($results as $row) {
    printf(
        "%-46s %7.1fms (أقصوى %6.1f)  ميزانية %4dms  %s\n",
        $row['label'],
        $row['median'],
        $row['max'],
        (int) $row['budget'],
        $row['ok'] ? '✔' : '✗ تجاوز الميزانية'
    );
    if (!$row['ok']) {
        $failed++;
    }
}

echo str_repeat('─', 74) . "\n";

/* ------------------------ 4. slowest queries ------------------------ */

echo "\nزمن تنفيذ الاستعلامات النموذجية بعد التوليد:\n";
$checks = [
    'قائمة الطلبات (تصفية بالحالة)' => 'SELECT * FROM orders WHERE status = ? ORDER BY created_at DESC LIMIT 50',
    'قائمة الطلبات (غير مصفّاة)'    => 'SELECT * FROM orders ORDER BY created_at DESC LIMIT 50',
    'عدّ الطلبات بالحالة'          => 'SELECT COUNT(*) FROM orders WHERE status = ?',
    'إحصاءات اليوم'                => "SELECT COUNT(*) FROM orders WHERE served_on = ?",
    'بحث بالاسم'                   => 'SELECT * FROM orders WHERE customer_name LIKE ? LIMIT 50',
    'كتالوج المنتجات المتاحة'      => 'SELECT * FROM products WHERE is_available = 1 ORDER BY sort_order',
];
echo str_repeat('─', 74) . "\n";
foreach ($checks as $label => $sql) {
    $params = str_contains($sql, '?') && str_contains($label, 'الاسم') ? ['%سامي%'] : ['new'];
    if (!str_contains($sql, '?')) {
        $params = [];
    } elseif (str_contains($label, 'اليوم')) {
        $params = [$today];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $start = microtime(true);
    $stmt->execute($params);
    $ms = (microtime(true) - $start) * 1000;
    printf("%-46s %7.1fms\n", $label, $ms);
    if ($ms > 100) {
        $failed++;
    }
}

echo str_repeat('─', 74) . "\n";
$dbSize = filesize($tempDb) / 1024 / 1024;
printf("حجم قاعدة البيانات: %.1f ميجابايت\n", $dbSize);
printf("عدد الطلبات: %s — عدد العناصر: %s\n",
    Database::value('SELECT COUNT(*) FROM orders'),
    Database::value('SELECT COUNT(*) FROM order_items'));

echo "\n" . ($failed === 0
    ? "\033[32m\033[1m  كل القياسات ضمن الميزانية ✔\033[0m\n"
    : "\033[31m\033[1m  {$failed} قياس تجاوز الميزانية\033[0m\n");

exit($failed === 0 ? 0 : 1);