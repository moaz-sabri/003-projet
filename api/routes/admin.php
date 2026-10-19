<?php

declare(strict_types=1);

/**
 * مسارات لوحة الإدارة — كلها تتطلب جلسة صالحة، وكل عملية تعديل تتطلب رمز CSRF.
 */

use App\Config\ProjectConfig;
use App\Core\Http\HttpError;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Validator;
use App\Core\CompanyProfile;
use App\Core\Money;
use App\Core\RuntimeSettings;
use App\Core\Security\Auth;
use App\Core\Security\Csrf;
use App\Core\Security\ImageUploader;
use App\Core\Security\RateLimiter;
use App\Repositories\CategoryRepository;
use App\Repositories\LocationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Services\BackupService;
use App\Services\CatalogService;
use App\Services\InstanceService;
use App\Services\OrderService;
use App\Services\QrService;

$body = static function (Request $request): array {
    $data = $request->body();
    return $data === [] ? $request->json() : $data;
};

$requireAuth = static function (Request $request): void {
    if (!Auth::check()) {
        throw new HttpError('unauthenticated', 'الجلسة منتهية. سجّل الدخول مجددًا.', 401);
    }
};

$requireAdmin = static function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    Csrf::verify($request);
};

/* ============================ المصادقة ============================ */

$router->post('admin.login', function (Request $request) use ($body): void {
    $data = $body($request);

    if (RateLimiter::tooManyLoginAttempts($request)) {
        throw new HttpError('locked', 'تم إيقاف المحاولات مؤقتًا. حاول بعد ' . ProjectConfig::ADMIN_LOCKOUT_MINUTES . ' دقيقة.', 429);
    }

    $username = mb_substr(trim((string) ($data['username'] ?? '')), 0, 60);
    $password = (string) ($data['password'] ?? '');

    if ($username === '' || $password === '') {
        throw new HttpError('missing_credentials', 'أدخل اسم المستخدم وكلمة المرور.', 422);
    }

    if (!Auth::checkCredentials($username, $password)) {
        RateLimiter::recordLogin($request, $username, false);
        \App\Core\Logger::warning('Failed admin login', ['username' => $username, 'ip' => $request->ip()]);
        throw new HttpError('invalid_credentials', 'بيانات الدخول غير صحيحة.', 401);
    }

    Auth::login($username);
    RateLimiter::recordLogin($request, $username, true);
    RateLimiter::clearLoginAttempts($request);

    Response::json([
        'ok'   => true,
        'data' => [
            'username'  => Auth::username(),
            'csrf'      => Auth::token(),
            'locations' => ProjectConfig::locationPlural(),
        ],
    ], 200, ['Cache-Control' => 'no-store']);
});

$router->post('admin.logout', function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    Auth::logout();
    Response::json(['ok' => true, 'data' => ['message' => 'تم تسجيل الخروج.']], 200, ['Cache-Control' => 'no-store']);
});

$router->get('admin.session', function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    Response::json([
        'ok'   => true,
        'data' => ['username' => Auth::username(), 'csrf' => Auth::token()],
    ], 200, ['Cache-Control' => 'no-store']);
});

/** تغيير كلمة مرور المدير (يتطلب كلمة المرور الحالية). */
$router->post('admin.password', function (Request $request) use ($body, $requireAdmin): void {
    $requireAdmin($request);
    $data    = $body($request);
    $current = (string) ($data['current_password'] ?? '');
    $new     = (string) ($data['new_password'] ?? '');

    if (!Auth::checkCredentials(Auth::username(), $current)) {
        throw new HttpError('invalid_credentials', 'كلمة المرور الحالية غير صحيحة.', 401);
    }
    if (mb_strlen($new) < 8) {
        throw new HttpError('weak_password', 'كلمة المرور الجديدة يجب ألا تقل عن 8 محارف.', 422);
    }
    if (mb_strlen($new) > 200) {
        throw new HttpError('weak_password', 'كلمة المرور طويلة جدًا.', 422);
    }

    Auth::changePassword($new);
    \App\Core\Logger::info('Admin password changed', ['ip' => $request->ip()]);
    Response::ok(['message' => 'تم تغيير كلمة المرور.']);
});

/* ============================ لوحة الإدارة ============================ */

/** ملخص الرئيسية: إحصاءات + أحدث الطلبات. */
$router->get('admin.dashboard', function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    $orders = new OrderRepository();
    [$latest] = array_values($orders->listForAdmin([], 8));

    Response::json([
        'ok'   => true,
        'data' => [
            'stats'  => $orders->stats(),
            'latest' => $latest,
            'time'   => date('H:i'),
        ],
    ], 200, ['Cache-Control' => 'no-store']);
});

/** الطلبات النشطة للتحديث الدوري (بدون تخزين مؤقت). */
$router->get('admin.orders.active', function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    $orders = new OrderRepository();
    Response::json([
        'ok'   => true,
        'data' => [
            'orders' => $orders->activeSince(date('Y-m-d H:i:s')),
            'stats'  => $orders->stats(),
            'time'   => date('H:i'),
        ],
    ], 200, ['Cache-Control' => 'no-store']);
});

/* ---------------------------- التصنيفات ---------------------------- */

$router->get('admin.categories', function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    $repo = new CategoryRepository();
    Response::ok(['categories' => $repo->all()]);
});

$validateCategory = static function (array $data): array {
    $v = Validator::make($data);
    $v->required('name', 'اسم التصنيف')
      ->string('name', 'اسم التصنيف', 1, 80)
      ->string('description', 'الوصف', 0, 300)
      ->integer('sort_order', 'الترتيب', 0, 100000)
      ->boolean('is_active');

    // throwIfFails يرمي استثناءً عند وجود أخطاء، ويعيد المصفوفة المُنقّاة
    return $v->throwIfFails();
};

$router->post('admin.category.create', function (Request $request) use ($body, $requireAdmin, $validateCategory): void {
    $requireAdmin($request);
    $data = $validateCategory($body($request));
    $id   = (new CategoryRepository())->create($data);
    Response::ok(['id' => $id, 'categories' => (new CategoryRepository())->all()], 201);
});

$router->post('admin.category.update', function (Request $request) use ($body, $requireAdmin, $validateCategory): void {
    $requireAdmin($request);
    $data = $validateCategory($body($request));
    $id   = (int) ($data['id'] ?? 0);
    if ($id <= 0 || (new CategoryRepository())->find($id) === null) {
        throw new HttpError('not_found', 'التصنيف غير موجود.', 404);
    }
    (new CategoryRepository())->update($id, $data);
    Response::ok(['categories' => (new CategoryRepository())->all()]);
});

$router->post('admin.category.delete', function (Request $request) use ($body, $requireAdmin): void {
    $requireAdmin($request);
    $data = $body($request);
    $id   = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        throw new HttpError('not_found', 'التصنيف غير موجود.', 404);
    }
    $repo = new CategoryRepository();
    $repo->delete($id);
    Response::ok(['categories' => $repo->all(), 'products' => (new ProductRepository())->allAdmin()]);
});

$router->post('admin.categories.reorder', function (Request $request) use ($body, $requireAdmin): void {
    $requireAdmin($request);
    $ids = (array) ($body($request)['ids'] ?? []);
    if ($ids === []) {
        throw new HttpError('invalid_input', 'قائمة الترتيب فارغة.', 422);
    }
    (new CategoryRepository())->reorder(array_map('intval', $ids));
    Response::ok(['categories' => (new CategoryRepository())->all()]);
});

/* ---------------------------- المنتجات ---------------------------- */

$router->get('admin.products', function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    Response::ok([
        'products'      => (new ProductRepository())->allAdmin(),
        'categories'    => (new CategoryRepository())->all(),
        'fields'        => ProjectConfig::productExtraFields(),
        'detail_fields' => ProjectConfig::productDetailFields(),
        'labels'        => [
            'singular'  => ProjectConfig::productSingular(),
            'plural'    => ProjectConfig::productPlural(),
            'catalog'   => ProjectConfig::catalogTitle(),
            'max_items' => ProjectConfig::MAX_CATALOG_ITEMS,
        ],
    ]);
});

$validateProduct = static function (array $data): array {
    $v = Validator::make($data);
    $v->required('name', 'اسم المنتج')
      ->string('name', 'اسم المنتج', 1, 120)
      ->string('description', 'الوصف', 0, 3000)
      ->integer('category_id', 'التصنيف', 0, PHP_INT_MAX)
      ->integer('sort_order', 'الترتيب', 0, 100000)
      ->boolean('is_available')
      ->boolean('is_featured');

    $out = $v->throwIfFails();
    $rawPrice    = $out['price'] ?? '0';
    $out['price_minor'] = Money::parseToMinor(is_scalar($rawPrice) ? (string) $rawPrice : '0');
    if ($out['price_minor'] < 0) {
        $out['price_minor'] = 0;
    }
    if ($out['price_minor'] > 999999999999) {
        throw new HttpError('invalid_price', 'السعر كبير جدًا.', 422);
    }

    // الحقول الإضافية: نقبلها ونخزّنها فقط وفق تعريف ProjectConfig
    $extra = [];
    $extraInput = $data['extra'] ?? [];
    if (is_array($extraInput)) {
        foreach (ProjectConfig::productExtraFields() as $field) {
            $key = $field['key'];
            $val = $extraInput[$key] ?? null;
            if ($field['type'] === 'boolean') {
                $extra[$key] = Validator::toBool($val) ? 1 : 0;
            } elseif ($field['type'] === 'number') {
                if ($val === null || $val === '') {
                    $extra[$key] = '';
                } elseif (is_numeric($val)) {
                    $extra[$key] = (int) $val;
                } else {
                    throw new HttpError('invalid_field', $field['label'] . ' يجب أن يكون رقمًا.', 422);
                }
            } else {
                $val = is_scalar($val) ? mb_substr(trim((string) $val), 0, 400) : '';
                if ($val !== '' && !empty($field['required'])) {
                    // مطلوب فقط عند التعبئة؛ لا نرفض الحذف
                }
                $extra[$key] = $val;
            }
        }
    }
    // حقول التفاصيل تُحفظ ضمن نفس البنية
    if (isset($data['details']) && is_array($data['details'])) {
        foreach (ProjectConfig::productDetailFields() as $field) {
            $key = $field['key'];
            $val = (string) ($data['details'][$key] ?? '');
            $extra[$key] = mb_substr(trim($val), 0, 1200);
        }
    }
    $out['extra'] = $extra;
    return $out;
};

$router->post('admin.product.create', function (Request $request) use ($body, $requireAdmin, $validateProduct): void {
    $requireAdmin($request);
    $data = $validateProduct($body($request));

    // حدّ القالب: لا نقبل أكثر من MAX_CATALOG_ITEMS عنصرًا في القائمة
    $repo = new ProductRepository();
    if ($repo->count() >= ProjectConfig::MAX_CATALOG_ITEMS) {
        throw new HttpError(
            'catalog_limit',
            'بلغت القائمة حدّها الأقصى (' . ProjectConfig::MAX_CATALOG_ITEMS . ' عنصرًا). احذف عنصرًا قبل إضافة آخر.',
            422
        );
    }

    $id = $repo->create($data);
    Response::ok(['id' => $id, 'products' => $repo->allAdmin()], 201);
});

$router->post('admin.product.update', function (Request $request) use ($body, $requireAdmin, $validateProduct): void {
    $requireAdmin($request);
    $data = $validateProduct($body($request));
    $id   = (int) ($data['id'] ?? 0);
    $repo = new ProductRepository();
    $existing = $repo->find($id);
    if ($id <= 0 || $existing === null) {
        throw new HttpError('not_found', 'المنتج غير موجود.', 404);
    }
    // لا نمسح الصورة إن لم تُرفع صورة جديدة
    if (empty($data['image_path'])) {
        $data['image_path'] = $existing['image_path'];
    }
    $repo->update($id, $data);
    Response::ok(['products' => $repo->allAdmin()]);
});

$router->post('admin.product.delete', function (Request $request) use ($body, $requireAdmin): void {
    $requireAdmin($request);
    $id  = (int) ($body($request)['id'] ?? 0);
    $repo = new ProductRepository();
    $existing = $repo->find($id);
    if ($id <= 0 || $existing === null) {
        throw new HttpError('not_found', 'المنتج غير موجود.', 404);
    }
    ImageUploader::delete($existing['image_path']);
    $repo->delete($id);
    Response::ok(['products' => $repo->allAdmin()]);
});

/** رفع صورة منتج (multipart). */
$router->post('admin.product.image', function (Request $request) use ($requireAdmin): void {
    $requireAdmin($request);
    $file = $request->file('image');
    if ($file === null) {
        throw new HttpError('missing_file', 'لم يتم اختيار صورة.', 422);
    }
    $path = ImageUploader::store($file, 'products');
    Response::ok(['image_path' => $path, 'image' => ProjectConfig::uploadsUrl($path)], 201);
});

/* ---------------------------- الطلبات ---------------------------- */

$router->get('admin.orders', function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    $repo   = new OrderRepository();
    $result = $repo->listForAdmin([
        'status'      => (string) ($request->get('status') ?? ''),
        'location_id' => (string) ($request->get('location_id') ?? ''),
        'date'        => (string) ($request->get('date') ?? ''),
        'q'           => mb_substr((string) ($request->get('q') ?? ''), 0, 60),
    ], min(200, max(1, (int) ($request->get('limit') ?? 100))));

    Response::ok([
        'orders'     => $result['items'],
        'total'      => $result['total'],
        'statuses'   => array_map(
            static fn(string $k, array $def): array => ['key' => $k, 'label' => $def['label'], 'color' => $def['color']],
            array_keys(ProjectConfig::ORDER_STATUSES),
            ProjectConfig::ORDER_STATUSES
        ),
    ]);
});

$router->get('admin.order', function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    $id    = (int) ($request->get('id') ?? 0);
    $order = (new OrderRepository())->find($id);
    if ($order === null) {
        throw new HttpError('not_found', 'الطلب غير موجود.', 404);
    }
    Response::ok(['order' => $order]);
});

$router->post('admin.order.status', function (Request $request) use ($body, $requireAdmin): void {
    $requireAdmin($request);
    $data = $body($request);
    $id   = (int) ($data['id'] ?? 0);
    $to   = (string) ($data['status'] ?? '');
    $note = mb_substr((string) ($data['note'] ?? ''), 0, 300);

    if (!isset(ProjectConfig::ORDER_STATUSES[$to])) {
        throw new HttpError('invalid_status', 'حالة غير معروفة.', 422);
    }

    $order = (new OrderService())->changeStatus($id, $to, 'admin:admin', $note);
    Response::ok(['order' => $order, 'stats' => (new OrderRepository())->stats()]);
});

/* ---------------------------- المواقع وQR ---------------------------- */

$router->get('admin.locations', function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    $repo = new LocationRepository();
    $base = app_config('app.base_url', '');
    $locations = $repo->all();
    foreach ($locations as &$location) {
        $location['qr_url'] = $base !== '' ? (new QrService())->locationUrl($base, $location['code']) : '';
    }
    unset($location);

    Response::ok(['locations' => $locations, 'suggested_code' => $repo->suggestCode()]);
});

$router->post('admin.location.save', function (Request $request) use ($body, $requireAdmin): void {
    $requireAdmin($request);
    $data = $body($request);
    $repo = new LocationRepository();

    $v = Validator::make($data);
    $v->required('name', 'اسم الموقع')
      ->string('name', 'اسم الموقع', 1, 60)
      ->string('code', 'الرمز', 0, 24)
      ->string('type', 'النوع', 0, 40)
      ->string('note', 'ملاحظة', 0, 200)
      ->integer('sort_order', 'الترتيب', 0, 100000)
      ->boolean('is_active')
      ->pattern('code', '/^[A-Za-z0-9_\-]{1,24}$/', 'الرمز يقبل حروفًا إنجليزية وأرقامًا فقط.')
      ->throwIfFails();
    $clean = $v->validated();

    $id   = (int) ($clean['id'] ?? 0);
    $code = strtoupper(trim((string) ($clean['code'] ?? '')));
    if ($code === '') {
        $code = $repo->suggestCode();
    }
    if ($repo->codeExists($code, $id > 0 ? $id : null)) {
        throw new HttpError('duplicate_code', 'هذا الرمز مستخدم لموقع آخر.', 422);
    }
    $clean['code'] = $code;
    $repo->save($id > 0 ? $id : null, $clean);

    Response::ok(['locations' => $repo->all()]);
});

$router->post('admin.location.delete', function (Request $request) use ($body, $requireAdmin): void {
    $requireAdmin($request);
    $id = (int) ($body($request)['id'] ?? 0);
    (new LocationRepository())->delete($id);
    Response::ok(['locations' => (new LocationRepository())->all()]);
});

/** رمز QR بصيغة SVG للموقع أو للطلب (للطباعة أو التنزيل). */
$router->get('admin.qr', function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    $type  = (string) ($request->get('type') ?? 'location');
    $value = (string) ($request->get('value') ?? '');
    $size  = min(16, max(4, (int) ($request->get('size') ?? 8)));
    $base  = app_config('app.base_url', '');
    if ($base === '') {
        throw new HttpError('missing_base_url', 'لم يُضبط رابط الموقع بعد. اضبطه من إعدادات الاستضافة.', 500);
    }

    $service = new QrService();
    try {
        if ($type === 'order') {
            $svg = $service->orderSvg($base, $value, $size);
        } else {
            $svg = $service->locationSvg($base, $value, $size);
        }
    } catch (\RuntimeException $e) {
        throw new HttpError('qr_failed', $e->getMessage(), 422);
    }

    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Disposition: inline; filename="qr-' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $type) . '.svg"');
    echo $svg;
    exit;
});

/** صفحة طباعة كل رموز المواقع. */
$router->get('admin.qr.sheet', function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    $repo   = new LocationRepository();
    $base   = app_config('app.base_url', '');
    $html   = (new QrService())->printSheetHtml($base, $repo->all(), RuntimeSettings::publicPayload());
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $html;
    exit;
});

/** رمز QR لطلب محدد — لعرضه في صفحة الطلب. */
$router->get('admin.order.qr', function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    $order = (new OrderRepository())->find((int) ($request->get('id') ?? 0));
    if ($order === null) {
        throw new HttpError('not_found', 'الطلب غير موجود.', 404);
    }
    $base = app_config('app.base_url', '');
    Response::ok([
        'qr_url'   => $base !== '' ? (new QrService())->orderUrl($base, $order['tracking_token']) : '',
        'number'    => $order['number'],
        'token'     => $order['tracking_token'],
    ]);
});

/* ---------------------------- إعدادات العرض ---------------------------- */

$router->get('admin.settings', function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    $service = new CatalogService();
    Response::ok([
        'settings'    => RuntimeSettings::adminPayload(),
        'config'      => $service->configPayload(),
        'base_url'    => app_config('app.base_url', ''),
        'company'     => [
            'path'      => 'app/data/company.json',
            'readme'    => 'app/data/README.md',
            'exists'    => CompanyProfile::exists(),
            'sections'  => CompanyProfile::sectionCount(),
            'has_contact' => CompanyProfile::hasContactWays(),
        ],
    ]);
});

$router->post('admin.settings.update', function (Request $request) use ($body, $requireAdmin): void {
    $requireAdmin($request);
    $data = $body($request);

    $v = Validator::make($data);
    $v->string('app_name', 'اسم النشاط', 1, 80)
      ->string('app_tagline', 'الوصف', 0, 160)
      ->string('about_summary', 'نبذة الصفحة الرئيسية', 0, 1200)
      ->string('welcome_message', 'رسالة الترحيب', 0, 500)
      ->string('contact_phone', 'الهاتف', 0, 30)
      ->string('contact_instagram', 'حساب إنستغرام', 0, 60)
      ->boolean('show_welcome')
      ->boolean('show_search')
      ->boolean('show_contact')
      ->boolean('show_prices')
      ->throwIfFails();

    $allowed = ['app_name', 'app_tagline', 'welcome_message', 'contact_phone', 'contact_instagram'];
    $pairs   = [];

    foreach ($allowed as $key) {
        if (array_key_exists($key, $data)) {
            $pairs[$key] = mb_substr(trim((string) $data[$key]), 0, 500);
        }
    }
    // نبذة الصفحة الرئيسية أطول من بقية الحقول (سطران فأكثر)
    if (array_key_exists('about_summary', $data)) {
        $pairs['about_summary'] = mb_substr(trim((string) $data['about_summary']), 0, 1200);
    }
    foreach (['show_welcome', 'show_search', 'show_contact', 'show_prices'] as $flag) {
        if (array_key_exists($flag, $data)) {
            $pairs[$flag] = Validator::toBool($data[$flag]) ? '1' : '0';
        }
    }
    // الشعار يُرفع كصورة عادية
    if (!empty($data['logo_path'])) {
        $pairs['logo_path'] = (string) $data['logo_path'];
    }

    if (ProjectConfig::feature('admin_logo')) {
        $file = $request->file('logo');
        if ($file !== null) {
            ImageUploader::delete(RuntimeSettings::get('logo_path'));
            $pairs['logo_path'] = ImageUploader::store($file, 'brand');
        }
    }

    RuntimeSettings::setMany($pairs);
    RuntimeSettings::flush();

    Response::ok(['settings' => RuntimeSettings::adminPayload()]);
});

/* ---------------------------- النسخ الاحتياطي ---------------------------- */

$router->get('admin.backups', function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    if (!ProjectConfig::feature('admin_backups')) {
        throw new HttpError('disabled', 'هذه الميزة غير مفعّلة.', 404);
    }
    Response::ok(['backups' => (new BackupService())->listBackups()]);
});

$router->post('admin.backup.create', function (Request $request) use ($requireAdmin): void {
    $requireAdmin($request);
    $path = (new BackupService())->create();
    Response::ok([
        'message' => 'تم إنشاء النسخة الاحتياطية.',
        'file'    => basename($path),
        'backups' => (new BackupService())->listBackups(),
    ], 201);
});

/** تنزيل ملف النسخة الاحتياطية (يتطلب جلسة). */
$router->get('admin.backup.download', function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    $name = (string) ($request->get('name') ?? '');
    $data = (new BackupService())->read($name);
    if ($data === null) {
        throw new HttpError('not_found', 'ملف النسخة غير موجود.', 404);
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: no-store');
    echo $data;
    exit;
});

/* ---------------------------- دورة حياة النسخة ---------------------------- */

/**
 * حالة النسخة: ما الذي سيُحذف بالضبط إن قرّر المستخدم التدمير.
 *
 * تُرجع مسارات نسبية فقط — كشف مسار القرص المطلق للمدير عبر الويب
 * لا فائدة منه، ويكشف بنية الخادم.
 */
$router->get('admin.instance.state', function (Request $request) use ($requireAuth): void {
    $requireAuth($request);
    if (!ProjectConfig::feature('admin_rebuild')) {
        throw new HttpError('disabled', 'إدارة دورة حياة النسخة غير مفعّلة في هذه النسخة.', 404);
    }
    Response::json([
        'ok'   => true,
        'data' => (new InstanceService())->state(),
    ], 200, ['Cache-Control' => 'no-store']);
});

/**
 * تدمير النسخة: حذف كل البيانات والعودة إلى ما قبل أول تهيئة.
 *
 * ثلاث حمايات مستقلة، أي واحدة تكفي لوقف العملية:
 *  1) جلسة مدير صالحة + رمز CSRF  ($requireAdmin)
 *  2) كلمة مرور المدير الحالية — لا الجلسة وحدها: جلسة قديمة على جهاز
 *     مشترك يجب ألّا تملك حق محو كل بيانات المشروع
 *  3) عبارة تأكيد مكتوبة حرفيًا — لا ينقر الزر بالخطأ
 *
 * بعدها تُحذف بيانات الدخول، فلا يعود للجلسة أي معنى: ننهيها صراحةً
 * قبل إرسال الاستجابة، وإلا بقي كوكي حيًا لا يفتح شيئًا.
 */
$router->post('admin.instance.destroy', function (Request $request) use ($body, $requireAdmin): void {
    $requireAdmin($request);

    if (!ProjectConfig::feature('admin_rebuild')) {
        throw new HttpError('disabled', 'إدارة دورة حياة النسخة غير مفعّلة في هذه النسخة.', 404);
    }

    /*
     * الجلسة وحدها لا تكفي، ونضيف إليها قفل المحاولات. السبب: جلسة قديمة
     * على جهاز مشترك أو كوكي مسروق يعني خرقًا للجلسة لا للمدير، وبلا
     * قفل يصبح هذا المسار محاولات غير محدودة لكلمة المرور — وهو أسرع
     * طريق لتدمير بيانات المشروع. القفل نفسه المستخدم في تسجيل الدخول،
     * فالمهاجم يوقف نفسه إن حاول التخمين.
     */
    if (RateLimiter::tooManyLoginAttempts($request)) {
        throw new HttpError(
            'locked',
            'تم إيقاف المحاولات مؤقتًا بعد محاولات فاشلة. حاول بعد '
            . ProjectConfig::ADMIN_LOCKOUT_MINUTES . ' دقيقة.',
            429
        );
    }

    $data    = $body($request);
    $service = new InstanceService();

    $password = (string) ($data['password'] ?? '');
    $confirm  = trim((string) ($data['confirm'] ?? ''));

    if (!Auth::checkCredentials(Auth::username(), $password)) {
        RateLimiter::recordLogin($request, Auth::username(), false);
        \App\Core\Logger::warning('Instance destroy rejected: wrong password', [
            'ip'   => $request->ip(),
            'user' => Auth::username(),
        ]);
        throw new HttpError('invalid_credentials', 'كلمة المرور غير صحيحة. لم يُحذف شيء.', 403);
    }

    if (!hash_equals(InstanceService::CONFIRM_WORD, $confirm)) {
        throw new HttpError(
            'confirm_mismatch',
            'عبارة التأكيد غير مطابقة. لم يُحذف شيء. اكتب: ' . InstanceService::CONFIRM_WORD,
            422
        );
    }

    // كلمة المرور صحيحة: امسح عدّاد المحاولات الفاشلة قبل التنفيذ.
    RateLimiter::recordLogin($request, Auth::username(), true);
    RateLimiter::clearLoginAttempts($request);

    // تُقرأ الحالة قبل التدمير: بعدها لن توجد قاعدة بيانات نعدّ منها.
    $before = $service->state();

    $report = $service->destroy('', [
        'confirm' => $confirm,
        'archive' => !isset($data['archive']) || (string) $data['archive'] !== '0',
    ]);

    // بيانات الدخول لم تعد موجودة ⇒ لا جلسة، ولا رمز CSRF صالح بعدها.
    Auth::logout();

    \App\Core\Logger::info('Instance destroyed', [
        'ip'      => $request->ip(),
        'user'    => $before['preset'] ?? '',
        'orders'  => $report['counts']['orders'] ?? 0,
        'archive' => $report['archive'],
    ]);

    Response::json([
        'ok'   => true,
        'data' => [
            'message'        => 'تم تدمير النسخة. الخادم الآن في حالة ما قبل أول تهيئة.',
            'archive'        => $report['archive'],
            'removed'        => $report['removed'],
            'destroyed'      => $before['counts'],
            'setup_available' => is_file(BASE_PATH . '/setup/index.php'),
            // الرابط يُعرض مرة واحدة فقط ثم يُفقد. المفتاح وحده لا يكفي
            // بلا نطاق محفوظ (استضافة بلا base_url صحيح).
            'rebuild_url'    => (string) $report['rebuildUrl'],
            'rebuild_key'    => (string) $report['rebuildKey'],
        ],
    ], 200, ['Cache-Control' => 'no-store']);
});

/**
 * تصدير إعدادات الدخول — نسخة مبهمة (بلا تجزئة كلمة المرور ولا مفتاح التطبيق).
 *
 * كان GET بلا CSRF رغم أنه عملية تُعدّل الحالة، وأسراره كانت
 * تُصدَّر كاملة. صار POST مع رمز CSRF مثل كل عمليات التعديل الأخرى.
 */
$router->post('admin.settings.export', function (Request $request) use ($requireAdmin): void {
    $requireAdmin($request);
    $data = (new BackupService())->exportSettings();
    if ($data === '') {
        throw new HttpError('not_found', 'ملف الإعدادات غير موجود.', 404);
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="app-config-masked.php"');
    header('Cache-Control: no-store');
    echo $data;
    exit;
});