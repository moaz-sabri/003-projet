<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\ProjectConfig;

/**
 * تمثيل المال: نخزّن الأعداد الصحيحة (بالوحدة الصغرى) في قاعدة البيانات،
 * ونعرضها وفق إعدادات العملة في ProjectConfig.
 */
final class Money
{
    /** يحوّل السعر المُدخل من المستخدم (نص) إلى وحدات صغيرة صحيحة. */
    public static function parseToMinor(string|int|float|null $input): int
    {
        if ($input === null || $input === '') {
            return 0;
        }

        $raw = trim((string) $input);

        // 0) إشارة السالب: سعر سالب ليس سعرًا. كان الحرف يُحذف بصمت فيتحوّل
        //    «-50» إلى +50 — أي أن خطأ كتابة واحد يبيع منتجًا بضعف السعر.
        //    الآن يصير 0 ( واضح في اللوحة) بدل قيمة موجبة خاطئة.
        if (str_starts_with($raw, '-') || str_starts_with($raw, '−')) {
            return 0;
        }

        // 1) الأرقام العربية-الهندية (٠١٢…) إلى أرقام لاتينية.
        //    بدون هذه الخطوة يُحذف كل رقم هندي بصمت والنتيجة صفر — وهو أسوأ
        //    من رفضه، لأن المدير يرى منتجاً بسعر 0 ولا يعرف أن الحقل «مُهلَك».
        $raw = self::normalizeDigits($raw);

        // 2) تنظيف: نحذف رموز العملة والمسافات، ونبقي الأرقام والفواصل
        $clean = preg_replace('/[^\d.,٫٬]/u', '', $raw) ?? '';
        $clean = str_replace([' ', "\u{00A0}", '٬', '٫', '٬'], '', $clean);
        $clean = trim($clean, '.,');   // رموز العملة قد تترك فواصل زائدة (مثال: «ل.ل»)

        if ($clean === '' || !preg_match('/\d/', $clean)) {
            return 0;
        }

        // نعتبر آخر فاصلة/نقطة الفاصل العشري المحتمل، وما قبله فواصل آلاف
        $decimalPos = null;
        for ($i = strlen($clean) - 1; $i >= 0; $i--) {
            if ($clean[$i] === '.' || $clean[$i] === ',') {
                $decimalPos = $i;
                break;
            }
        }

        if ($decimalPos !== null && $decimalPos > 0) {
            $tail   = substr($clean, $decimalPos + 1);
            $before = substr($clean, 0, $decimalPos);

            // فاصل آلاف (ثلاثة أرقام) وليس فاصلة عشرية
            $isThousand = strlen($tail) === 3 && preg_match('/^\d{1,3}([.,]\d{3})*$/', $before) === 1;

            // مهم: في *كلا* المسارين يجب أن يختفي كل فاصل آلاف قبل التحويل،
            // وإلا توقّف (float) عند أول فاصلة وأفسد الرقم بصمت.
            // مثال الخلل السابق: «1,500.50» → «1,500.50» → (float) يتوقف عند
            // الفاصلة → 1 بدل 1500 (خطأ بمعامل 1000 وبلا أي رسالة خطأ).
            $head = str_replace([',', '.'], '', $before);
            $clean = $isThousand
                ? $head . $tail
                : $head . '.' . $tail;
        }

        $clean = str_replace(',', '', $clean);
        if (!preg_match('/^\d+(\.\d+)?$/', $clean)) {
            return 0;   // حارس أخير: لا يمرّ شيء إلى (float) إلا رقم نظيف
        }

        $value = (float) $clean;
        if (!is_finite($value)) {
            return 0;
        }

        $scale  = 10 ** ProjectConfig::CURRENCY_DECIMALS;
        $minor  = $value * $scale;
        if (!is_finite($minor) || $minor > PHP_INT_MAX || $minor < 0) {
            return 0;   // فيض أو قيمة غير معقولة → 0 بدل قيمة مشوّهة
        }

        return (int) round($minor);
    }

    /**
     * يحوّل الأرقام العربية-الهندية والفارسية إلى أرقام لاتينية.
     * (٠-٩ و ۰-۹) — الحقل السداسي عشري يغطّي المدى كاملاً.
     */
    private static function normalizeDigits(string $raw): string
    {
        // خريطة صريحة أوضح وأسرع من الحساب، ولا تعتمد على mb_ord.
        static $map = [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ];
        return strtr($raw, $map);
    }

    /** يحوّل الوحدات الصغيرة إلى نص للعرض. */
    public static function format(int $minor, bool $withCurrency = true): string
    {
        $decimals = ProjectConfig::CURRENCY_DECIMALS;
        $value    = $minor / (10 ** $decimals);
        $number   = number_format($value, $decimals, '.', ',');
        if (!$withCurrency) {
            return $number;
        }
        return ProjectConfig::CURRENCY_POSITION === 'before'
            ? ProjectConfig::CURRENCY . ' ' . $number
            : $number . ' ' . ProjectConfig::CURRENCY;
    }

    /** يحوّل نص السعر المُدخل إلى رقم مفروض للعرض داخل حقول الإدارة. */
    public static function minorToInput(int $minor): string
    {
        $decimals = ProjectConfig::CURRENCY_DECIMALS;
        return number_format($minor / (10 ** $decimals), $decimals, '.', '');
    }
}