<?php
/**
 * BusinessPresets — أنواع النشاط الجاهزة.
 *
 * الهدف: أن يختار المستخدم في صفحة التهيئة "ماذا أعرض" بجملة واحدة، فتتغيّر
 * معه كل التسميات والوضع والخصائص تلقائيًا بدل تعديل عشرات الأسطر في الكود.
 *
 * كل نوع نشاط يحمل:
 *   mode              — orders (مع نظام طلبات) | catalog (عرض فقط)
 *   business_label    — تسمية النشاط المفردة
 *   product_*         — تسميات المنتج المفرد والجمع
 *   catalog_title     — عنوان صفحة المنتجات
 *   locations         — إعدادات المواقع (طاولات/فروع/معارض)
 *   order_types       — أنواع الطلب (فارغة في وضع العرض فقط)
 *   extra_fields      — حقول المنتج الإضافية
 *   detail_fields     — حقول صفحة التفاصيل
 *   features          — وظائف تُفعَّل أو تُعطَّل
 *
 * إضافة نوع نشاط جديد = إضافة مدخل جديد هنا فقط. لا حاجة لتعديل أي ملف آخر.
 *
 * @package App\Config
 */

declare(strict_types=1);

namespace App\Config;

final class BusinessPresets
{
    /** مشروع فيه نظام طلبات وسلة وإتمام طلب. */
    public const MODE_ORDERS = 'orders';

    /** مشروع عرض فقط: منتجات للتصفح، بلا سلة ولا طلبات. */
    public const MODE_CATALOG = 'catalog';

    /* =====================================================================
     * تعريفات الأنواع الجاهزة
     * ===================================================================== */

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            /* ---------------------- مطعم / مقهى ---------------------- */
            'restaurant' => [
                'label'           => 'مطعم أو مقهى',
                'summary'         => 'قائمة طعام، طاولات برموز QR، وطلبات من المحل أو سفيرًا',
                'icon'            => 'restaurant',
                'mode'            => self::MODE_ORDERS,
                'business_label'  => 'مطعم',
                'product_singular' => 'صنف',
                'product_plural'   => 'الأصناف',
                'catalog_title'    => 'القائمة',
                'catalog_cta'      => 'تصفّح القائمة',
                'order_cta'        => 'اطلب الآن',
                'home_hero_title'  => 'نطبخ لك ونوصلها',
                'multi_item_orders' => true,
                'locations' => [
                    'enabled'   => true,
                    'singular'  => 'طاولة',
                    'plural'    => 'طاولات',
                    'required'  => true,
                    'source'    => 'qr_or_picker',
                    'code'      => 'restaurant',
                ],
                'order_types'   => self::restaurantOrderTypes(),
                'extra_fields'  => self::foodExtraFields(),
                'detail_fields' => [
                    [
                        'key' => 'ingredients', 'label' => 'المكوّنات', 'type' => 'textarea',
                        'hint' => 'مثال: لحم، خبز، جبنة، بهارات',
                    ],
                ],
                'detail_rules' => [
                    'min_description_length' => 120,
                    'extra_field_threshold'  => 2,
                    'always_detail_keys'    => ['ingredients'],
                ],
                'features' => [
                    'locations' => true, 'order_tracking' => true, 'order_types' => true,
                ],
            ],

            /* -------------------------- متجر -------------------------- */
            'store' => [
                'label'           => 'متجر',
                'summary'         => 'عرض المنتجات مع طلب عبر السلة، توصيل أو استلام من المحل',
                'icon'            => 'store',
                'mode'            => self::MODE_ORDERS,
                'business_label'  => 'متجر',
                'product_singular' => 'منتج',
                'product_plural'   => 'المنتجات',
                'catalog_title'    => 'المنتجات',
                'catalog_cta'      => 'تصفّح المنتجات',
                'order_cta'        => 'أكمل الطلب',
                'home_hero_title'  => 'كل ما تحتاجه في مكان واحد',
                'multi_item_orders' => true,
                'locations' => [
                    'enabled'   => false,
                    'singular'  => 'فرع',
                    'plural'    => 'الفروع',
                    'required'  => false,
                    'source'    => 'disabled',
                    'code'      => 'store',
                ],
                'order_types'   => self::storeOrderTypes(),
                'extra_fields'  => self::genericExtraFields(),
                'detail_fields' => [
                    ['key' => 'specs',    'label' => 'المواصفات', 'type' => 'textarea', 'hint' => 'المقاس، المادة، اللون'],
                    ['key' => 'warranty', 'label' => 'الضمان',    'type' => 'text',     'hint' => 'مثال: سنة كاملة'],
                ],
                'detail_rules' => [
                    'min_description_length' => 140,
                    'extra_field_threshold'  => 2,
                    'always_detail_keys'    => ['specs'],
                ],
                'features' => [
                    'locations' => false, 'order_tracking' => true, 'order_types' => true,
                ],
            ],

            /* --------------------- عرض / وكالة --------------------- */
            'showroom' => [
                'label'           => 'معرض أو وكالة',
                'summary'         => 'عرض المنتجات والمواصفات دون نظام طلبات — للاستفسار عبر التواصل',
                'icon'            => 'showroom',
                'mode'            => self::MODE_CATALOG,
                'business_label'  => 'معرض',
                'product_singular' => 'مركبة',
                'product_plural'   => 'المركبات',
                'catalog_title'    => 'المعرض',
                'catalog_cta'      => 'تصفّح المعرض',
                'order_cta'        => '',
                'home_hero_title'  => 'معرض يعرض لك الخيارات كاملة',
                'multi_item_orders' => false,
                'locations' => [
                    'enabled'   => false,
                    'singular'  => 'معرض',
                    'plural'    => 'المعارض',
                    'required'  => false,
                    'source'    => 'disabled',
                    'code'      => 'showroom',
                ],
                'order_types'  => [],
                'extra_fields' => self::showroomExtraFields(),
                'detail_fields' => [
                    [
                        'key' => 'condition', 'label' => 'الحالة', 'type' => 'select',
                        'options' => [
                            ['value' => 'new',    'label' => 'جديدة'],
                            ['value' => 'used',   'label' => 'مستعملة'],
                            ['value' => 'refurb', 'label' => 'مجدّدة'],
                        ],
                    ],
                    ['key' => 'year',     'label' => 'سنة الصنع', 'type' => 'number'],
                    ['key' => 'mileage',  'label' => 'المقطوعة',  'type' => 'text', 'hint' => 'مثال: 45,000 كم'],
                    ['key' => 'features', 'label' => 'المواصفات', 'type' => 'textarea'],
                ],
                'detail_rules' => [
                    'min_description_length' => 60,
                    'extra_field_threshold'  => 2,
                    'always_detail_keys'    => ['features'],
                ],
                'features' => [
                    'locations' => false, 'order_tracking' => false, 'order_types' => false,
                ],
            ],

            /* ------------------------- عيادة ------------------------- */
            'clinic' => [
                'label'           => 'عيادة أو مركز صحي',
                'summary'         => 'عرض الخدمات مع حجز موعد مباشرة',
                'icon'            => 'clinic',
                'mode'            => self::MODE_ORDERS,
                'business_label'  => 'العيادة',
                'product_singular' => 'خدمة',
                'product_plural'   => 'الخدمات',
                'catalog_title'    => 'الخدمات',
                'catalog_cta'      => 'تصفّح الخدمات',
                'order_cta'        => 'احجز موعدك',
                'home_hero_title'  => 'رعايتكم تبدأ من هنا',
                'multi_item_orders' => false,
                'locations' => [
                    'enabled'   => false,
                    'singular'  => 'فرع',
                    'plural'    => 'الفروع',
                    'required'  => false,
                    'source'    => 'disabled',
                    'code'      => 'store',
                ],
                'order_types'  => self::clinicOrderTypes(),
                'extra_fields' => [
                    [
                        'key' => 'duration', 'label' => 'مدة الجلسة', 'type' => 'text',
                        'required' => false, 'default' => '', 'hint' => 'مثال: 45 دقيقة',
                        'show_in_card' => true, 'searchable' => true, 'sort' => 10,
                    ],
                    [
                        'key' => 'specialist', 'label' => 'الاختصاص', 'type' => 'text',
                        'required' => false, 'default' => '', 'hint' => 'اسم الطبيب أو القسم',
                        'show_in_card' => true, 'searchable' => true, 'sort' => 20,
                    ],
                ],
                'detail_fields' => [
                    ['key' => 'preparation', 'label' => 'التحضير اللازم', 'type' => 'textarea'],
                    ['key' => 'includes',     'label' => 'تشمل الخدمة',  'type' => 'textarea'],
                ],
                'detail_rules' => [
                    'min_description_length' => 120,
                    'extra_field_threshold'  => 2,
                    'always_detail_keys'    => ['includes'],
                ],
                'features' => [
                    'locations' => false, 'order_tracking' => false, 'order_types' => true,
                ],
            ],

            /* ------------------- استوديو / خدمات ------------------- */
            'studio' => [
                'label'           => 'استوديو أو ورشة',
                'summary'         => 'عرض الأعمال والخدمات مع طلب مباشر من الزبون',
                'icon'            => 'studio',
                'mode'            => self::MODE_ORDERS,
                'business_label'  => 'الاستوديو',
                'product_singular' => 'خدمة',
                'product_plural'   => 'الخدمات',
                'catalog_title'    => 'الأعمال والخدمات',
                'catalog_cta'      => 'تصفّح الأعمال',
                'order_cta'        => 'اطلب الخدمة',
                'home_hero_title'  => 'نحوّل فكرتك إلى واقع',
                'multi_item_orders' => false,
                'locations' => [
                    'enabled'   => false,
                    'singular'  => 'مقر',
                    'plural'    => 'المقرات',
                    'required'  => false,
                    'source'    => 'disabled',
                    'code'      => 'store',
                ],
                'order_types'   => self::studioOrderTypes(),
                'extra_fields'  => self::genericExtraFields(),
                'detail_fields' => [
                    ['key' => 'includes', 'label' => 'ماذا تشمل الخدمة', 'type' => 'textarea'],
                    ['key' => 'duration', 'label' => 'مدة التنفيذ',      'type' => 'text', 'hint' => 'مثال: 7 أيام عمل'],
                ],
                'detail_rules' => [
                    'min_description_length' => 110,
                    'extra_field_threshold'  => 2,
                    'always_detail_keys'    => ['includes'],
                ],
                'features' => [
                    'locations' => false, 'order_tracking' => true, 'order_types' => true,
                ],
            ],

            /* ------------------- عام (عرض فقط) ------------------- */
            'general' => [
                'label'           => 'عام — عرض فقط',
                'summary'         => 'موقع تعريفي يعرض المنتجات والصور وبيانات التواصل بلا نظام طلبات',
                'icon'            => 'general',
                'mode'            => self::MODE_CATALOG,
                'business_label'  => 'المؤسسة',
                'product_singular' => 'منتج',
                'product_plural'   => 'المنتجات',
                'catalog_title'    => 'المنتجات',
                'catalog_cta'      => 'تصفّح المنتجات',
                'order_cta'        => '',
                'home_hero_title'  => 'نقدّم لكم ما نمتاز به',
                'multi_item_orders' => false,
                'locations' => [
                    'enabled'   => false,
                    'singular'  => 'فرع',
                    'plural'    => 'الفروع',
                    'required'  => false,
                    'source'    => 'disabled',
                    'code'      => 'store',
                ],
                'order_types'   => [],
                'extra_fields'  => self::genericExtraFields(),
                'detail_fields' => [
                    ['key' => 'specs', 'label' => 'المواصفات', 'type' => 'textarea'],
                ],
                'detail_rules' => [
                    'min_description_length' => 140,
                    'extra_field_threshold'  => 2,
                    'always_detail_keys'    => ['specs'],
                ],
                'features' => [
                    'locations' => false, 'order_tracking' => false, 'order_types' => false,
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function get(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    public static function exists(string $id): bool
    {
        return isset(self::all()[$id]);
    }

    /** أول عنصر في القائمة — الافتراضي في صفحة التهيئة. */
    public static function defaultId(): string
    {
        return 'general';
    }

    /**
     * يحوّل مدخل نوع النشاط إلى حزمة تغطية (overrides) لـ ProjectConfig.
     * القيم الفارغة تُحذف حتى لا تُطمس ثوابت ProjectConfig.
     *
     * @return array<string, mixed>
     */
    public static function toOverrides(string $id): array
    {
        $preset = self::get($id);
        if ($preset === null) {
            $preset = self::get(self::defaultId()) ?? [];
        }

        $loc = (array) ($preset['locations'] ?? []);

        $overrides = [
            'preset_id'            => $id,
            'preset_label'         => (string) ($preset['label'] ?? 'عام'),
            'mode'                 => (string) ($preset['mode'] ?? self::MODE_CATALOG),
            'business_label'       => (string) ($preset['business_label'] ?? ''),
            'product_singular'     => (string) ($preset['product_singular'] ?? ''),
            'product_plural'       => (string) ($preset['product_plural'] ?? ''),
            'catalog_title'        => (string) ($preset['catalog_title'] ?? ''),
            'catalog_cta'          => (string) ($preset['catalog_cta'] ?? ''),
            'order_cta'            => (string) ($preset['order_cta'] ?? ''),
            'home_hero_title'      => (string) ($preset['home_hero_title'] ?? ''),
            'multi_item_orders'    => (bool) ($preset['multi_item_orders'] ?? false),
            'location_enabled'     => (bool) ($loc['enabled'] ?? false),
            'location_singular'    => (string) ($loc['singular'] ?? ''),
            'location_plural'      => (string) ($loc['plural'] ?? ''),
            'location_required'    => (bool) ($loc['required'] ?? false),
            'location_source'      => (string) ($loc['source'] ?? 'disabled'),
            'location_code'        => (string) ($loc['code'] ?? 'store'),
            'order_types'          => (array) ($preset['order_types'] ?? []),
            'product_extra_fields' => (array) ($preset['extra_fields'] ?? []),
            'product_detail_fields' => (array) ($preset['detail_fields'] ?? []),
            'product_detail_rules' => (array) ($preset['detail_rules'] ?? []),
            'feature_overrides'    => (array) ($preset['features'] ?? []),
        ];

        // لا نُبقي القيم الفارغة: ProjectConfig يبقى المرجع لما لم يُحدَّد
        foreach ($overrides as $key => $value) {
            if ($value === '' || $value === []) {
                unset($overrides[$key]);
            }
        }

        return $overrides;
    }

    /* =====================================================================
     * أنواع الطلبات
     * ===================================================================== */

    /** @return array<string, array<string, mixed>> */
    private static function restaurantOrderTypes(): array
    {
        return [
            'dine_in' => [
                'label'       => 'في المحل',
                'icon'        => 'dine-in',
                'default'     => true,
                'requires_location' => true,
                'description' => 'يُخدم الطلب على الطاولة المحددة.',
                'fields' => [
                    [
                        'key' => 'notes', 'label' => 'ملاحظات للمطبخ', 'type' => 'textarea',
                        'required' => false, 'sort' => 30,
                    ],
                    [
                        'key' => 'served_for', 'label' => 'عدد الأشخاص', 'type' => 'number',
                        'required' => false, 'min' => 1, 'max' => 40, 'sort' => 40,
                    ],
                ],
            ],
            'takeaway' => [
                'label'       => 'سفري',
                'icon'        => 'takeaway',
                'default'     => false,
                'requires_location' => false,
                'description' => 'استلام سريع دون انتظار الطاولة.',
                'fields' => [
                    [
                        'key' => 'customer_phone', 'label' => 'رقم الهاتف', 'type' => 'tel',
                        'required' => false, 'placeholder' => '09xxxxxxxx', 'sort' => 20,
                    ],
                    [
                        'key' => 'pickup_time', 'label' => 'وقت الاستلام', 'type' => 'text',
                        'required' => false, 'placeholder' => 'خلال 20 دقيقة', 'sort' => 30,
                    ],
                    ['key' => 'notes', 'label' => 'ملاحظات', 'type' => 'textarea', 'required' => false, 'sort' => 40],
                ],
            ],
            'delivery' => [
                'label'       => 'توصيل',
                'icon'        => 'delivery',
                'default'     => false,
                'requires_location' => false,
                'description' => 'توصيل إلى العنوان الذي تحدده.',
                'fields' => [
                    [
                        'key' => 'customer_phone', 'label' => 'رقم الهاتف', 'type' => 'tel',
                        'required' => true, 'placeholder' => '09xxxxxxxx', 'sort' => 20,
                    ],
                    ['key' => 'address', 'label' => 'العنوان', 'type' => 'textarea', 'required' => true, 'sort' => 30],
                    ['key' => 'notes', 'label' => 'ملاحظات', 'type' => 'textarea', 'required' => false, 'sort' => 40],
                ],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function storeOrderTypes(): array
    {
        return [
            'delivery' => [
                'label'       => 'توصيل',
                'icon'        => 'delivery',
                'default'     => true,
                'requires_location' => false,
                'description' => 'نوصّل الطلب إلى عنوانك.',
                'fields' => [
                    [
                        'key' => 'customer_phone', 'label' => 'رقم الهاتف', 'type' => 'tel',
                        'required' => true, 'placeholder' => '09xxxxxxxx', 'sort' => 20,
                    ],
                    ['key' => 'address', 'label' => 'العنوان', 'type' => 'textarea', 'required' => true, 'sort' => 30],
                    ['key' => 'notes', 'label' => 'ملاحظات', 'type' => 'textarea', 'required' => false, 'sort' => 40],
                ],
            ],
            'pickup' => [
                'label'       => 'استلام من المحل',
                'icon'        => 'takeaway',
                'default'     => false,
                'requires_location' => false,
                'description' => 'نجهّز الطلب ثم تستلمه من المحل.',
                'fields' => [
                    [
                        'key' => 'customer_phone', 'label' => 'رقم الهاتف', 'type' => 'tel',
                        'required' => true, 'placeholder' => '09xxxxxxxx', 'sort' => 20,
                    ],
                    [
                        'key' => 'pickup_time', 'label' => 'وقت الاستلام', 'type' => 'text',
                        'required' => false, 'sort' => 30,
                    ],
                    ['key' => 'notes', 'label' => 'ملاحظات', 'type' => 'textarea', 'required' => false, 'sort' => 40],
                ],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function clinicOrderTypes(): array
    {
        return [
            'visit' => [
                'label'       => 'حجز موعد',
                'icon'        => 'calendar',
                'default'     => true,
                'requires_location' => false,
                'description' => 'اختر الموعد المناسب وسيتم تأكيده هاتفيًا.',
                'fields' => [
                    [
                        'key' => 'customer_phone', 'label' => 'رقم الهاتف', 'type' => 'tel',
                        'required' => true, 'placeholder' => '09xxxxxxxx', 'sort' => 20,
                    ],
                    [
                        'key' => 'appointment_date', 'label' => 'التاريخ المطلوب', 'type' => 'text',
                        'required' => true, 'placeholder' => 'مثال: 2026-11-04', 'sort' => 30,
                    ],
                    [
                        'key' => 'appointment_time', 'label' => 'الوقت المناسب', 'type' => 'text',
                        'required' => false, 'placeholder' => 'مثال: بعد الساعة 4', 'sort' => 40,
                    ],
                    ['key' => 'notes', 'label' => 'ملاحظات', 'type' => 'textarea', 'required' => false, 'sort' => 50],
                ],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function studioOrderTypes(): array
    {
        return [
            'request' => [
                'label'       => 'طلب الخدمة',
                'icon'        => 'service',
                'default'     => true,
                'requires_location' => false,
                'description' => 'صف ما تحتاج وسنعاود الاتصال بك بالتفاصيل.',
                'fields' => [
                    [
                        'key' => 'customer_phone', 'label' => 'رقم الهاتف', 'type' => 'tel',
                        'required' => true, 'placeholder' => '09xxxxxxxx', 'sort' => 20,
                    ],
                    [
                        'key' => 'preferred_date', 'label' => 'التاريخ المفضّل', 'type' => 'text',
                        'required' => false, 'sort' => 30,
                    ],
                    [
                        'key' => 'notes', 'label' => 'تفاصيل الطلب', 'type' => 'textarea',
                        'required' => true, 'sort' => 40,
                    ],
                ],
            ],
        ];
    }

    /* =====================================================================
     * حقول المنتجات
     * ===================================================================== */

    /** @return array<int, array<string, mixed>> */
    private static function foodExtraFields(): array
    {
        return [
            [
                'key' => 'portion', 'label' => 'الحجم', 'type' => 'text',
                'required' => false, 'default' => '', 'hint' => 'مثال: 250 غ أو كوب كبير',
                'show_in_card' => true, 'searchable' => true, 'sort' => 10,
            ],
            [
                'key' => 'vegetarian', 'label' => 'نباتي', 'type' => 'boolean',
                'required' => false, 'default' => false, 'hint' => '',
                'show_in_card' => true, 'searchable' => true, 'sort' => 20,
            ],
            [
                'key' => 'spicy', 'label' => 'حار', 'type' => 'boolean',
                'required' => false, 'default' => false, 'hint' => '',
                'show_in_card' => true, 'searchable' => true, 'sort' => 30,
            ],
            [
                'key' => 'calories', 'label' => 'السعرات', 'type' => 'number',
                'required' => false, 'default' => '', 'hint' => 'بالسعرات الحرارية',
                'show_in_card' => true, 'searchable' => false, 'sort' => 40,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function genericExtraFields(): array
    {
        return [
            [
                'key' => 'sku', 'label' => 'الرمز', 'type' => 'text',
                'required' => false, 'default' => '', 'hint' => 'رقم أو رمز مرجعي',
                'show_in_card' => true, 'searchable' => true, 'sort' => 10,
            ],
            [
                'key' => 'brand', 'label' => 'العلامة', 'type' => 'text',
                'required' => false, 'default' => '', 'hint' => 'اسم الشركة المصنّعة',
                'show_in_card' => true, 'searchable' => true, 'sort' => 20,
            ],
            [
                'key' => 'featured', 'label' => 'مميّز', 'type' => 'boolean',
                'required' => false, 'default' => false, 'hint' => '',
                'show_in_card' => true, 'searchable' => true, 'sort' => 30,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function showroomExtraFields(): array
    {
        return [
            [
                'key' => 'year', 'label' => 'السنة', 'type' => 'number',
                'required' => false, 'default' => '', 'hint' => 'سنة الصنع',
                'show_in_card' => true, 'searchable' => true, 'sort' => 10,
            ],
            [
                'key' => 'mileage', 'label' => 'المقطوعة', 'type' => 'text',
                'required' => false, 'default' => '', 'hint' => 'مثال: 45,000 كم',
                'show_in_card' => true, 'searchable' => true, 'sort' => 20,
            ],
            [
                'key' => 'featured', 'label' => 'مميّز', 'type' => 'boolean',
                'required' => false, 'default' => false, 'hint' => '',
                'show_in_card' => true, 'searchable' => true, 'sort' => 30,
            ],
        ];
    }
}