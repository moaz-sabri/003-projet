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

        // تنظيف: نحذف رموز العملة والمسافات، ونبقي الأرقام والفواصل
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

            $clean = $isThousand
                ? $before . $tail
                : $before . '.' . $tail;
        }

        $value = (float) $clean;
        if (!is_finite($value)) {
            return 0;
        }

        return (int) round($value * (10 ** ProjectConfig::CURRENCY_DECIMALS));
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