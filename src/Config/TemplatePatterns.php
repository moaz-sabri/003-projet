<?php
/**
 * TemplatePatterns — أنماط القدرات (الخدمة) لا أنواع الأنشطة (المجال).
 *
 * لماذا هذا الملف؟
 *   كان الاختيار الأول في التهيئة هو «مطعم / متجر / معرض». هذا يخبر عن المجال
 *   لا عن ما يستطيعه الموقع: مطعمان قد يحتاج أحدهما طاولات، والآخر توصيلًا فقط.
 *   هنا يصبح الاختيار **نمطًا**: ماذا يتيح القالب فعلًا؟
 *
 *       هل هناك طلبات؟            none | cart | single | reservation
 *       هل هناك مواقع/طاولات؟     none | optional | required
 *       هل للعنصر صفحة تفاصيل؟    none | page | gallery
 *       هل الصورة واحدة؟          single | gallery
 *       هل الطلب متعدد العناصر؟   نعم | لا
 *       هل نتابع الطلب برمز؟      نعم | لا
 *
 *   وكل ما يبنيه المستخدم من إجابات يُحفظ في settings باسم template_blueprint،
 *   فيبقى سلوك النسخة ثابتًا حتى لو تغيّرت الأنماط الجاهزة في إصدار لاحق.
 *
 * العلاقة مع BusinessPresets:
 *   BusinessPresets بقي كما هو للتوافق مع النسخ القديمة (restaurant / store…).
 *   هذا الملف هو المصدر الجديد، ويحوّل المعرّفات القديمة تلقائيًا إلى نمط
 *   قدرات مقابل. إضافة نمط جديد = إضافة مدخل في all() فقط.
 *
 * @package App\Config
 */

declare(strict_types=1);

namespace App\Config;

final class TemplatePatterns
{
    /* =====================================================================
     * قيم القدرات — مجموعات مغلقة
     * ===================================================================== */

    public const MODE_ORDERS  = 'orders';
    public const MODE_CATALOG = 'catalog';

    public const ORDER_NONE        = 'none';
    public const ORDER_CART        = 'cart';
    public const ORDER_SINGLE      = 'single';
    public const ORDER_RESERVATION = 'reservation';

    public const PLACE_NONE     = 'none';
    public const PLACE_OPTIONAL = 'optional';
    public const PLACE_REQUIRED = 'required';

    public const DETAIL_NONE    = 'none';
    public const DETAIL_PAGE    = 'page';
    public const DETAIL_GALLERY = 'gallery';

    public const MEDIA_SINGLE  = 'single';
    public const MEDIA_GALLERY = 'gallery';

    public const ORDERINGS = [
        self::ORDER_NONE, self::ORDER_CART, self::ORDER_SINGLE, self::ORDER_RESERVATION,
    ];

    public const PLACES = [
        self::PLACE_NONE, self::PLACE_OPTIONAL, self::PLACE_REQUIRED,
    ];

    public const DETAILS = [
        self::DETAIL_NONE, self::DETAIL_PAGE, self::DETAIL_GALLERY,
    ];

    public const MEDIA = [
        self::MEDIA_SINGLE, self::MEDIA_GALLERY,
    ];

    /* =====================================================================
     * الأنماط الستة + نمط مخصص
     * ===================================================================== */

    /**
     * الأنماط الجاهزة. المفاتيح هي ما يُحفظ في business_preset.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'orders_tables' => [
                'label'   => 'طلبات وطاولات',
                'summary' => 'سلة، مواقع أو طاولات برموز QR، ومتابعة الطلب لمن يخدم في المكان.',
                'icon'    => '🍽️',
                'blueprint' => [
                    'ordering'    => self::ORDER_CART,
                    'multi_items' => true,
                    'places'      => self::PLACE_REQUIRED,
                    'reservation' => false,
                    'tracking'    => true,
                    'details'     => self::DETAIL_GALLERY,
                    'media'       => self::MEDIA_GALLERY,
                ],
                'vocabulary' => [
                    'singular'      => 'عنصر',
                    'plural'        => 'العناصر',
                    'catalog_title' => 'القائمة',
                    'catalog_cta'   => 'تصفّح القائمة',
                    'order_cta'     => 'اطلب الآن',
                    'hero_title'    => 'اطلب من مكانك مباشرة',
                ],
            ],

            'orders' => [
                'label'   => 'طلبات فقط',
                'summary' => 'سلة وطلبات متعددة، توصيل أو استلام، بلا مواقع ولا طاولات.',
                'icon'    => '🛍️',
                'blueprint' => [
                    'ordering'    => self::ORDER_CART,
                    'multi_items' => true,
                    'places'      => self::PLACE_NONE,
                    'reservation' => false,
                    'tracking'    => true,
                    'details'     => self::DETAIL_PAGE,
                    'media'       => self::MEDIA_GALLERY,
                ],
                'vocabulary' => [
                    'singular'      => 'عنصر',
                    'plural'        => 'العناصر',
                    'catalog_title' => 'الكتالوج',
                    'catalog_cta'   => 'تصفّح العناصر',
                    'order_cta'     => 'اطلب الآن',
                    'hero_title'    => 'اطلب ما تحتاجه بسهولة',
                ],
            ],

            'reservations' => [
                'label'   => 'حجوزات ومواعيد',
                'summary' => 'خدمات تُعرض ويُحجز موعدها مباشرة، بلا سلة متعددة.',
                'icon'    => '📅',
                'blueprint' => [
                    'ordering'    => self::ORDER_RESERVATION,
                    'multi_items' => false,
                    'places'      => self::PLACE_NONE,
                    'reservation' => true,
                    'tracking'    => false,
                    'details'     => self::DETAIL_PAGE,
                    'media'       => self::MEDIA_GALLERY,
                ],
                'vocabulary' => [
                    'singular'      => 'خدمة',
                    'plural'        => 'الخدمات',
                    'catalog_title' => 'الخدمات',
                    'catalog_cta'   => 'تصفّح الخدمات',
                    'order_cta'     => 'احجز موعدك',
                    'hero_title'    => 'احجز موعدك في دقيقة',
                ],
            ],

            'showcase' => [
                'label'   => 'عرض فقط',
                'summary' => 'معرض بطاقات بسيط: صورة واسم ووصف قصير، بلا تفاصيل ولا طلبات.',
                'icon'    => '🖼️',
                'blueprint' => [
                    'ordering'    => self::ORDER_NONE,
                    'multi_items' => false,
                    'places'      => self::PLACE_NONE,
                    'reservation' => false,
                    'tracking'    => false,
                    'details'     => self::DETAIL_NONE,
                    'media'       => self::MEDIA_SINGLE,
                ],
                'vocabulary' => [
                    'singular'      => 'عمل',
                    'plural'        => 'الأعمال',
                    'catalog_title' => 'المعروضات',
                    'catalog_cta'   => 'تصفّح المعروضات',
                    'order_cta'     => '',
                    'hero_title'    => 'تعرّف على ما نقدّمه',
                ],
            ],

            'showcase_details' => [
                'label'   => 'عرض مع تفاصيل',
                'summary' => 'المعروضات مع صفحة تفاصيل ومعرض صور، والاستفسار عبر التواصل.',
                'icon'    => '🔎',
                'blueprint' => [
                    'ordering'    => self::ORDER_NONE,
                    'multi_items' => false,
                    'places'      => self::PLACE_NONE,
                    'reservation' => false,
                    'tracking'    => false,
                    'details'     => self::DETAIL_GALLERY,
                    'media'       => self::MEDIA_GALLERY,
                ],
                'vocabulary' => [
                    'singular'      => 'عنصر',
                    'plural'        => 'العناصر',
                    'catalog_title' => 'المعروضات',
                    'catalog_cta'   => 'تصفّح المعروضات',
                    'order_cta'     => '',
                    'hero_title'    => 'تفاصيل كاملة قبل أن تقرر',
                ],
            ],

            'showcase_order' => [
                'label'   => 'عرض وتفاصيل وطلب لمرة واحدة',
                'summary' => 'كل التفاصيل والمعرض، ثم طلب عنصر واحد فقط في المرة.',
                'icon'    => '🎯',
                'blueprint' => [
                    'ordering'    => self::ORDER_SINGLE,
                    'multi_items' => false,
                    'places'      => self::PLACE_NONE,
                    'reservation' => false,
                    'tracking'    => true,
                    'details'     => self::DETAIL_GALLERY,
                    'media'       => self::MEDIA_GALLERY,
                ],
                'vocabulary' => [
                    'singular'      => 'منتج',
                    'plural'        => 'المنتجات',
                    'catalog_title' => 'المنتجات',
                    'catalog_cta'   => 'تصفّح المنتجات',
                    'order_cta'     => 'اطلب هذا العنصر',
                    'hero_title'    => 'اختر، اقرأ التفاصيل، ثم اطلب',
                ],
            ],

            'custom' => [
                'label'   => 'نمط مخصص',
                'summary' => 'ابنِ النمط بنفسك: طلبات، حجوزات، مواقع، تفاصيل، ومعرض صور.',
                'icon'    => '🧩',
                'blueprint' => [
                    'ordering'    => self::ORDER_NONE,
                    'multi_items' => false,
                    'places'      => self::PLACE_NONE,
                    'reservation' => false,
                    'tracking'    => false,
                    'details'     => self::DETAIL_PAGE,
                    'media'       => self::MEDIA_SINGLE,
                ],
                'vocabulary' => [
                    'singular'      => 'عنصر',
                    'plural'        => 'العناصر',
                    'catalog_title' => 'المعروضات',
                    'catalog_cta'   => 'تصفّح المعروضات',
                    'order_cta'     => 'اطلب الآن',
                    'hero_title'    => 'مرحبًا بكم',
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

    /** النمط الافتراضي في التهيئة: عرض مع تفاصيل — أكثر نمط آمن لأي كيان. */
    public static function defaultId(): string
    {
        return 'showcase_details';
    }

    /**
     * أسماء الأنماط القديمة (المجال) ومقابلها من القدرات.
     * تُستخدم فقط للنسخ التي ما زالت تحمل restaurant/store…
     *
     * @return array<string, string>
     */
    public static function legacyPatternMap(): array
    {
        return [
            'restaurant' => 'orders_tables',
            'store'      => 'orders',
            'clinic'     => 'reservations',
            'studio'     => 'showcase_order',
            'showroom'   => 'showcase_details',
            'general'    => 'showcase_details',
        ];
    }

    /* =====================================================================
     * التطبيع والحفظ
     * ===================================================================== */

    /**
     * يطبّع إجابات البناء (من POST أو من settings) إلى blueprint مغلق.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeBlueprint(array $input): array
    {
        $ordering = self::choice($input['ordering'] ?? '', self::ORDERINGS, self::ORDER_NONE);
        $places   = self::choice($input['places'] ?? '', self::PLACES, self::PLACE_NONE);
        $details  = self::choice($input['details'] ?? '', self::DETAILS, self::DETAIL_NONE);
        $media    = self::choice($input['media'] ?? '', self::MEDIA, self::MEDIA_SINGLE);

        $reservation = self::boolish($input['reservation'] ?? false)
            || $ordering === self::ORDER_RESERVATION;

        if ($reservation) {
            $ordering = self::ORDER_RESERVATION;
        }

        /*
         * التفاصيل والمعرض قدرتان متلازمتان: معرض بلا صفحة تفاصيل لا يُعرض
         * أصلًا، وتفاصيل «مع معرض» بلا معرض وعد ناقص. نُصلح الاختيارين
         * المتعارضين بدل تمرير نمط غير قابل للتنفيذ.
         */
        if ($details === self::DETAIL_GALLERY) {
            $media = self::MEDIA_GALLERY;
        } elseif ($media === self::MEDIA_GALLERY && $details === self::DETAIL_NONE) {
            $details = self::DETAIL_PAGE;
        }

        return [
            'ordering'    => $ordering,
            'multi_items' => self::boolish($input['multi_items'] ?? false)
                && $ordering === self::ORDER_CART,
            'places'      => $places,
            'reservation' => $reservation,
            'tracking'    => self::boolish($input['tracking'] ?? false)
                && in_array($ordering, [self::ORDER_CART, self::ORDER_SINGLE], true),
            'details'     => $details,
            'media'       => $media,
        ];
    }

    /**
     * يقرأ إجابات لوحة التهيئة ويحوّلها إلى نموذج مغلق.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function answersFromRequest(array $post): array
    {
        return [
            'ordering'      => (string) ($post['pattern_ordering'] ?? ''),
            'places'        => (string) ($post['pattern_places'] ?? ''),
            'details'       => (string) ($post['pattern_details'] ?? ''),
            'media'         => (string) ($post['pattern_media'] ?? ''),
            'multi_items'   => !empty($post['pattern_multi']),
            'reservation'   => (string) ($post['pattern_ordering'] ?? '') === self::ORDER_RESERVATION,
            'tracking'      => !empty($post['pattern_tracking']),
            'singular'      => mb_substr(trim((string) ($post['pattern_singular'] ?? '')), 0, 40),
            'plural'        => mb_substr(trim((string) ($post['pattern_plural'] ?? '')), 0, 60),
            'catalog_title' => mb_substr(trim((string) ($post['pattern_catalog_title'] ?? '')), 0, 60),
            'order_cta'     => mb_substr(trim((string) ($post['pattern_order_cta'] ?? '')), 0, 60),
            'hero_title'    => mb_substr(trim((string) ($post['pattern_hero_title'] ?? '')), 0, 120),
        ];
    }

    /**
     * يحوّل معرّف نمط (أو معرّف نشاط قديم) إلى حزمة overrides لـ ProjectConfig.
     *
     * @param array<string, mixed> $answers إجابات إضافية (تُستخدم مع custom أو لتعديل نمط)
     * @return array<string, mixed>
     */
    public static function toOverrides(string $id, array $answers = []): array
    {
        // التوافق الخلفي: الأنشطة القديمة تُترجم عبر طبقتها الأصلية.
        if (BusinessPresets::exists($id)) {
            $overrides = BusinessPresets::toOverrides($id);
            $overrides['template_blueprint'] = self::blueprintFromOverrides($overrides);
            $overrides['pattern_label'] = $overrides['preset_label'] ?? $id;
            return $overrides;
        }

        $pattern = self::get($id);
        if ($pattern === null) {
            $id      = self::defaultId();
            $pattern = self::get($id) ?? [];
        }

        $blueprint = self::normalizeBlueprint(array_merge(
            (array) ($pattern['blueprint'] ?? []),
            self::onlyCapabilities($answers)
        ));

        $vocabulary = self::vocabulary($pattern, $answers);
        $overrides  = self::buildOverrides($id, (string) ($pattern['label'] ?? $id), $blueprint, $vocabulary);
        $overrides['template_blueprint'] = $blueprint;

        return $overrides;
    }

    /**
     * يبني overrides من blueprint محفوظ مسبقًا (بعد ترقية القالب مثلًا).
     *
     * @param array<string, mixed> $blueprint
     * @param array<string, mixed> $vocabulary
     * @return array<string, mixed>
     */
    public static function fromBlueprint(array $blueprint, array $vocabulary = [], string $label = ''): array
    {
        $bp = self::normalizeBlueprint($blueprint);
        return self::buildOverrides(
            'custom',
            $label !== '' ? $label : 'نمط مخصص',
            $bp,
            self::vocabulary(['vocabulary' => $vocabulary], $vocabulary)
        ) + ['template_blueprint' => $bp];
    }

    /* =====================================================================
     * الوصف للعرض في التهيئة ولوحة الإدارة
     * ===================================================================== */

    /** جملة قصيرة تصف ما ستحصل عليه من blueprint. */
    public static function describe(array $blueprint): string
    {
        $bp = self::normalizeBlueprint($blueprint);
        $parts = [];

        $parts[] = match ($bp['ordering']) {
            self::ORDER_CART    => $bp['multi_items'] ? 'سلة وطلبات متعددة' : 'سلة وطلب عنصر واحد',
            self::ORDER_SINGLE  => 'طلب عنصر واحد',
            self::ORDER_RESERVATION => 'حجوزات ومواعيد',
            default             => 'عرض فقط بلا طلبات',
        };

        if ($bp['places'] === self::PLACE_REQUIRED) {
            $parts[] = 'مواقع/طاولات إلزامية برموز QR';
        } elseif ($bp['places'] === self::PLACE_OPTIONAL) {
            $parts[] = 'مواقع اختيارية';
        }

        if ($bp['details'] === self::DETAIL_GALLERY) {
            $parts[] = 'صفحة تفاصيل ومعرض صور';
        } elseif ($bp['details'] === self::DETAIL_PAGE) {
            $parts[] = 'صفحة تفاصيل';
        } else {
            $parts[] = 'بطاقة مختصرة فقط';
        }

        // «تفاصيل + معرض» يغطّي المعرض أصلًا، فلا نكرّره في الوصف.
        if ($bp['media'] === self::MEDIA_GALLERY && $bp['details'] !== self::DETAIL_GALLERY) {
            $parts[] = 'معرض صور للعنصر';
        }

        if ($bp['tracking']) {
            $parts[] = 'متابعة الطلب برمز';
        }

        return implode(' · ', $parts);
    }

    /** @return array<string, mixed> الوصف الكامل لنمط جاهز */
    public static function describeId(string $id): array
    {
        $pattern = self::get($id);
        if ($pattern === null) {
            return ['label' => $id, 'summary' => '', 'result' => ''];
        }
        return [
            'label'   => (string) ($pattern['label'] ?? $id),
            'summary' => (string) ($pattern['summary'] ?? ''),
            'result'  => self::describe((array) ($pattern['blueprint'] ?? [])),
        ];
    }

    /* =====================================================================
     * البناء الداخلي
     * ===================================================================== */

    /**
     * @param array<string, mixed> $pattern
     * @param array<string, mixed> $answers
     * @return array<string, string>
     */
    private static function vocabulary(array $pattern, array $answers): array
    {
        $base = (array) ($pattern['vocabulary'] ?? []);
        $pick = static function (string $key, string $default) use ($base, $answers): string {
            $value = trim((string) ($answers[$key] ?? ''));
            if ($value === '') {
                $value = trim((string) ($base[$key] ?? ''));
            }
            return $value !== '' ? $value : $default;
        };

        return [
            'singular'      => $pick('singular', 'عنصر'),
            'plural'        => $pick('plural', 'العناصر'),
            'catalog_title' => $pick('catalog_title', 'المعروضات'),
            'catalog_cta'   => $pick('catalog_cta', 'تصفّح المعروضات'),
            'order_cta'     => $pick('order_cta', 'اطلب الآن'),
            'hero_title'    => $pick('hero_title', 'مرحبًا بكم'),
        ];
    }

    /**
     * @param array<string, mixed> $blueprint
     * @param array<string, string> $vocabulary
     * @return array<string, mixed>
     */
    private static function buildOverrides(string $id, string $label, array $blueprint, array $vocabulary): array
    {
        $ordering = $blueprint['ordering'];
        $mode     = $ordering === self::ORDER_NONE ? self::MODE_CATALOG : self::MODE_ORDERS;
        $orderingOn = $ordering !== self::ORDER_NONE;
        $placesOn   = $blueprint['places'] !== self::PLACE_NONE;
        $detailsOn  = $blueprint['details'] !== self::DETAIL_NONE;
        $galleryOn  = $blueprint['media'] === self::MEDIA_GALLERY;

        $locationSource = match ($blueprint['places']) {
            self::PLACE_REQUIRED => 'qr_or_picker',
            self::PLACE_OPTIONAL => 'picker',
            default              => 'disabled',
        };

        $features = [
            'locations'       => $placesOn,
            'qr_locations'    => $blueprint['places'] === self::PLACE_REQUIRED,
            'search'          => true,
            'order_types'     => $orderingOn,
            'product_details' => $detailsOn,
            'product_gallery' => $galleryOn,
            'welcome_page'    => false,
            'order_tracking'  => $orderingOn && $blueprint['tracking'],
            'qr_orders'       => $orderingOn && $blueprint['tracking'],
            'notes'           => $orderingOn,
            'auto_refresh'    => $orderingOn,
            'admin_logo'      => true,
            'admin_welcome'   => true,
            'admin_contacts'  => true,
            'admin_backups'   => true,
            'admin_rebuild'   => true,
        ];

        return [
            'preset_id'             => $id,
            'preset_label'          => $label,
            'pattern_label'         => $label,
            'mode'                  => $mode,
            'business_label'        => 'المؤسسة',
            'product_singular'      => $vocabulary['singular'],
            'product_plural'        => $vocabulary['plural'],
            'catalog_title'         => $vocabulary['catalog_title'],
            'catalog_cta'           => $vocabulary['catalog_cta'],
            'order_cta'             => $vocabulary['order_cta'],
            'home_hero_title'       => $vocabulary['hero_title'],
            'multi_item_orders'     => $ordering === self::ORDER_CART && $blueprint['multi_items'],
            'location_enabled'      => $placesOn,
            'location_singular'     => $placesOn ? 'موقع' : 'فرع',
            'location_plural'       => $placesOn ? 'المواقع' : 'الفروع',
            'location_required'     => $blueprint['places'] === self::PLACE_REQUIRED,
            'location_source'       => $locationSource,
            'location_code'         => $placesOn ? 'store' : 'store',
            'order_types'           => self::orderTypes($blueprint, $vocabulary),
            'product_extra_fields'  => self::extraFields($blueprint),
            'product_detail_fields' => self::detailFields($blueprint),
            'product_detail_rules'  => self::detailRules($blueprint),
            'feature_overrides'     => $features,
            'template_blueprint'    => $blueprint,
        ];
    }

    /**
     * @param array<string, mixed> $blueprint
     * @param array<string, string> $vocabulary
     * @return array<string, array<string, mixed>>
     */
    private static function orderTypes(array $blueprint, array $vocabulary): array
    {
        if ($blueprint['ordering'] === self::ORDER_NONE) {
            return [];
        }

        if ($blueprint['ordering'] === self::ORDER_RESERVATION) {
            return [
                'reservation' => [
                    'label'   => 'حجز موعد',
                    'icon'    => 'calendar',
                    'default' => true,
                    'requires_location' => $blueprint['places'] === self::PLACE_REQUIRED,
                    'description' => 'اختر الموعد المناسب وسنؤكده معك في أقرب وقت.',
                    'fields' => [
                        [
                            'key' => 'customer_phone', 'label' => 'رقم الهاتف', 'type' => 'tel',
                            'required' => true, 'placeholder' => '09xxxxxxxx', 'sort' => 20,
                        ],
                        [
                            'key' => 'appointment_date', 'label' => 'التاريخ المطلوب', 'type' => 'date',
                            'required' => true, 'sort' => 30,
                        ],
                        [
                            'key' => 'appointment_time', 'label' => 'الوقت المناسب', 'type' => 'text',
                            'required' => false, 'placeholder' => 'مثال: بعد الساعة 4', 'sort' => 40,
                        ],
                        [
                            'key' => 'people', 'label' => 'عدد الأشخاص', 'type' => 'number',
                            'required' => false, 'min' => 1, 'max' => 40, 'sort' => 45,
                        ],
                        [
                            'key' => 'notes', 'label' => 'ملاحظات', 'type' => 'textarea',
                            'required' => false, 'sort' => 50,
                        ],
                    ],
                ],
            ];
        }

        $types = [];

        if ($blueprint['places'] !== self::PLACE_NONE) {
            $types['dine_in'] = [
                'label'   => 'في المكان',
                'icon'    => 'dine-in',
                'default' => true,
                'requires_location' => true,
                'description' => 'يُخدم الطلب في الموقع أو الطاولة المحددة.',
                'fields' => [
                    [
                        'key' => 'notes', 'label' => 'ملاحظات', 'type' => 'textarea',
                        'required' => false, 'sort' => 30,
                    ],
                    [
                        'key' => 'served_for', 'label' => 'عدد الأشخاص', 'type' => 'number',
                        'required' => false, 'min' => 1, 'max' => 40, 'sort' => 40,
                    ],
                ],
            ];
            $types['takeaway'] = [
                'label'   => 'سفري',
                'icon'    => 'takeaway',
                'default' => false,
                'requires_location' => false,
                'description' => 'استلام سريع دون انتظار المكان.',
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
            ];
        } else {
            $types['delivery'] = [
                'label'   => 'توصيل',
                'icon'    => 'delivery',
                'default' => true,
                'requires_location' => false,
                'description' => 'نوصّل الطلب إلى العنوان الذي تحدده.',
                'fields' => [
                    [
                        'key' => 'customer_phone', 'label' => 'رقم الهاتف', 'type' => 'tel',
                        'required' => true, 'placeholder' => '09xxxxxxxx', 'sort' => 20,
                    ],
                    ['key' => 'address', 'label' => 'العنوان', 'type' => 'textarea', 'required' => true, 'sort' => 30],
                    ['key' => 'notes', 'label' => 'ملاحظات', 'type' => 'textarea', 'required' => false, 'sort' => 40],
                ],
            ];
            $types['pickup'] = [
                'label'   => 'استلام',
                'icon'    => 'takeaway',
                'default' => false,
                'requires_location' => false,
                'description' => 'نجهّز الطلب ثم تستلمه من الموقع.',
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
            ];
        }

        // إذا كان الطلب لعنصر واحد فقط نُبقي الأنواع كما هي؛ الفرق يُفرض في السلة
        // والسيرفر عبر multi_item_orders، فلا نكرر تعريفات الطلب.
        return $types;
    }

    /**
     * @param array<string, mixed> $blueprint
     * @return array<int, array<string, mixed>>
     */
    private static function extraFields(array $blueprint): array
    {
        $fields = [
            [
                'key' => 'code', 'label' => 'الرمز', 'type' => 'text',
                'required' => false, 'default' => '', 'hint' => 'رقم أو رمز مرجعي',
                'show_in_card' => true, 'searchable' => true, 'sort' => 10,
            ],
            [
                'key' => 'featured', 'label' => 'مميّز', 'type' => 'boolean',
                'required' => false, 'default' => false, 'hint' => '',
                'show_in_card' => true, 'searchable' => true, 'sort' => 20,
            ],
        ];

        if ($blueprint['ordering'] === self::ORDER_RESERVATION) {
            $fields[] = [
                'key' => 'duration', 'label' => 'المدة', 'type' => 'text',
                'required' => false, 'default' => '', 'hint' => 'مثال: 45 دقيقة',
                'show_in_card' => true, 'searchable' => true, 'sort' => 30,
            ];
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $blueprint
     * @return array<int, array<string, mixed>>
     */
    private static function detailFields(array $blueprint): array
    {
        if ($blueprint['details'] === self::DETAIL_NONE) {
            return [];
        }
        return [
            [
                'key'   => 'highlights',
                'label' => 'أبرز التفاصيل',
                'type'  => 'textarea',
                'hint'  => 'سطر أو سطران يوضحان ما يميّز هذا العنصر.',
            ],
        ];
    }

    /** @param array<string, mixed> $blueprint @return array<string, mixed> */
    private static function detailRules(array $blueprint): array
    {
        if ($blueprint['details'] === self::DETAIL_NONE) {
            return [
                'min_description_length' => 100000,
                'extra_field_threshold'  => 100000,
                'always_detail_keys'     => [],
            ];
        }
        return [
            'min_description_length' => 120,
            'extra_field_threshold'  => 1,
            'always_detail_keys'     => ['highlights'],
        ];
    }

    /**
     * يستنبط blueprint من حزمة overrides قديمة (أنشطة المجال).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function blueprintFromOverrides(array $overrides): array
    {
        $mode = (string) ($overrides['mode'] ?? self::MODE_ORDERS);
        $features = (array) ($overrides['feature_overrides'] ?? []);
        $feature = static fn(string $name, bool $default): bool =>
            array_key_exists($name, $features) ? (bool) $features[$name] : $default;

        $types = array_keys((array) ($overrides['order_types'] ?? []));
        $reservation = in_array('reservation', $types, true)
            || in_array('visit', $types, true);

        $ordering = match (true) {
            $mode === self::MODE_CATALOG => self::ORDER_NONE,
            $reservation                  => self::ORDER_RESERVATION,
            (bool) ($overrides['multi_item_orders'] ?? false) => self::ORDER_CART,
            default                       => self::ORDER_SINGLE,
        };

        $locations = (bool) ($overrides['location_enabled'] ?? false)
            && ($overrides['location_source'] ?? 'disabled') !== 'disabled';
        $places = !$locations ? self::PLACE_NONE
            : ((bool) ($overrides['location_required'] ?? false) ? self::PLACE_REQUIRED : self::PLACE_OPTIONAL);

        return self::normalizeBlueprint([
            'ordering'    => $ordering,
            'multi_items' => $ordering === self::ORDER_CART,
            'places'      => $places,
            'reservation' => $reservation,
            'tracking'    => $feature('order_tracking', $mode === self::MODE_ORDERS),
            'details'     => $feature('product_details', true)
                ? self::DETAIL_PAGE
                : self::DETAIL_NONE,
            'media'       => $feature('product_gallery', false)
                ? self::MEDIA_GALLERY
                : self::MEDIA_SINGLE,
        ]);
    }

    /* =====================================================================
     * أدوات صغيرة
     * ===================================================================== */

    /** @param array<mixed> $allowed */
    private static function choice(mixed $value, array $allowed, string $default): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function boolish(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes', 'نعم'], true);
    }

    /**
     * لا نمرّر إلا مفاتيح القدرات؛ تسميات المفردات تُقرأ منفصلة.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function onlyCapabilities(array $input): array
    {
        $out = [];
        foreach (['ordering', 'places', 'details', 'media', 'multi_items', 'reservation', 'tracking'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== '' && $input[$key] !== null) {
                $out[$key] = $input[$key];
            }
        }
        return $out;
    }
}
