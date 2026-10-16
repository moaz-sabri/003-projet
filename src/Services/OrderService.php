<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\ProjectConfig;
use App\Core\Database;
use App\Core\Http\HttpError;
use App\Core\Http\Request;
use App\Core\Http\Validator;
use App\Core\Money;
use App\Core\Security\RateLimiter;
use App\Repositories\LocationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;

/**
 * منطق إنشاء الطلبات — الجزء الأكثر حساسية في النظام.
 *
 * القواعد الملزمة (readme §7):
 *  1) التحقق من المدخلات.
 *  2) التحقق من المنتجات وأسعارها الحالية وحالة توفرها (لا تُقبل أسعار من الزبون).
 *  3) التحقق من الموقع وفق إعدادات النسخة — على السيرفر لا الواجهة فقط.
 *  4) حساب الإجمالي على السيرفر.
 *  5) حفظ الطلب وعناصره في معاملة واحدة.
 *  6) حفظ اسم المنتج وسعره وقت الطلب.
 *  7) إرجاع رقم الطلب بعد نجاح الحفظ فقط.
 *  8) منع التكرار عبر مفتاح idempotency.
 */
final class OrderService
{
    /**
     * مفتاح التكرار: النمط نفسه الذي يستخدمه OrderRepository عند البحث.
     * التطابق بين الاثنين شرط أساسي: لو قبل الإدخال مفتاحًا يرفضه البحث،
     * فكل إرسال يمرّ ثم يصطدم بالقيد الفريد فيرجع 500 بدل إعادة نظيفة للطلب.
     */
    public const IDEMPOTENCY_PATTERN = '/^[A-Za-z0-9_\-]{8,80}$/';

    /** أقصى طول لعمود idempotency_key — يوافق النمط أعلاه. */
    public const IDEMPOTENCY_MAX = 80;

    private ProductRepository $products;
    private LocationRepository $locations;
    private OrderRepository $orders;

    public function __construct()
    {
        $this->products  = new ProductRepository();
        $this->locations = new LocationRepository();
        $this->orders    = new OrderRepository();
    }

    /**
     * @param array $payload محتوى الطلب من الواجهة
     * @return array بيانات الطلب بعد الحفظ
     */
    public function create(array $payload, ?Request $request = null): array
    {
        if ($request !== null && !RateLimiter::hitPublicApi($request, 'order.create', ProjectConfig::ORDER_RATE_LIMIT_PER_MINUTE)) {
            throw new HttpError('too_many_requests', 'طلبات كثيرة خلال وقت قصير. انتظر قليلًا ثم أعد المحاولة.', 429);
        }

        // المفتاح يأتي من العميل: يُقصّ ويُتحقَّق منه *قبل* أي استخدام.
        // بدون ذلك يُخزَّن نص طويل بلا سقف (حتى حدّ post_max_size) في عمود
        // مفهرس لا يُنظَّف، ومفتاح لا يطابق النمط يمرّ من هنا فلا يمنعه البحث،
        // فيصطدم بالقيد الفريد لاحقًا ويرجع 500 بدل إعادة نظيفة للطلب.
        $idempotencyKey = isset($payload['idempotency_key']) && is_scalar($payload['idempotency_key'])
            ? trim((string) $payload['idempotency_key'])
            : '';
        if ($idempotencyKey !== ''
            && (strlen($idempotencyKey) > self::IDEMPOTENCY_MAX || !preg_match(self::IDEMPOTENCY_PATTERN, $idempotencyKey))
        ) {
            $idempotencyKey = '';   // مفتاح غير صالح = لا تكرار، لا خطأ للمستخدم
        }

        // (8) إعادة الإرسال بنفس المفتاح تُعيد الطلب نفسه دون تكرار (سلوك مقصود).
        // ملاحظة أمنية: المفتاح يعمل كـ bearer token — من يعرفه يقرأ الطلب كاملًا.
        // الواجهة تولّده بـ crypto.randomUUID() فلا يُخمَّن عمليًا، والجدار الفعلي
        // أنه لا يظهر في referer ولا في أي رابط.
        if ($idempotencyKey !== '') {
            $existing = $this->orders->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                $full = $this->orders->find((int) $existing['id']) ?? $existing;
                $full['idempotent_replay'] = true;
                return $full;
            }
        }

        // (1) التحقق من المدخلات
        $type        = (string) ($payload['order_type'] ?? '');
        $typeDef     = ProjectConfig::orderType($type);
        if ($typeDef === null) {
            throw new HttpError('invalid_order_type', 'نوع الطلب غير معروف.', 422);
        }

        $name = trim((string) ($payload['customer_name'] ?? ''));
        if (ProjectConfig::CUSTOMER_NAME_REQUIRED && $name === '') {
            throw new HttpError('missing_customer_name', 'يرجى كتابة الاسم قبل إرسال الطلب.', 422, [
                'fields' => ['customer_name' => 'الاسم مطلوب.'],
            ]);
        }
        if (mb_strlen($name) > 60) {
            throw new HttpError('invalid_customer_name', 'الاسم طويل جدًا.', 422);
        }
        $name = self::cleanText($name);

        // (3) الموقع — يُفرض على السيرفر
        $location = $this->resolveLocation($payload, $type);

        // العناصر
        $items = $payload['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new HttpError('empty_cart', 'السلة فارغة.', 422);
        }
        if (count($items) > ProjectConfig::MAX_ITEMS_PER_ORDER) {
            throw new HttpError('too_many_items', 'عدد عناصر الطلب كبير جدًا.', 422);
        }

        // (2)+(4) التحقق من المنتجات وحساب الإجمالي على السيرفر
        [$lines, $total, $itemsCount, $notes] = $this->resolveItems($items, $payload);

        $phone = '';
        $fields = $this->resolveFields($type, $payload['fields'] ?? []);

        // "ملاحظات" معرّف كحقل ضمن نوع الطلب، فقد تصل داخل fields
        if ($notes === '' && isset($fields['notes'])) {
            $notes = self::cleanText((string) $fields['notes']);
        }
        unset($fields['notes']);

        if (!empty($fields['customer_phone'])) {
            $phone = (string) $fields['customer_phone'];
        }
        if (ProjectConfig::CUSTOMER_PHONE_REQUIRED && $phone === '') {
            $phone = trim((string) ($payload['customer_phone'] ?? ''));
        }
        unset($fields['customer_phone']);

        // (5) الحفظ في معاملة واحدة
        $orderId = Database::transaction(function () use (
            $type, $name, $phone, $location, $fields, $notes, $lines, $total, $itemsCount, $idempotencyKey
        ): int {
            $day   = date('Y-m-d');
            $now   = date('Y-m-d H:i:s');
            $token = $this->orders->newToken();

            $orderId = Database::insert('orders', [
                'public_token'    => $token,
                'number'          => 0,            // يُملأ بعدد اليوم
                'served_on'       => $day,
                'order_type'      => $type,
                'customer_name'   => $name,
                'customer_phone'  => mb_substr($phone, 0, 30),
                'location_id'     => $location['id'] ?? null,
                'location_label'  => $location['name'] ?? '',
                'status'          => 'new',
                'fields_json'     => self::encodeJson($fields),
                'notes'           => mb_substr((string) $notes, 0, 500),
                'total_minor'     => $total,
                'items_count'     => $itemsCount,
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

            // رقم ظاهر متسلسل لكل يوم
            $number = $this->orders->nextNumber($day);
            Database::update('orders', ['number' => $number], 'id = :id', ['id' => $orderId]);

            // (6) حفظ لقطة من اسم المنتج وسعره وكميته
            foreach ($lines as $line) {
                Database::insert('order_items', [
                    'order_id'         => $orderId,
                    'product_id'       => $line['product_id'],
                    'name'             => $line['name'],
                    'unit_price_minor' => $line['unit_price_minor'],
                    'quantity'         => $line['quantity'],
                    'line_total_minor' => $line['line_total_minor'],
                    'extra_json'       => self::encodeJson($line['extra']),
                    'created_at'      => $now,
                ]);
            }

            Database::insert('order_events', [
                'order_id'    => $orderId,
                'from_status' => '',
                'to_status'   => 'new',
                'actor'       => 'customer',
                'note'        => 'تم إنشاء الطلب من الواجهة',
                'created_at'  => $now,
            ]);

            return $orderId;
        });

        // (7) لا تأكيد نجاح قبل الحفظ
        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new HttpError('save_failed', 'تعذّر حفظ الطلب. حاول مجددًا.', 500);
        }
        return $order;
    }

    /* ------------------------------------------------------------------ */

    /**
     * (3) تحديد الموقع وفق إعدادات النسخة.
     */
    private function resolveLocation(array $payload, string $type): array
    {
        $required = ProjectConfig::typeRequiresLocation($type);

        if (!ProjectConfig::feature('locations')) {
            if ($required) {
                throw new HttpError('location_required', 'هذه النسخة لا تدعم الطلب بدون موقع.', 422);
            }
            return [];
        }

        $code = trim((string) ($payload['location_code'] ?? ''));
        $id   = (int) ($payload['location_id'] ?? 0);

        $location = null;
        if ($code !== '') {
            $location = $this->locations->findByCode($code);
        } elseif ($id > 0) {
            $location = $this->locations->find($id);
        }

        // (8) موقع معطّل أو غير موجود + اشتراط الموقع → رفض الطلب على السيرفر
        if ($location === null || !$location['active']) {
            if ($required) {
                throw new HttpError('location_required', 'يجب اختيار ' . ProjectConfig::locationSingular() . ' صالحة قبل إرسال الطلب.', 422, [
                    'fields' => ['location' => 'الموقع غير صالح أو غير نشط.'],
                ]);
            }
            return [];
        }

        return $location;
    }

    /**
     * (2) التحقق من كل عنصر مقابل قاعدة البيانات + (4) حساب الإجمالي على السيرفر.
     *
     * @return array{0: array, 1: int, 2: int, 3: string}
     */
    private function resolveItems(array $items, array $payload): array
    {
        $merged = [];
        $notes  = '';

        foreach ($items as $index => $item) {
            // عنصر غير صالح يُهمَل بصمت: الزبون يختار 3 أصناف فيُحذف صنفان
            // ولا يعرف. الأصل: كل خطأ في عنصر يجب أن يُقال، لا أن يُخفى.
            if (!is_array($item)) {
                throw new HttpError('invalid_cart_item', 'عنصر في السلة غير صالح.', 422, [
                    'fields' => ['items.' . $index => 'عنصر غير صالح.'],
                ]);
            }
            $rawQty = $item['quantity'] ?? null;
            if (!is_scalar($rawQty) || !is_numeric($rawQty)) {
                throw new HttpError('invalid_quantity', 'كمية غير صالحة في السلة.', 422, [
                    'fields' => ['items.' . $index . '.quantity' => 'كمية غير صالحة.'],
                ]);
            }
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity  = (int) $rawQty;

            if ($productId <= 0) {
                throw new HttpError('invalid_cart_item', 'أحد عناصر السلة لا يشير إلى منتج.', 422, [
                    'fields' => ['items.' . $index . '.product_id' => 'منتج غير صالح.'],
                ]);
            }
            if ($quantity <= 0) {
                throw new HttpError('invalid_quantity', 'الكمية يجب أن تكون واحدًا على الأقل.', 422, [
                    'fields' => ['items.' . $index . '.quantity' => 'كمية يجب أن تكون 1 أو أكثر.'],
                ]);
            }

            // الخطر: كان الفحص على الكمية المفردة قبل الدمج، فإرسال 40 عنصرًا
            // لنفس المنتج بكمية 99 كان يتجاوز الحدّ 40 ضعفًا (المتوثَّق 99).
            // الفحص على المجموع بعد الدمج هو الصحيح.
            $mergedQty = ($merged[$productId] ?? 0) + $quantity;
            if ($quantity > ProjectConfig::MAX_QTY_PER_ITEM || $mergedQty > ProjectConfig::MAX_QTY_PER_ITEM) {
                throw new HttpError('invalid_quantity', 'الكمية المطلوبة كبيرة جدًا.', 422);
            }
            $merged[$productId] = $mergedQty;
        }

        if ($merged === []) {
            throw new HttpError('empty_cart', 'لا توجد عناصر صالحة في السلة.', 422);
        }

        // (2) لا نثق بسعر الواجهة إطلاقًا
        $products = Database::all(
            'SELECT id, name, price_minor, is_available, extra_json FROM products WHERE id IN (' .
            implode(',', array_map('intval', array_keys($merged))) . ')'
        );
        $byId = [];
        foreach ($products as $p) {
            $byId[(int) $p['id']] = $p;
        }

        $lines     = [];
        $total     = 0;
        $itemsCount = 0;

        foreach ($merged as $productId => $quantity) {
            if (!isset($byId[$productId])) {
                throw new HttpError('product_not_found', 'أحد المنتجات لم يعد موجودًا في القائمة. حدّث الصفحة.', 422);
            }
            $product = $byId[$productId];

            if ((int) $product['is_available'] !== 1) {
                throw new HttpError(
                    'product_unavailable',
                    'المنتج «' . $product['name'] . '» لم يعد متاحًا الآن. احذفه من السلة لإتمام الطلب.',
                    422,
                    ['product_id' => $productId]
                );
            }

            $unitPrice = (int) $product['price_minor'];
            $lineTotal = $unitPrice * $quantity;
            $total    += $lineTotal;
            $itemsCount += $quantity;

            $lines[] = [
                'product_id'       => (int) $product['id'],
                'name'             => (string) $product['name'],
                'unit_price_minor' => $unitPrice,
                'quantity'         => $quantity,
                'line_total_minor' => $lineTotal,
                'extra'            => json_decode((string) $product['extra_json'], true) ?: [],
            ];
        }

        // ملاحظات الطلب
        if (ProjectConfig::feature('notes')) {
            $notes = trim((string) ($payload['notes'] ?? ''));
            if (mb_strlen($notes) > 500) {
                throw new HttpError('notes_too_long', 'الملاحظات طويلة جدًا.', 422);
            }
        }

        return [$lines, $total, $itemsCount, self::cleanText($notes)];
    }

    /**
     * التحقق من الحقول الإضافية وفق تعريف نوع الطلب في ProjectConfig.
     */
    private function resolveFields(string $type, array $input): array
    {
        $definitions = ProjectConfig::orderFieldsFor($type);
        $clean       = [];

        foreach ($definitions as $field) {
            $key   = $field['key'];
            $value = $input[$key] ?? '';

            if ($field['type'] === 'boolean') {
                $clean[$key] = Validator::toBool($value) ? 1 : 0;
                continue;
            }

            $value = trim((string) (is_scalar($value) ? $value : ''));

            if ($value === '') {
                if (!empty($field['required'])) {
                    throw new HttpError('missing_field', $field['label'] . ' مطلوب.', 422, [
                        'fields' => [$key => $field['label'] . ' مطلوب.'],
                    ]);
                }
                continue;
            }

            $maxLen = (int) ($field['max_length'] ?? 300);
            if (mb_strlen($value) > $maxLen) {
                throw new HttpError('field_too_long', $field['label'] . ' طويل جدًا.', 422);
            }

            if ($field['type'] === 'number') {
                if (!is_numeric($value)) {
                    throw new HttpError('invalid_field', $field['label'] . ' يجب أن يكون رقمًا.', 422);
                }
                $num = (int) $value;
                if (isset($field['min']) && $num < (int) $field['min']) {
                    throw new HttpError('invalid_field', $field['label'] . ' أقل من الحد المسموح.', 422);
                }
                if (isset($field['max']) && $num > (int) $field['max']) {
                    throw new HttpError('invalid_field', $field['label'] . ' أكبر من الحد المسموح.', 422);
                }
                $clean[$key] = $num;
                continue;
            }

            if ($field['type'] === 'tel' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $value)) {
                throw new HttpError('invalid_field', 'رقم الهاتف غير صالح.', 422);
            }

            $clean[$key] = self::cleanText($value);
        }

        return $clean;
    }

    /**
     * ترميز JSON للأعمدة النصية. json_encode يُرجع false عند UTF-8 تالف؛
     * كان false يُربط كـ PARAM_INT = 0 فتُخزَّن «0» بدل الحقول — أي أن بايتًا
     * واحدًا تالفًا يمحو كل حقول الطلب بصمت.
     */
    private static function encodeJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new HttpError('invalid_input', 'بيانات الطلب تحتوي على أحرف غير صالحة.', 422);
        }
        return $json;
    }

    /** تحديث حالة الطلب من الإدارة مع احترام الانتقالات المسموحة. */
    public function changeStatus(int $orderId, string $to, string $actor, string $note = ''): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new HttpError('not_found', 'الطلب غير موجود.', 404);
        }

        $from = $order['status'];
        if ($from === $to) {
            return $order;
        }

        $allowed = ProjectConfig::ORDER_STATUSES[$from]['next'] ?? [];
        if (!in_array($to, $allowed, true)) {
            throw new HttpError('invalid_transition', 'لا يمكن الانتقال من «' . ($order['status_label'] ?? $from) . '» إلى الحالة المطلوبة.', 422);
        }

        Database::transaction(function () use ($orderId, $from, $to, $actor, $note): void {
            $this->orders->updateStatus($orderId, $from, $to, $actor, $note);
            if ($to === 'cancelled' && $note !== '') {
                $this->orders->cancelReason($orderId, $note);
            }
        });

        return $this->orders->find($orderId) ?? [];
    }

    /** منع أي محارف خطرة أو روابط في النصوص القصيرة. */
    public static function cleanText(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = strip_tags($value);
        return trim($value);
    }
}