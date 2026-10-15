<?php
/**
 * ProjectConfig — المرجع الوحيد لإعدادات النسخة الهيكلية.
 *
 * كل تعديل بنيوي في القالب يتم من هنا ومن الكود فقط (لا يوجد محرر حقول
 * في لوحة الإدارة). البيانات التشغيلية القابلة للتعديل اليومي تُحفظ في
 * جدول settings داخل SQLite، وتُقرأ عبر RuntimeSettings.
 *
 * لتحويل القالب إلى نشاط آخر (معرض سيارات، متجر، مول):
 *   1) عدّل ثوابت الهوية ونوع النشاط والتسميات.
 *   2) عدّل PRODUCT_EXTRA_FIELDS و PRODUCT_DETAIL_FIELDS.
 *   3) عدّل ORDER_TYPES و FEATURES.
 *   4) أعد تهيئة قاعدة البيانات بالأمر: php bin/console migrate:fresh
 *
 * @package App\Config
 */

declare(strict_types=1);

namespace App\Config;

final class ProjectConfig
{
    /* =====================================================================
     * طبقة التغطية وقت التشغيل (Runtime Overrides)
     * =====================================================================
     * الثوابت أدناه هي القيم الافتراضية. أما ما يختاره المستخدم في صفحة
     * التهيئة (نوع النشاط) فيُخزَّن في جدول settings ويُطبَّق هنا عبر
     * setOverrides()، فتتغيّر التسميات والوضع والخصائص بلا تعديل الكود.
     *
     * القاعدة: كل بنية قابلة للتغيير تُقرأ عبر دالة (mode، locationSingular)
     * لا عبر الثابت مباشرة. والثابت يبقى مرجعًا احتياطيًا للمشاريع التي
     * لا تمرّ بصفحة التهيئة.
     * ===================================================================== */

    /** @var array<string, mixed>|null */
    private static ?array $overrides = null;

    /**
     * يطبّق تغطية نوع النشاط المختار. تُستدعى مرة واحدة لكل طلب.
     *
     * @param array<string, mixed>|null $overrides حزمة من BusinessPresets::toOverrides()
     *                                             أو null لإلغاء التغطية.
     */
    public static function setOverrides(?array $overrides): void
    {
        self::$overrides = ($overrides === null || $overrides === []) ? null : $overrides;
    }

    /**
     * يقرأ نوع النشاط المحفوظ في الإعدادات ويطبّقه.
     * آمنة للاستدعاء أكثر من مرة، ولا تفشل إن لم تكن قاعدة البيانات جاهزة بعد.
     */
    public static function applyStoredPreset(): void
    {
        try {
            $id = \App\Core\RuntimeSettings::get('business_preset', '');
            self::setOverrides(
                BusinessPresets::exists($id) ? BusinessPresets::toOverrides($id) : null
            );
        } catch (\Throwable) {
            self::setOverrides(null);
        }
    }

    /** @return mixed قيمة التغطية إن وُجدت، وإلا القيمة الافتراضية */
    private static function ov(string $key, mixed $default = null): mixed
    {
        return self::$overrides[$key] ?? $default;
    }

    /* =====================================================================
     * إصدار القالب — المرجع الوحيد لرقم النسخة
     * =====================================================================
     * عند رفع نسخة جديدة حدّث الرقم هنا فقط، ثم وزّعه على:
     *   - app/index.html  (سمة الاستبدال ?v= في ملفات الواجهة)
     *   - sw.js           (ثابت VERSION لتفعيل تحديث كاش عامل الخدمة)
     *   - CHANGELOG.md
     * ===================================================================== */

    /** رقم إصدار القالب (دلالي). */
    public const VERSION = '1.3.0';

    /** تاريخ اعتماد هذه النسخة. */
    public const RELEASED_AT = '2026-10-15';

    /* =====================================================================
     * أ. وضع المشروع: مع نظام طلبات أم عرض فقط
     * ===================================================================== */

    /** المشروع فيه سلة وطلبات وإتمام طلب. */
    public const MODE_ORDERS  = BusinessPresets::MODE_ORDERS;

    /** المشروع عرض فقط: منتجات للتصفح، بلا سلة ولا طلبات. */
    public const MODE_CATALOG = BusinessPresets::MODE_CATALOG;

    /** @return self::MODE_* وضع المشروع الفعّال */
    public static function mode(): string
    {
        $mode = (string) self::ov('mode', self::MODE_ORDERS);
        return $mode === self::MODE_CATALOG ? self::MODE_CATALOG : self::MODE_ORDERS;
    }

    /** هل يعرض المشروع نظام طلبات كاملًا (سلة، إتمام، متابعة)؟ */
    public static function hasOrdering(): bool
    {
        return self::mode() === self::MODE_ORDERS;
    }

    /** معرّف نوع النشاط المختار (restaurant, store, showroom, ...). */
    public static function presetId(): string
    {
        $id = (string) self::ov('preset_id', '');
        return $id !== '' ? $id : (string) self::BUSINESS_TYPE;
    }

    /** التسمية العربية لنوع النشاط المختار. */
    public static function presetLabel(): string
    {
        return (string) self::ov('preset_label', (string) self::BUSINESS_TYPE);
    }

    /* =====================================================================
     * ب. التسميات — تتبع نوع النشاط المختار
     * ===================================================================== */

    /** تسمية النشاط المفردة ("مطعم"، "متجر"، "العيادة"). */
    public static function businessLabel(): string
    {
        return (string) self::ov('business_label', 'نشاط');
    }

    /** تسمية المنتج المفرد ("صنف" / "منتج" / "مركبة" / "خدمة"). */
    public static function productSingular(): string
    {
        return (string) self::ov('product_singular', 'صنف');
    }

    /** تسمية المنتج بالجمع ("الأصناف" / "المنتجات" / "المركبات"). */
    public static function productPlural(): string
    {
        return (string) self::ov('product_plural', 'المنتجات');
    }

    /** عنوان صفحة المنتجات ("القائمة" / "المنتجات" / "المعرض"). */
    public static function catalogTitle(): string
    {
        return (string) self::ov('catalog_title', 'المنتجات');
    }

    public static function catalogCta(): string
    {
        return (string) self::ov('catalog_cta', 'تصفّح المنتجات');
    }

    public static function orderCta(): string
    {
        return (string) self::ov('order_cta', 'اطلب الآن');
    }

    public static function homeHeroTitle(): string
    {
        return (string) self::ov('home_hero_title', (string) self::DEFAULT_APP_TAGLINE);
    }

    /* =====================================================================
     * ج. الهوية والعرض
     * ===================================================================== */

    /** اسم النشاط كما يظهر افتراضيًا (يمكن تغييره من لوحة الإدارة). */
    public const DEFAULT_APP_NAME = 'مشروعي';

    /** سطر تعريفي قصير أسفل الاسم. */
    public const DEFAULT_APP_TAGLINE = 'قائمة رقمية سريعة — اطلب مباشرة من طاولتك';

    /** رسالة ترحيب اختيارية تُعرض في صفحة الترحيب (فارغة = بلا رسالة). */
    public const DEFAULT_WELCOME_MESSAGE = 'تصفّح القائمة وأرسل طلبك مباشرة. اختر طاولتك من الرمز أو من القائمة، ولا حاجة لإنشاء حساب.';

    /** اللغة واتجاه الواجهة. */
    public const LOCALE          = 'ar';
    public const DIRECTION       = 'rtl'; // rtl | ltr

    /** العملة: رمز العرض وموضعه، وعدد الخانات العشرية. */
    public const CURRENCY         = 'ل.ل';
    public const CURRENCY_POSITION = 'after'; // before | after
    public const CURRENCY_DECIMALS = 0;

    /** المنطقة الزمنية لحساب أوقات الطلبات والتقارير. */
    public const TIMEZONE = 'Asia/Beirut';

    /** الحد الأقصى لعدد عناصر الطلب الواحد (حماية من الإساءة). */
    public const MAX_ITEMS_PER_ORDER = 40;

    /**
     * حد إنشاء الطلبات في الدقيقة لكل عنوان IP.
     * ملاحظة مهمة: زبائن النشاط الواحد غالبًا يتشاركون عنوان IP واحدًا (NAT)
     * عبر شبكة الواي-فاي، لذا يجب أن يبقى الحد سخيًا بما يكفي لتشغيل متزامن
     * كثيف مع استمراره في منع الإغراق الآلي.
     */
    public const ORDER_RATE_LIMIT_PER_MINUTE = 60;

    /** أقصى كمية لعنصر واحد في الطلب. */
    public const MAX_QTY_PER_ITEM = 99;

    /** أقصى عدد منتجات في صفحة القائمة ( paging ) */
    public const CATALOG_PAGE_SIZE = 200;

    /* =====================================================================
     * د. نوع النشاط والمواقع
     * ===================================================================== */

    /** restaurant | cafe | store | car_dealer | mall */
    public const BUSINESS_TYPE = 'restaurant';

    /** تسميات المواقع كما في readme §4-ب */
    public const LOCATION_SINGULAR = 'طاولة';
    public const LOCATION_PLURAL   = 'طاولات';

    /** هل يجب تحديد موقع قبل إرسال الطلب؟ (السيرفر يفرض ذلك، لا الواجهة فقط) */
    public const LOCATION_REQUIRED = true;

    /** كيف يفهم النظام مصدر الموقع: qr | picker | qr_or_picker | disabled */
    public const LOCATION_SOURCE = 'qr_or_picker';

    /** هل يمكن للزبون تبديل الموقع بنفسه أم يُثبَّت من QR فقط؟ */
    public const LOCATION_LOCKED_BY_QR = false;

    /* ------------------------- الوصول للتغطية ------------------------- */

    public static function locationSingular(): string
    {
        return (string) self::ov('location_singular', self::LOCATION_SINGULAR);
    }

    public static function locationPlural(): string
    {
        return (string) self::ov('location_plural', self::LOCATION_PLURAL);
    }

    public static function locationRequired(): bool
    {
        return (bool) self::ov('location_required', self::LOCATION_REQUIRED);
    }

    public static function locationSource(): string
    {
        return (string) self::ov('location_source', self::LOCATION_SOURCE);
    }

    /** هل نظام المواقع مفعّل لهذا المشروع؟ */
    public static function locationsEnabled(): bool
    {
        return (bool) self::feature('locations', true) && self::locationSource() !== 'disabled';
    }

    /** بادئة رموز المواقع — تحدّدها نوع النشاط المختار. */
    public static function locationCodePrefix(): string
    {
        $explicit = (string) self::ov('location_code', '');
        if ($explicit !== '') {
            return match ($explicit) {
                'restaurant' => 'T', // T = Table
                'showroom'   => 'C', // C = Car / showroom
                default      => 'L', // L = Location
            };
        }
        return match (self::BUSINESS_TYPE) {
            'car_dealer'    => 'C',
            'store', 'mall' => 'L',
            default         => 'T',
        };
    }

    /* =====================================================================
     * ج. حقول المنتجات
     * ===================================================================== */

    /**
     * الحقول الإضافية المخزّنة في products.extra_json
     *
     * الأنواع المدعومة: text | textarea | number | select | boolean | tags
     * المفاتيح: key, label, type, required, options, default, hint,
     *          show_in_card (يظهر في بطاقة المنتج), searchable, sort
     */
    public const PRODUCT_EXTRA_FIELDS = [
        [
            'key'          => 'portion',
            'label'        => 'الحجم',
            'type'         => 'text',
            'required'     => false,
            'default'      => '',
            'hint'         => 'مثال: 250 غ أو كوب كبير',
            'show_in_card' => true,
            'searchable'   => true,
            'sort'         => 10,
        ],
        [
            'key'          => 'vegetarian',
            'label'        => 'نباتي',
            'type'         => 'boolean',
            'required'     => false,
            'default'      => false,
            'show_in_card' => true,
            'searchable'   => true,
            'sort'         => 20,
        ],
        [
            'key'          => 'spicy',
            'label'        => 'حار',
            'type'         => 'boolean',
            'required'     => false,
            'default'      => false,
            'show_in_card' => true,
            'searchable'   => true,
            'sort'         => 30,
        ],
        [
            'key'          => 'calories',
            'label'        => 'السعرات',
            'type'         => 'number',
            'required'     => false,
            'default'      => '',
            'hint'         => 'بالسعرات الحرارية',
            'show_in_card' => true,
            'searchable'   => false,
            'sort'         => 40,
        ],
    ];

    /**
     * حقول صفحة التفاصيل الإضافية (تظهر في صفحة/نافذة تفاصيل المنتج).
     * تُقرأ أيضًا من extra_json — هنا نحدد ما يستحق معروضًا بالتفصيل.
     */
    public const PRODUCT_DETAIL_FIELDS = [
        [
            'key'   => 'ingredients',
            'label' => 'المكوّنات',
            'type'  => 'textarea',
            'hint'  => 'مثال: لحم، خبز، جبنة، بهارات',
        ],
    ];

    /**
     * هل يحتاج هذا النوع من المنتجات صفحة تفاصيل مستقلة؟
     * القاعدة: لا تُفتح صفحة تفاصيل إلا إذا وُجد نص طويل أو حقول إضافية.
     */
    public const PRODUCT_DETAIL_RULES = [
        'min_description_length' => 120,  // طول الوصف الذي يستحق صفحة تفاصيل
        'extra_field_threshold'  => 2,    // عدد حقول details التي تفعّل صفحة التفاصيل
        'always_detail_keys'    => ['ingredients'],
    ];

    /* =====================================================================
     * د. قواعد الطلبات
     * ===================================================================== */

    /**
     * أنواع الطلبات. كل نوع يحمل: التسمية، شرط الموقع، وحقول النموذج.
     * الحقول المدعومة: text | textarea | number | tel | select | boolean
     */
    public const ORDER_TYPES = [
        'dine_in' => [
            'label'            => 'طلب في المحل',
            'icon'             => 'dine-in',
            'requires_location' => true,
            'default'          => true,
            'description'      => 'يُخدم الطلب على الموقع المحدد (طاولة).',
            'fields'           => [
                [
                    'key'      => 'notes',
                    'label'    => 'ملاحظات للمطبخ',
                    'type'     => 'textarea',
                    'required' => false,
                    'placeholder' => 'مثال: بدون بصل، الصوص على جنب',
                    'sort'     => 30,
                ],
                [
                    'key'      => 'served_for',
                    'label'    => 'عدد الأشخاص',
                    'type'     => 'number',
                    'required' => false,
                    'min'      => 1,
                    'max'      => 40,
                    'sort'     => 40,
                ],
            ],
        ],
        'takeaway' => [
            'label'            => 'سفري',
            'icon'             => 'takeaway',
            'requires_location' => false,
            'default'          => false,
            'description'      => 'استلام سريع دون انتظار الطاولة.',
            'fields'           => [
                [
                    'key'      => 'customer_phone',
                    'label'    => 'رقم الهاتف (اختياري)',
                    'type'     => 'tel',
                    'required' => false,
                    'placeholder' => '09xxxxxxxx',
                    'sort'     => 20,
                ],
                [
                    'key'      => 'pickup_time',
                    'label'    => 'وقت الاستلام',
                    'type'     => 'text',
                    'required' => false,
                    'placeholder' => 'مثال: خلال 20 دقيقة',
                    'sort'     => 30,
                ],
                [
                    'key'      => 'notes',
                    'label'    => 'ملاحظات',
                    'type'     => 'textarea',
                    'required' => false,
                    'sort'     => 40,
                ],
            ],
        ],
    ];

    /**
     * حالات الطلب — كل حالة تنتقل إلى الحالات المسموحة (next).
     */
    public const ORDER_STATUSES = [
        'new' => [
            'label' => 'جديد',
            'color' => '#e8590c',
            'next'  => ['in_progress', 'completed', 'cancelled'],
            'initial' => true,
            'notify' => true,
        ],
        'in_progress' => [
            'label' => 'قيد التنفيذ',
            'color' => '#1c7ed6',
            'next'  => ['completed', 'cancelled'],
        ],
        'completed' => [
            'label' => 'مكتمل',
            'color' => '#2f9e44',
            'next'  => [],
            'final' => true,
        ],
        'cancelled' => [
            'label' => 'ملغى',
            'color' => '#868e96',
            'next'  => [],
            'final' => true,
        ],
    ];

    /** هل يُطلب اسم الزبون عند إتمام الطلب؟ (نعم، وبلا حساب) */
    public const CUSTOMER_NAME_REQUIRED = true;
    public const CUSTOMER_NAME_LABEL   = 'اسمك';
    public const CUSTOMER_NAME_PLACEHOLDER = 'مثال: أبو علي';

    /** هل يُطلب رقم الهاتف؟ (افتراضيًا لا، حسب readme §5-4) */
    public const CUSTOMER_PHONE_REQUIRED = false;

    /* =====================================================================
     * هـ. الوظائف المفعّلة
     * ===================================================================== */

    public const FEATURES = [
        'locations'        => true,  // نظام المواقع (طاولات/معارض)
        'qr_locations'     => true,  // توليد QR للمواقع
        'qr_orders'        => true,  // توليد QR لكل طلب
        'search'           => true,  // البحث البسيط في المنتجات
        'order_types'      => true,  // اختيار نوع الطلب
        'product_details'  => true,  // صفحة/نافذة تفاصيل المنتج
        'welcome_page'     => true,  // صفحة الترحيب
        'order_tracking'   => true,  // متابعة الطلب برمز QR
        'notes'            => true,  // ملاحظات الطلب
        'admin_logo'       => true,  // تعديل الشعار من الإدارة
        'admin_welcome'    => true,  // تعديل رسالة الترحيب من الإدارة
        'admin_contacts'   => true,  // إظهار هاتف/إنستغرام في الواجهة
        'admin_backups'    => true,  // تنزيل نسخة احتياطية من الإدارة
        'auto_refresh'     => true,  // تحديث دوري للطلبات الجديدة
    ];

    /**
     * وظائف لا معنى لها في وضع العرض فقط، فتُغلق تلقائيًا عند اختياره.
     * هذا يضمن أن "عرض فقط" لا يترك أزرار طلبات معلّقة في أي مكان.
     */
    public const ORDER_ONLY_FEATURES = [
        'order_types',
        'order_tracking',
        'qr_orders',
        'auto_refresh',
    ];

    /** مدة صلاحية رمز متابعة الطلب (بالدقائق). */
    public const ORDER_TOKEN_TTL_MINUTES = 60 * 24 * 14;

    /* =====================================================================
     * بيانات الدخول (تُولَّد في storage/config/local.php عند التهيئة)
     * ===================================================================== */

    public const ADMIN_SESSION_NAME     = 'lc_admin';
    public const ADMIN_SESSION_IDLE_MIN = 60 * 2;   // انتهاء بعد خمول
    public const ADMIN_SESSION_MAX_MIN  = 60 * 12;  // انتهاء أقصى regardless
    public const ADMIN_MAX_LOGIN_ATTEMPTS = 6;
    public const ADMIN_LOCKOUT_MINUTES   = 15;

    /* =====================================================================
     * مسارات المشروع
     * ===================================================================== */

    public static function basePath(string $append = ''): string
    {
        return rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/') . ($append !== '' ? '/' . ltrim($append, '/') : '');
    }

    public static function storagePath(string $append = ''): string
    {
        return self::basePath('storage') . ($append !== '' ? '/' . ltrim($append, '/') : '');
    }

    /** مجلد الصور العام (داخل مسار الويب، محمي من تنفيذ أي سكربت). */
    public static function publicUploadsPath(string $append = ''): string
    {
        return self::basePath('app/uploads') . ($append !== '' ? '/' . ltrim($append, '/') : '');
    }

    /**
     * جذر الموقع كما هو مرئي من المتصفح (لدعم التركيب داخل مجلد فرعي).
     * يُستنتج من SCRIPT_NAME: مثال /admin/index.php → "/"
     */
    public static function webRoot(): string
    {
        static $root = null;
        if ($root !== null) {
            return $root;
        }

        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $root   = '/';

        // في CLI تكون SCRIPT_NAME مسارًا على القرص، فنعتبر الجذر "/"
        if ($script !== '' && str_starts_with($script, '/')) {
            $dir   = rtrim(str_replace('\\', '/', dirname($script)), '/');
            $depth = substr_count(trim($dir, '/'), '/');
            $root  = $depth > 0 ? str_repeat('../', $depth) : '/';
        }

        return $root;
    }

    /** رابط الصورة كما يُطلب من المتصفح (جذر مطلق). */
    public static function uploadsUrl(string $file): string
    {
        return self::webRoot() . 'app/uploads/' . ltrim($file, '/');
    }

    /* =====================================================================
     * أدوات مساعدة للإعدادات — كل واحدة تقرأ عبر طبقة التغطية
     * ===================================================================== */

    /** @return array<int, array<string, mixed>> حقول المنتج الإضافية مرتبة */
    public static function productExtraFields(): array
    {
        $fields = (array) self::ov('product_extra_fields', self::PRODUCT_EXTRA_FIELDS);
        usort($fields, static fn(array $a, array $b): int => ($a['sort'] ?? 100) <=> ($b['sort'] ?? 100));
        return $fields;
    }

    /** @return array<int, array<string, mixed>> حقول صفحة التفاصيل */
    public static function productDetailFields(): array
    {
        return (array) self::ov('product_detail_fields', self::PRODUCT_DETAIL_FIELDS);
    }

    /** @return array<string, mixed> قواعد فتح صفحة التفاصيل */
    public static function productDetailRules(): array
    {
        return (array) self::ov('product_detail_rules', self::PRODUCT_DETAIL_RULES);
    }

    /** @return array<string, array<string, mixed>> أنواع الطلب الفعلية */
    public static function orderTypes(): array
    {
        $types = (array) self::ov('order_types', self::ORDER_TYPES);
        // وضع العرض_only لا يُعرَض له أنواع طلبات مهما كانت الثوابت
        return self::hasOrdering() ? $types : [];
    }

    /** @return array<int, array<string, mixed>> */
    public static function orderTypeList(): array
    {
        $list = [];
        foreach (self::orderTypes() as $key => $def) {
            $list[] = ['key' => $key] + $def;
        }
        return $list;
    }

    public static function orderType(string $key): ?array
    {
        $types = self::orderTypes();
        return $types[$key] ?? null;
    }

    /** الحقول الفعلية (المدمجة من الأنواع) مرتبة حسب sort */
    public static function orderFieldsFor(string $type): array
    {
        $def  = self::orderTypes()[$type] ?? [];
        $flds = $def['fields'] ?? [];
        usort($flds, static fn(array $a, array $b): int => ($a['sort'] ?? 100) <=> ($b['sort'] ?? 100));
        return $flds;
    }

    /**
     * هل الوظيفة مفعّلة؟
     * في وضع العرض فقط تُغلق كل وظائف الطلبات مهما كانت الثوابت.
     */
    public static function feature(string $name, bool $default = false): bool
    {
        $overrides = (array) self::ov('feature_overrides', []);
        if (array_key_exists($name, $overrides)) {
            return (bool) $overrides[$name];
        }
        if (!self::hasOrdering() && in_array($name, self::ORDER_ONLY_FEATURES, true)) {
            return false;
        }
        return self::FEATURES[$name] ?? $default;
    }

    /** @return array<string, bool> كل الوظائف بعد تطبيق التغطية */
    public static function features(): array
    {
        $out = [];
        foreach (array_keys(self::FEATURES) as $name) {
            $out[$name] = self::feature($name);
        }
        foreach ((array) self::ov('feature_overrides', []) as $name => $value) {
            $out[$name] = (bool) $value;
        }
        return $out;
    }

    /** هل نوع الطلب هذا يفرض وجود موقع؟ */
    public static function typeRequiresLocation(string $type): bool
    {
        $def = self::orderTypes()[$type] ?? null;
        if ($def === null) {
            return self::locationRequired();
        }
        return (bool) ($def['requires_location'] ?? self::locationRequired());
    }

    /** الإعدادات التي تُزرع في جدول settings عند أول تهيئة. */
    public static function defaultRuntimeSettings(): array
    {
        return [
            'app_name'            => self::DEFAULT_APP_NAME,
            'app_tagline'         => self::DEFAULT_APP_TAGLINE,
            'welcome_message'     => self::DEFAULT_WELCOME_MESSAGE,
            'logo_path'           => '',
            'contact_phone'       => '',
            'contact_instagram'   => '',
            'show_welcome'        => '1',
            'show_search'         => '1',
            'show_contact'        => '1',
            'show_prices'         => '1',
            'low_stock_note'      => '',
            'business_preset'     => '',   // يُملأ في صفحة التهيئة
        ];
    }
}