<?php

declare(strict_types=1);

namespace App\Core;

/**
 * إعدادات التشغيل القابلة للتعديل من لوحة الإدارة (جدول settings).
 * تُقرأ مرة واحدة لكل طلب.
 */
final class RuntimeSettings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $rows = Database::all('SELECT key, value FROM settings');
        $out  = [];
        foreach ($rows as $row) {
            $out[$row['key']] = $row['value'];
        }
        foreach (\App\Config\ProjectConfig::defaultRuntimeSettings() as $k => $v) {
            $out[$k] ??= $v;
        }
        self::$cache = $out;
        return $out;
    }

    public static function get(string $key, string $default = ''): string
    {
        return (string) (self::all()[$key] ?? $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $all = self::all();
        if (!array_key_exists($key, $all)) {
            return $default;
        }
        return self::toBool($all[$key]);
    }

    private static function toBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes', 'نعم'], true);
    }

    public static function set(string $key, string $value): void
    {
        Database::run(
            'INSERT INTO settings (key, value, updated_at) VALUES (:k, :v, :now)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            ['k' => $key, 'v' => $value, 'now' => Database::now()]
        );
        self::$cache[$key] = $value;
    }

    public static function setMany(array $pairs): void
    {
        Database::transaction(function () use ($pairs): void {
            foreach ($pairs as $k => $v) {
                self::set((string) $k, (string) $v);
            }
        });
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** الإعدادات التي تُعرض في الواجهة العامة (بدون أي أسرار). */
    public static function publicPayload(): array
    {
        return [
            'app_name'        => self::get('app_name'),
            'app_tagline'     => self::get('app_tagline'),
            'welcome_message' => self::get('welcome_message'),
            'logo_path'       => self::assetUrl(self::get('logo_path')),
            'about_summary'   => self::get('about_summary'),
            'contact_phone'   => self::get('contact_phone'),
            'contact_instagram' => self::get('contact_instagram'),
            'show_welcome'    => self::bool('show_welcome'),
            'show_search'     => self::bool('show_search'),
            'show_contact'    => self::bool('show_contact'),
            'show_prices'     => self::bool('show_prices'),

            /* --- تخصيص الصفحة الرئيسية (فارغ = ورّث من company.json) --- */
            'hero_image'          => self::assetUrl(self::get('hero_image_path')),
            'home_layout'         => self::get('home_layout'),
            'home_height'         => self::get('home_height'),
            'home_hero_eyebrow'   => self::get('home_hero_eyebrow'),
            'home_hero_title'     => self::get('home_hero_title'),
            'home_hero_text'      => self::get('home_hero_text'),
            'home_primary_label'  => self::get('home_primary_label'),
            'home_primary_url'    => self::get('home_primary_url'),
            'home_secondary_label' => self::get('home_secondary_label'),
            'home_secondary_url'  => self::get('home_secondary_url'),
            'home_show_highlights' => self::get('home_show_highlights'),
            'home_show_sections'   => self::get('home_show_sections'),
            'home_show_gallery'    => self::get('home_show_gallery'),
            'home_show_stats'      => self::get('home_show_stats'),
            'home_show_testimonials' => self::get('home_show_testimonials'),
            'home_show_faq'        => self::get('home_show_faq'),
            'home_show_cta'        => self::get('home_show_cta'),
            'home_show_contact'    => self::get('home_show_contact'),
        ];
    }

    public static function adminPayload(): array
    {
        $payload = self::all();
        unset($payload['admin_password_hash']);
        // logo_path يبقى كما هو (المسار المخزَّن) لأن لوحة الإدارة تحتاجه للحذف،
        // وlogo_url هو الرابط الجاهز للمعاينة.
        $payload['logo_url'] = self::assetUrl((string) ($payload['logo_path'] ?? ''));
        // نفس المنطق لصورة الغلاف: المسار للحذف/الاستبدال، والرابط للمعاينة.
        $payload['hero_image_url'] = self::assetUrl((string) ($payload['hero_image_path'] ?? ''));
        return $payload;
    }

    /**
     * يحوّل مسارًا مخزَّنًا في قاعدة البيانات إلى رابط جاهز للمتصفح.
     *
     * لماذا؟ لأن قاعدة البيانات تحفظ "brand/x.png" (نسبي لمجلد الرفع)، بينما
     * المتصفح يحتاج "/app/uploads/brand/x.png". ترك التحويل للمتصفح ينتج
     * شعارًا مكسورًا بمجرد أن يتغيّر مسار الصفحة.
     */
    private static function assetUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^(https?:)?//|^data:/#i', $path)) {
            return $path;
        }
        if (str_contains($path, '..')) {
            return '';
        }
        return \App\Config\ProjectConfig::uploadsUrl($path);
    }
}