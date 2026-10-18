<?php

declare(strict_types=1);

namespace App\Config;

/**
 * عقد القالب — القواعد التي لا تتغيّر مهما تغيّر المشروع.
 *
 * ## لماذا هذا الملف؟
 *
 * سياسة المشروع تفصل ثلاثة أشياء:
 *   1) **القالب** — بنية ثابتة لا تُعدَّل من لوحة الإدارة ولا من صفحة التهيئة.
 *   2) **البيانات** — تُملأ وتُمحى بحرية (المنتجات، الطلبات، الإعدادات).
 *   3) **دورة حياة النسخة** — تُبنى ثم يمكن تدميرها والعودة إلى الصفر
 *      والبناء من جديد بنشاط مختلف (انظر App\Services\InstanceService).
 *
 * ما كان ناقصًا هو **الحدّ الفاصل المُعلن** بين (1) و(2). قبل هذا الملف كان
 * أي تعديل على أي شيء يبدو مشروعًا، والخلط بينهما هو ما يُنتج قالبًا متآكلًا
 * لا يعرف أحد سبب وجود ملف فيه. هنا نكتب الحدّ صراحةً ونتحقق منه آليًا
 * (tests/suites/instance.php) بدل الاعتماد على الذاكرة.
 *
 * ## القاعدة الذهبية
 *
 * ما لم يُدرج في `ADMIN_MUTABLE` أدناه — أو في تعريفات الحقول نفسها —
 * فهو **مجمّد**: تعديله ليس تخصيصًا، بل إصدار قالب جديد.
 *
 * @package App\Config
 */
final class TemplateContract
{
    /* =====================================================================
     * أ. أنواع حقول الإدخال — مجموعة مغلقة
     * =====================================================================
     *
     * هذا هو الثابت الأكثر أهمية في القالب كله، لأن كل حقل يدخل به الزبون
     * يمرّ بأربع طبقات مرتبطة ببعضها:
     *
     *   1) تعريف الحقل هنا وفي ProjectConfig/BusinessPresets
     *   2) التحقق (App\Core\Http\Validator)
     *   3) التخزين — عمود SQLite أو قيمة داخل extra_json
     *   4) العرض (app/js/ui.js · admin/assets/admin.js)
     *
     * نوع جديد هنا بلا تغيير الطبقات الأربع ليس ميزة جديدة: هو حقل يُقبل من
     * الزبون ولا يُتحقق منه، أو يُخزَّن بنوع لا يطابق ما يعرضه.
     * لذلك الأنواع تُضاف **فقط** بترقية إصدار قالب موثّقة.
     */

    public const FIELD_TYPES = [
        'text'     => 'نص قصير',
        'textarea' => 'نص طويل',
        'number'   => 'رقم',
        'tel'      => 'هاتف',
        'select'   => 'قائمة اختيارات',
        'boolean'  => 'صح / خطأ',
        'tags'     => 'وسوم متعددة',
        'date'     => 'تاريخ',
    ];

    /* =====================================================================
     * ب. القواعد المجمّدة
     * ===================================================================== */

    /**
     * @var array<int, array{key:string, rule:string, why:string}>
     */
    public const INVARIANTS = [
        [
            'key'  => 'field_types',
            'rule' => 'أنواع حقول الإدخال محدودة بـ: ' . 'text · textarea · number · tel · select · boolean · tags · date',
            'why'  => 'كل نوع مرتبط بأربع طبقات (تحقق · تخزين · عرض · تقرير). نوع بلا تحديث الطبقات الأربع = حقل يُقبل ولا يُفحص.',
        ],
        [
            'key'  => 'money_minor',
            'rule' => 'كل مبلغ يُخزَّن كعدد صحيح في *_minor، والعرض يحسبه من CURRENCY_DECIMALS.',
            'why'  => 'الفاصلة العائمة في المال مصدر تصادمات في الإجماليات. القاعدة الوحيدة التي تمنعها هي التخزين كعدد صحيح.',
        ],
        [
            'key'  => 'price_authority',
            'rule' => 'الخادم يحسب الإجمالي من قاعدة البيانات ويتجاهل أي سعر يرسله العميل.',
            'why'  => 'لو قبل الخادم سعرًا من الطلب لأمكن التلاعب بالأسعار بطلب واحد.',
        ],
        [
            'key'  => 'response_envelope',
            'rule' => 'كل استجابة API بالشكل {ok:true,data} أو {ok:false,error:{code,message}}.',
            'why'  => 'كل عميل (الواجهة، اللوحة، أدوات الفحص) يعتمد على هذا الشكل تحديدًا.',
        ],
        [
            'key'  => 'column_names',
            'rule' => 'أسماء أعمدة الجداول الأساسية ثابتة (products · orders · order_items · settings · …).',
            'why'  => 'المستودعات والترحيلات ونسخ الـ SQL الاحتياطية تكتب هذه الأسماء حرفيًا.',
        ],
        [
            'key'  => 'order_tokens',
            'rule' => 'public_token و idempotency_key هما مفتاح متابعة الطلب ومنع التكرار.',
            'why'  => 'روابط QR المطبوعة تحمل public_token؛ وتغيير اسمه يُبطل كل رموز QR في العالم.',
        ],
        [
            'key'  => 'extra_json',
            'rule' => 'الحقول الإضافية تُخزَّن في extra_json لا في أعمدة جديدة.',
            'why'  => 'إضافة حقل = إدخال في تعريف، بلا migration وبلا تعديل على الجداول القائمة.',
        ],
        [
            'key'  => 'clock',
            'rule' => 'كل الأوقات تُكتب عبر Database::now() بتوقيت النسخة، ولا UTC مباشرة.',
            'why'  => 'datetime(\'now\') في SQLite يُرجع UTC، فتظهر أوقات الطلبات متأخرة أو متقدمة في التقارير اليومية.',
        ],
        [
            'key'  => 'order_snapshots',
            'rule' => 'لقطات الطلبات السابقة (order_items.extra_json والأسعار المخزّنة) لا تُمس أبدًا.',
            'why'  => 'سجل محاسبي: تعديل اسم المنتج أو سعره اليوم لا يغيّر ما وقّعه الزبون أمس.',
        ],
        [
            'key'  => 'clean_routes',
            'rule' => 'المسارات النظيفة /cart و /checkout و /order/{token} و / بانية في القالب.',
            'why'  => 'رموز QR المطبوعة تشير إليها؛ تغييرها يعني إعادة توليد وطباعة كل الرموز.',
        ],
        [
            'key'  => 'security_rules',
            'rule' => 'استعلامات محضّرة · CSRF في كل تعديل · hash لكلمة المرور · حد إرسال · رفع صور مفحوصة.',
            'why'  => 'ليست ميزات اختيارية. تعطيلها من الواجهة يعني فتح ثغرة لا رجعة فيها.',
        ],
        [
            'key'  => 'outside_webroot',
            'rule' => 'storage/ يجب ألا يكون قابلًا للوصول عبر الويب (htaccess أو نقله خارج public_html).',
            'why'  => 'ملف app.sqlite فيه كل الطلبات وأسماء الزبائن. إبقاؤه تحت مسار الويب خطأ بنية لا خطأ إعداد.',
        ],
        [
            'key'  => 'preset_shape',
            'rule' => 'كل نوع نشاط يحمل المفاتيح نفسها (mode · product_singular · order_types · extra_fields · features).',
            'why'  => 'الطبقة التي تغلّف ProjectConfig تقرأ هذه المفاتيح بالاسم؛ ناقصها يُعطّل الاختيار الصامت.',
        ],
    ];

    /* =====================================================================
     * ج. ما يمكن للواجهة تغييره فعلًا — قائمة بيضاء
     * =====================================================================
     *
     * كل ما ليس هنا يُعدّ بنية. لو أردتَ شيئًا هنا فالتعديل في القالب
     * ( Tron + إصدار جديد )، لا في بيانات النسخة.
     *
     * @var array<int, array{key:string, items:string}>
     */
    public const ADMIN_MUTABLE = [
        [
            'key'   => 'catalog',
            'items' => 'التصنيفات · المنتجات (بالحقول المعرّفة فقط) · الصور · الأسعار · التوفّر',
        ],
        [
            'key'   => 'locations',
            'items' => 'المواقع ورموزها (عند تفعيل نظام المواقع)',
        ],
        [
            'key'   => 'orders',
            'items' => 'حالات الطلب ضمن ORDER_STATUSES · الملاحظات · الإلغاء · التصدير والطباعة',
        ],
        [
            'key'   => 'display',
            'items' => 'اسم النشاط · الوصف · رسالة الترحيب · الهاتف · الإنستغرام · الشعار · مفاتيح الظهور',
        ],
        [
            'key'   => 'lifecycle',
            'items' => 'نوع النشاط (preset) · تدمير النسخة والعودة للبداية · النسخ الاحتياطي · كلمة مرور المدير',
        ],
    ];

    /* =====================================================================
     * د. أدوات
     * ===================================================================== */

    public static function isKnownFieldType(mixed $type): bool
    {
        return is_string($type) && isset(self::FIELD_TYPES[$type]);
    }

    /**
     * النوع الفعلي بعد التصحيح: غير المعروف يُسقط إلى 'text'
     * (سلوك آمن — لا رفض ولا قيمة ضائعة).
     */
    public static function normalizeFieldType(mixed $type): string
    {
        return self::isKnownFieldType($type) ? (string) $type : 'text';
    }

    /**
     * القيمة الافتراضية للحقل، من مفتاح 'default' في تعريفه.
     *
     * هي المرجع الوحيد: تقرأه Migrations عند إضافة حقول جديدة (تملأ العناصر
     * السابقة بهذه القيمة)، وتقرأه واجهات الكتابة، وتعرضه اللوحة.
     * توحيدها هنا يمنع أن تعبّئ المَرحل قيمة ويعرض النموذج أخرى.
     *
     * الحقول التي لا تذكر 'default' تأخذ قيمة فارغة صالحة لنوعها:
     * boolean ← 0، number/text ← '' (لا null، لأن NULL يخالف NOT NULL
     * في الجداول ويصير حالة ثالثة لا يعرفها النموذج).
     */
    public static function fieldDefault(array $field): string|int
    {
        $type = self::normalizeFieldType($field['type'] ?? 'text');
        $raw  = $field['default'] ?? null;

        if ($type === 'boolean') {
            if ($raw === null) {
                return 0;
            }
            return \App\Core\Http\Validator::toBool($raw) ? 1 : 0;
        }

        if ($type === 'number') {
            if ($raw === null || $raw === '') {
                return '';
            }
            return is_numeric($raw) ? (int) $raw : '';
        }

        if ($raw === null || is_array($raw) || is_object($raw)) {
            return '';
        }
        return mb_substr(trim((string) $raw), 0, 400);
    }

    /**
     * يتحقق من أن كل تعريفات الحقول تمرّ بأنواع معروفة.
     * يُستدعى قبل زرع أي تعريف جديد (ترحيل أو تعديل قالب).
     *
     * @throws \InvalidArgumentException
     */
    public static function assertFieldTypes(array $fields, string $where = ''): void
    {
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $type  = $field['type'] ?? 'text';
            $label = (string) ($field['label'] ?? $field['key'] ?? '؟');
            if (!self::isKnownFieldType($type)) {
                throw new \InvalidArgumentException(
                    'نوع حقل غير معروف' . ($where !== '' ? ' في ' . $where : '')
                    . ': «' . $label . '» → ' . (is_scalar($type) ? (string) $type : '?')
                    . '. الأنواع المسموحة: ' . implode(' · ', array_keys(self::FIELD_TYPES))
                );
            }
            if (empty($field['key']) || !is_string($field['key'])) {
                throw new \InvalidArgumentException(
                    'حقل بلا مفتاح key' . ($where !== '' ? ' في ' . $where : '') . ': «' . $label . '»'
                );
            }
        }
    }

    /** @return array<int, array{key:string, rule:string, why:string}> */
    public static function invariants(): array
    {
        return self::INVARIANTS;
    }

    /**
     * ملخّص للعرض في لوحة الإدارة (تبويب «معلومات النسخة»).
     *
     * @return array<int, array{key:string, items:string}>
     */
    public static function mutableGroups(): array
    {
        return self::ADMIN_MUTABLE;
    }
}
