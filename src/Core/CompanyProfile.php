<?php

declare(strict_types=1);

namespace App\Core;

/**
 * CompanyProfile — القارئ الوحيد لملف معلومات الشركة.
 *
 * لماذا ملف واحد؟
 *   لأن الهدف قالب ثابت يتغيّر محتواه بتعديل ملف واحد. كل ما يصف الشركة
 *   (النبذة، الصور، التواصل، أوقات العمل، الأقسام، نصوص SEO) يعيش في
 *   app/data/company.json، وتُبنى عليه الصفحة الرئيسية وصفحة التواصل.
 *
 * لماذا لا قاعدة البيانات؟
 *   لأن المحتوى طويل ومحرّر نصيًا (نبذة، قصة، أقسام). تعديله من لوحة الإدارة
 *   بإدارة عشرات الحقول يتعب أكثر مما يعبّر عنه، بينما تعديل JSON واحد
 *   يفعلها للجميع. كما أن الملف يبقى نسخة احتياطية قابلة للنسخ بين المشاريع.
 *
 * لكن القالب لا يتركك عاجزًا: ما له واجهة (الاسم، الشعار، الهاتف، إنستغرام)
 *   يمكن تغييره من لوحة الإدارة، ويبقى له الأولوية هنا عند تعبئته.
 *
 * آمن بالتصميم: الملف اختياري تمامًا. حذفه أو إفساده لا يُسقط الموقع —
 *   تعود الواجهة إلى الإعدادات الافتراضية وتعمل بشكل سليم.
 *
 * @package App\Core
 */
final class CompanyProfile
{
    /** إصدار بنية الملف — يُرفع عند إضافة مفاتيح جديدة. */
    public const SCHEMA_VERSION = 1;

    private static ?array $cache = null;

    /* =====================================================================
     * الوصول إلى الملف
     * ===================================================================== */

    /** مسار الملف المطلق. */
    public static function path(): string
    {
        return \App\Config\ProjectConfig::basePath('app/data/company.json');
    }

    /** هل الملف موجود على القرص؟ */
    public static function exists(): bool
    {
        return is_file(self::path());
    }

    /**
     * بنية الملف الافتراضية — تُستخدم عند غياب الملف أو إفساده.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'brand' => [
                'name'        => '',
                'tagline'     => '',
                'logo'        => '',
                'hero_image'  => '',
                'cover_image' => '',
            ],
            'about' => [
                'summary'    => '',
                'story'      => '',
                'highlights' => [],
                'gallery'    => [],
            ],
            'contact' => [
                'phone'         => '',
                'whatsapp'      => '',
                'email'         => '',
                'instagram'     => '',
                'facebook'      => '',
                'telegram'      => '',
                'tiktok'        => '',
                'youtube'       => '',
                'whatsapp_note' => '',
                'address'       => '',
                'map_url'       => '',
                'working_hours' => [],
            ],
            'sections' => [],
            'cta' => [
                'title'        => '',
                'text'         => '',
                'button_label' => '',
                'button_url'   => '',
            ],
            'seo' => [
                'title'       => '',
                'description' => '',
                'keywords'    => '',
            ],
            'footer' => [
                'note'  => '',
                'links' => [],
            ],
        ];
    }

    /**
     * قراءة الملف الخام بلا أي دمج مع قاعدة البيانات.
     * آمنة للاستدعاء من أي مكان، ولا ترمي استثناءً أبدًا.
     *
     * @return array<string, mixed>
     */
    public static function read(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $data = self::schema();
        $file = self::path();

        if (is_file($file) && is_readable($file)) {
            $raw = file_get_contents($file);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = self::mergeDeep($data, $decoded);
                }
            }
        }

        return self::$cache = $data;
    }

    /** يُستخدم في الأدوات والاختبارات بعد كتابة الملف. */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /** دمج متكرر: القيم الواردة تغلب القيم الافتراضية، والمفاتيح المجهولة تُتجاهل. */
    private static function mergeDeep(array $base, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (!array_key_exists($key, $base)) {
                continue; // مفتاح غير معروف في هذه النسخة من البنية
            }
            if (is_array($base[$key]) && is_array($value) && !self::isList($value)) {
                $base[$key] = self::mergeDeep($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /** @param array<mixed> $a */
    private static function isList(array $a): bool
    {
        return $a === [] || array_is_list($a);
    }

    /* =====================================================================
     * الحمولة الجاهزة للواجهة
     * ===================================================================== */

    /**
     * كل ما تحتاجه الواجهة لرسم الغلاف وصفحة التواصل، بعد:
     *   - دمج القيم الفارغة مع إعدادات لوحة الإدارة
     *   - تحويل مسارات الصور النسبية إلى روابط جاهزة
     *   - حذف الأقسام الفارغة حتى لا تظهر أقسام فارغة على الشاشة
     *
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $raw = self::read();

        // قيم من لوحة الإدارة: تُستخدم فقط حين يكون حقل الملف فارغًا
        $fromDb = self::dbFallbacks();

        $brand = (array) $raw['brand'];
        $about = (array) $raw['about'];
        $contact = (array) $raw['contact'];

        $brand['name']    = self::text($brand['name'] ?? '') ?: (string) ($fromDb['name'] ?? '');
        $brand['tagline'] = self::text($brand['tagline'] ?? '') ?: (string) ($fromDb['tagline'] ?? '');
        $brand['logo']    = self::image($brand['logo'] ?? '') ?: (string) ($fromDb['logo'] ?? '');

        /*
         * نبذة الصفحة الرئيسية: ما يكتبه المدير في الإعدادات يتقدّم على نص الملف،
         * لأن الحقل معلن في لوحة الإدارة على أنه قابل للتعديل من هناك — وإلا
         * بقي تعديله بلا أثر ما دام في company.json نص (وأي نص قديم فيه).
         * كلاهما أفضل من النص الثابت الذي كان يُزرع وقت التهيئة.
         */
        $about['summary'] = self::text($fromDb['about_summary'] ?? '', 1200)
            ?: self::text($about['summary'] ?? '', 1200);

        $contact['phone']     = self::text($contact['phone'] ?? '') ?: (string) ($fromDb['phone'] ?? '');
        $contact['instagram'] = self::text($contact['instagram'] ?? '') ?: (string) ($fromDb['instagram'] ?? '');
        unset($fromDb['name'], $fromDb['tagline'], $fromDb['logo'], $fromDb['phone'], $fromDb['instagram']);

        return [
            'brand' => [
                'name'        => $brand['name'],
                'tagline'     => $brand['tagline'],
                'logo'        => self::image($brand['logo']),
                'hero_image'  => self::image($brand['hero_image'] ?? ''),
                'cover_image' => self::image($brand['cover_image'] ?? ''),
            ],
            'about' => [
                'summary'    => self::text($about['summary'] ?? '', 1200),
                'story'      => self::text($about['story'] ?? '', 4000),
                'highlights' => self::highlights((array) ($about['highlights'] ?? [])),
                'gallery'    => self::images((array) ($about['gallery'] ?? [])),
            ],
            'contact' => self::contactPayload($contact),
            'sections' => self::sections((array) ($raw['sections'] ?? [])),
            'cta' => [
                'title'        => self::text($raw['cta']['title'] ?? '', 120),
                'text'         => self::text($raw['cta']['text'] ?? '', 400),
                'button_label' => self::text($raw['cta']['button_label'] ?? '', 60),
                'button_url'   => self::url($raw['cta']['button_url'] ?? ''),
            ],
            'seo' => [
                'title'       => self::text($raw['seo']['title'] ?? '', 120),
                'description' => self::text($raw['seo']['description'] ?? '', 300),
                'keywords'    => self::text($raw['seo']['keywords'] ?? '', 200),
            ],
            'footer' => [
                'note'  => self::text($raw['footer']['note'] ?? '', 200),
                'links' => self::footerLinks((array) ($raw['footer']['links'] ?? [])),
            ],
        ];
    }

    /**
     * بيانات SEO فقط — تُستخدم في index.php الذي لا يلمس قاعدة البيانات إطلاقًا.
     *
     * @return array{title: string, description: string, keywords: string}
     */
    public static function seo(): array
    {
        $raw  = self::read();
        $name = self::text($raw['brand']['name'] ?? '');
        $tag  = self::text($raw['brand']['tagline'] ?? '');

        return [
            'title'       => self::text($raw['seo']['title'] ?? '', 120) ?: $name,
            'description' => self::text($raw['seo']['description'] ?? '', 300) ?: $tag,
            'keywords'    => self::text($raw['seo']['keywords'] ?? '', 200),
        ];
    }

/** هل يحتوي الملف على أي وسيلة تواصل على الأقل؟ (لتقرّر إظهار صفحة التواصل) */
    public static function hasContactWays(): bool
    {
        $contact = (array) self::read()['contact'];
        foreach (['phone', 'whatsapp', 'email', 'instagram', 'facebook', 'telegram', 'tiktok', 'youtube', 'address', 'map_url'] as $key) {
            if (self::text($contact[$key] ?? '') !== '') {
                return true;
            }
        }
        return self::workingHours((array) ($contact['working_hours'] ?? [])) !== [];
    }

    /** عدد الأقسام المعروضة — يُستخدم لتقرير تغيير النسخ. */
    public static function sectionCount(): int
    {
        return count(self::sections((array) self::read()['sections']));
    }

    /* =====================================================================
     * التطبيع (Normalization)
     * ===================================================================== */

    /**
     * الحقول التي لها بديل في لوحة الإدارة.
     * تُقرأ بلطف: قبل تهيئة قاعدة البيانات لا يوجد ما نقرأ منه.
     *
     * @return array<string, string>
     */
    private static function dbFallbacks(): array
    {
        try {
            $s = RuntimeSettings::publicPayload();
            return [
                'name'      => (string) ($s['app_name'] ?? ''),
                'tagline'   => (string) ($s['app_tagline'] ?? ''),
                'logo'      => (string) ($s['logo_path'] ?? ''),
                'about_summary' => (string) ($s['about_summary'] ?? ''),
                'phone'     => (string) ($s['contact_phone'] ?? ''),
                'instagram' => (string) ($s['contact_instagram'] ?? ''),
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int, array<string, string>> */
    private static function highlights(array $items): array
    {
        $out = [];
        foreach (self::toList($items) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = self::text($item['title'] ?? '', 80);
            $text  = self::text($item['text'] ?? '', 240);
            if ($title === '' && $text === '') {
                continue; // لا أقسام فارغة
            }
            $out[] = [
                'icon'  => self::icon($item['icon'] ?? ''),
                'title' => $title,
                'text'  => $text,
            ];
        }
        return $out;
    }

    /**
     * أقسام المحتوى المرتّبة — الكتل المرنة التي تبني الصفحة الرئيسية.
     * أي قسم بلا عنوان ولا نص يُسقَط.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function sections(array $items): array
    {
        $out = [];
        foreach (self::toList($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = self::text($item['title'] ?? '', 120);
            $body  = self::text($item['body'] ?? '', 4000);
            $bullets = [];
            foreach (self::toList($item['bullets'] ?? []) as $bullet) {
                $line = self::text(is_scalar($bullet) ? (string) $bullet : '', 200);
                if ($line !== '') {
                    $bullets[] = $line;
                }
            }
            $image = self::image($item['image'] ?? '');

            // شرط الظهور: نص حقيقي أو صورة — لا صندوق فارغ أبدًا
            if ($title === '' && $body === '' && $image === '' && $bullets === []) {
                continue;
            }

            $out[] = [
                'id'       => 'sec-' . ($index + 1),
                'icon'     => self::icon($item['icon'] ?? ''),
                'title'    => $title,
                'body'     => $body,
                'image'    => $image,
                'bullets'  => $bullets,
                // RTL: صورة نصية تُوضع في العمود الأول
                'flip'     => (bool) ($item['flip'] ?? false),
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function contactPayload(array $contact): array
    {
        $hours = self::workingHours((array) ($contact['working_hours'] ?? []));

        return [
            'phone'         => self::phone($contact['phone'] ?? ''),
            'whatsapp'      => self::phone($contact['whatsapp'] ?? ''),
            'email'         => self::email($contact['email'] ?? ''),
            'instagram'     => self::handle($contact['instagram'] ?? ''),
            'facebook'      => self::url($contact['facebook'] ?? ''),
            'telegram'      => self::handle($contact['telegram'] ?? ''),
            'tiktok'        => self::handle($contact['tiktok'] ?? ''),
            'youtube'       => self::url($contact['youtube'] ?? ''),
            'address'       => self::text($contact['address'] ?? '', 300),
            'map_url'       => self::url($contact['map_url'] ?? ''),
            'working_hours' => $hours,
            'has_hours'     => $hours !== [],
        ];
    }

    /** @return array<int, array<string, string>> */
    private static function workingHours(array $items): array
    {
        $out = [];
        foreach (self::toList($items) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $day = self::text($item['day'] ?? '', 40);
            if ($day === '') {
                continue;
            }
            $open  = self::time($item['open'] ?? '');
            $close = self::time($item['close'] ?? '');
            $out[] = [
                'day'   => $day,
                'open'  => $open,
                'close' => $close,
                // "مغلق" أو يوم كامل بلا ساعات
                'label' => ($open !== '' && $close !== '') ? $open . ' — ' . $close : (string) ($item['note'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return array<int, array<string, string>> */
    private static function footerLinks(array $items): array
    {
        $out = [];
        foreach (self::toList($items) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = self::text($item['label'] ?? '', 60);
            $url   = self::url($item['url'] ?? '');
            if ($label === '' || $url === '') {
                continue;
            }
            $out[] = ['label' => $label, 'url' => $url];
        }
        return $out;
    }

    /** @return array<int, string> */
    private static function images(array $items): array
    {
        $out = [];
        foreach (self::toList($items) as $item) {
            $url = self::image(is_scalar($item) ? (string) $item : ($item['url'] ?? ''));
            if ($url !== '') {
                $out[] = $url;
            }
        }
        return $out;
    }

    /**
     * تحويل قيم الحقول إلى مصفوفة رغم أن JSON يسمح بكتابتها كائنًا.
     * (قد يكتب المستخدم القوسين بالاتجاهين فيدخل الكائن مكان المصفوفة.)
     *
     * @return array<int, mixed>
     */
    private static function toList(array $value): array
    {
        return array_values(array_filter($value, static fn($v) => $v !== null && $v !== ''));
    }

    /* =====================================================================
     * تنظيف القيم — كل مدخل من الملف يمرّ من هنا قبل الوصول للمتصفح
     * ===================================================================== */

    private static function text(mixed $value, int $max = 2000): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $text = trim((string) $value);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // أسطر فارغة متتالية أكثر من سطرين لا تضيف شيئًا بصريًا
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
        return mb_substr($text, 0, $max);
    }

    /**
     * مسار صورة → رابط جاهز للمتصفح.
     * فارغ → فارغ | مطلق (http/https/data) → كما هو | نسبي → app/uploads/
     */
    private static function image(mixed $value): string
    {
        $path = self::text($value, 300);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^(https?:)?//|^data:/#i', $path)) {
            return $path;
        }
        // حماية من الخروج من مجلد الرفع (../)
        if (str_contains($path, '..')) {
            return '';
        }
        return \App\Core\Web\Paths::upload($path);
    }

    /** رابط خارجي: يُقبل فقط http/https/mailto/tel — يُسقط أي شيء آخر. */
    private static function url(mixed $value): string
    {
        $url = self::text($value, 300);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^(https?://|mailto:|tel:)#i', $url)) {
            return $url;
        }
        // وسيلة تواصل تُكتب بلا https — نحوّلها
        if (preg_match('#^(www\.|@)[^\s]+#i', $url)) {
            return 'https://' . ltrim($url, '@');
        }
        return '';
    }

    /** رقم هاتف: نُبقي الأرقام والرموز التي تتيح اتصالًا فعّالًا فقط. */
    private static function phone(mixed $value): string
    {
        $phone = self::text($value, 30);
        return (string) preg_replace('/[^\d+()\-\s]/', '', $phone);
    }

    private static function email(mixed $value): string
    {
        $email = self::text($value, 120);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /** حساب تواصل: @name أو اسم كامل — نوحّد الشكل. */
    private static function handle(mixed $value): string
    {
        $handle = ltrim(self::text($value, 60), '@');
        return (string) preg_replace('/[^\w.\-]/', '', $handle);
    }

    private static function time(mixed $value): string
    {
        $time = self::text($value, 5);
        return preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $time) ? $time : '';
    }

    /**
     * اسم أيقونة من القائمة المسموحة فقط — أي قيمة أخرى تسقط لصمت.
     * القائمة مغلقة عمدًا: أيقونة من خارجها تعني محاولة حقن HTML.
     */
    private static function icon(mixed $value): string
    {
        $icon = strtolower(self::text($value, 30));
        return in_array($icon, self::ICONS, true) ? $icon : '';
    }

    /** @var array<int, string> الأيقونات المتاحة في القالب */
    public const ICONS = [
        'star', 'shield', 'clock', 'truck', 'check', 'heart', 'phone', 'mail',
        'pin', 'calendar', 'award', 'sparkle', 'leaf', 'tool', 'tag', 'gift',
        'users', 'chart', 'box', 'key',
    ];
}