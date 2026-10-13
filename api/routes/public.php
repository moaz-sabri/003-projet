<?php

declare(strict_types=1);

/**
 * المسارات العامة (واجهة الزبون).
 * لا تتطلب أي مصادقة، لكن جميع العمليات الحسّاسة (إنشاء الطلب) محدودة ومتحقَّق منها.
 */

use App\Config\ProjectConfig;
use App\Core\Http\HttpError;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Validator;
use App\Repositories\OrderRepository;
use App\Services\CatalogService;
use App\Services\OrderService;

/** قراءة جسم الطلب: JSON أو form-encoded. */
$readBody = static function (Request $request): array {
    $body = $request->body();
    if ($body === []) {
        $json = $request->json();
        if ($json !== []) {
            return $json;
        }
    }
    return $body;
};

/**
 * الحمولة الكاملة: إعدادات + تصنيفات + منتجات + مواقع.
 * تخبّئتها Service Worker ليعمل التصفح دون اتصال.
 */
$router->get('catalog.snapshot', function (Request $request) use ($readBody): void {
    $service = new CatalogService();
    $search  = (string) ($request->get('q') ?? '');
    $payload = $service->snapshot(mb_substr(trim($search), 0, 60) ?: null);

    Response::json([
        'ok'   => true,
        'data' => $payload,
        'meta' => ['generated_at' => date('c')],
    ], 200, [
        // نسمح بالتخزين المؤقت مع إعادة التحقق (readme §11)
        'Cache-Control' => 'public, max-age=60, stale-while-revalidate=86400',
    ]);
});

/** تفاصيل منتج (تُستخدم فقط للمنتجات التي لها تفاصيل تستحق العرض). */
$router->get('product.get', function (Request $request): void {
    $id      = (int) ($request->get('id') ?? 0);
    $service = new CatalogService();
    $product = $id > 0 ? $service->productDetails($id) : null;

    if ($product === null) {
        throw new HttpError('not_found', 'المنتج غير موجود أو لم يعد متاحًا.', 404);
    }
    Response::json(['ok' => true, 'data' => $product], 200, ['Cache-Control' => 'public, max-age=300']);
});

/** المواقع النشطة (للاختيار اليدوي عند القدوم بدون QR). */
$router->get('locations.list', function (): void {
    if (!ProjectConfig::feature('locations')) {
        Response::ok(['locations' => []]);
    }
    $repo = new \App\Repositories\LocationRepository();
    Response::json([
        'ok'   => true,
        'data' => ['locations' => $repo->all(true)],
    ], 200, ['Cache-Control' => 'public, max-age=120']);
});

/**
 * إنشاء طلب — لا يُعدّ ناجحًا إلا بعد الحفظ الفعلي على السيرفر.
 * يدعم idempotency عبر الترويسة Idempotency-Key أو الحقل idempotency_key.
 */
$router->post('order.create', function (Request $request) use ($readBody): void {
    $payload = array_merge($readBody($request), [
        'idempotency_key' => $request->header('Idempotency-Key') ?? ($request->input('idempotency_key') ?? ''),
    ]);

    $service = new OrderService();
    $order   = $service->create($payload, $request);

    $baseUrl = app_config('app.base_url', '');
    $trackUrl = ProjectConfig::feature('order_tracking') && $baseUrl !== ''
        ? rtrim($baseUrl, '/') . '/app/index.html?t=' . $order['tracking_token']
        : null;

    Response::json([
        'ok'   => true,
        'data' => [
            'number'     => $order['number'],
            'total'      => $order['total'],
            'status'     => $order['status'],
            'status_label' => $order['status_label'],
            'location'   => $order['location'],
            'items'      => $order['items'],
            'created_at' => $order['created_at'],
            'tracking_token' => $order['tracking_token'],
            'tracking_url'   => $trackUrl,
            'replayed'       => (bool) ($order['idempotent_replay'] ?? false),
        ],
    ], 201, ['Cache-Control' => 'no-store']);
});

/** متابعة حالة الطلب برمزه — قراءة فقط، بلا أي صلاحية تعديل أو إلغاء. */
$router->get('order.status', function (Request $request): void {
    $token = (string) ($request->get('token') ?? '');
    $repo  = new OrderRepository();
    $order = $repo->findByToken($token);

    if ($order === null) {
        throw new HttpError('not_found', 'الطلب غير موجود أو انتهت صلاحية الرمز.', 404);
    }

    $full = $repo->find($order['id']);
    Response::json([
        'ok'   => true,
        'data' => [
            'number'       => $full['number'],
            'status'       => $full['status'],
            'status_label' => $full['status_label'],
            'status_color' => $full['status_color'],
            'location'     => $full['location'],
            'order_type'   => $full['order_type_label'],
            'customer'     => $full['customer_name'],
            'total'        => $full['total'],
            'items'        => array_map(
                static fn(array $i): array => [
                    'name'       => $i['name'],
                    'quantity'   => $i['quantity'],
                    'unit_price' => $i['unit_price'],
                    'line_total' => $i['line_total'],
                ],
                $full['items']
            ),
            'notes'       => $full['notes'],
            'created_at'  => $full['created_at'],
            'time_label'  => $full['time_label'],
        ],
    ], 200, ['Cache-Control' => 'no-store']);
});

/** التوفر — تستخدمه الواجهة لعرض حالة الاتصال دون إزعاج المستخدم. */
$router->get('ping', function (): void {
    Response::json(['ok' => true, 'data' => ['time' => date('c'), 'server' => 'php']], 200, ['Cache-Control' => 'no-store']);
});