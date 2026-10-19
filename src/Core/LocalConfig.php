<?php

declare(strict_types=1);

namespace App\Core;

/**
 * قراءة وكتابة ملف الإعدادات المحلي (local.php) في مكان واحد.
 *
 * ## لماذا وُجد
 *
 * كان كل مسار يكتب الملف بنفسه: `bin/console`، و`Auth::changePassword`،
 * وصفحة التهيئة. ثلاث نسخ تتباعد مع الوقت. والأخطر أن الكتابة كانت
 * تُعلن «تم» حتى إن فشلت (صلاحيات، قرص ممتلئ، ملف للقراءة فقط)، فيظن
 * صاحب النسخة أن كلمة المرور تغيّرت وهي لم تتغيّر.
 *
 * هنا:
 *   - المكان يُحسم من `app_config_file()` — نفس ما يقرأ منه التطبيق —
 *     فلا يكتب مسارٌ في ملف ويقرأ آخر من غيره.
 *   - الكتابة تُتحقَّق بإعادة القراءة والمقارنة، وإلّا استثناء صريح.
 *   - تُبطَل ذاكرة opcache للملف حتى يسري التغيير فورًا بلا إعادة تشغيل
 *     PHP-FPM (وهو سبب شائع لـ«غيّرت كلمة المرور ولم تتغيّر»).
 */
final class LocalConfig
{
    /** المسار الفعلي لملف الإعدادات الذي يقرأه التطبيق. */
    public static function path(): string
    {
        return app_config_file();
    }

    /** @return array<string,mixed> */
    public static function read(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    /**
     * يعدّل الإعدادات القائمة ثم يكتبها. تُستدعى دالة التعديل بالمصفوفة
     * الحالية وتعيد المصفوفة الجديدة — فلا تُسقط حقولًا لم تُذكر.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $mutator
     */
    public static function update(callable $mutator): string
    {
        return self::write($mutator(self::read()));
    }

    /**
     * يكتب الإعدادات ويتحقّق من وصولها إلى القرص فعلًا.
     *
     * @param array<string,mixed> $config
     * @return string المسار المكتوب
     */
    public static function write(array $config): string
    {
        $path = self::path();
        $dir  = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('تعذّر إنشاء مجلد الإعدادات: ' . $dir);
        }

        $php = "<?php\n"
             . "// ملف إعدادات محلي — لا يُرفع إلى أي مستودع ولا يُخدم عبر الويب.\n"
             . "// الحماية من الوصول تأتي من إعداد nginx، لا من ملف داخل المجلد.\n"
             . "return " . var_export($config, true) . ";\n";

        $written = @file_put_contents($path, $php, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException(
                'تعذّر كتابة ملف الإعدادات: ' . $path
                . ' — تحقّق من صلاحيات الكتابة على المجلد.'
            );
        }

        StorageWriter::fixOwnership($path, 0640);

        // إبطال الكاش: بدونه قد يبقى opcache يقدّم النسخة القديمة بعد الكتابة.
        clearstatcache(true, $path);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        // تحقّق فعلي: «file_put_contents نجح» لا يعني أن القيمة المطلوبة
        // وصلت (قد يكتبها كاتب آخر بعدنا). نُعيد القراءة ونقارن.
        $check = require $path;
        if (!is_array($check) || $check != $config) {
            throw new \RuntimeException(
                'كُتب ملف الإعدادات لكن القيمة لم تُطبَّق — تحقّق من القرص والصلاحيات.'
            );
        }

        return $path;
    }
}
