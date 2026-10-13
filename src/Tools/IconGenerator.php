<?php

declare(strict_types=1);

namespace App\Tools;

/**
 * مولّد أيقونات PWA بصيغة PNG بلغة PHP خالصة (بدون GD وبدون مكتبات).
 * يكتب ملف PNG يدويًا عبر zlib، ما يجعله يعمل على أي استضافة PHP.
 *
 * التشغيل: php bin/console icons
 */
final class IconGenerator
{
    private const SIZES = [72, 96, 128, 144, 152, 192, 384, 512];

    /** ألوان الهوية الافتراضية (يمكن تغييرها من هنا). */
    private const DARK  = [17, 24, 39];
    private const LIGHT = [249, 250, 251];
    private const ACCENT = [234, 88, 12];

    /** @return array<int,string> قائمة الملفات التي أُنشئت */
    public static function generateAll(string $initials = '', array $accent = self::ACCENT): array
    {
        $dir = \App\Config\ProjectConfig::basePath('app/assets/icons');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('تعذّر إنشاء مجلد الأيقونات.');
        }

        $created = [];
        foreach (self::SIZES as $size) {
            $png   = self::render($size, $initials, $accent);
            $name  = $size === 512 ? 'icon-512.png' : 'icon-' . $size . '.png';
            $path  = $dir . '/' . $name;
            file_put_contents($path, $png);
            $created[] = $path;

            if ($size === 512) {
                file_put_contents($dir . '/icon-512-maskable.png', $png);
                $created[] = $dir . '/icon-512-maskable.png';
            }
        }

        // أيقونة متجهية صغيرةFallback للمتصفحات التي لا تدعم PNG في المانيفست
        file_put_contents($dir . '/icon.svg', self::svg($initials, $accent));
        $created[] = $dir . '/icon.svg';

        return $created;
    }

    /** يرسم مربّعًا بحواف دائرية مع رمز Activity رفيع داخله. */
    private static function render(int $size, string $initials, array $accent): string
    {
        $ss        = 3;                       // معامل التنعيم (3×3 لكل بكسل)
        $big       = $size * $ss;
        $radius    = (int) round($big * 0.22);
        $canvas    = [];

        // بناء الصورة بحجم مكبّر ثم تصغيرها يدويًا (average downsample)
        for ($y = 0; $y < $big; $y++) {
            $canvas[$y] = str_repeat("\x00", $big * 3);
        }

        $glyph = self::glyphMask($big, $accent);

        for ($y = 0; $y < $big; $y++) {
            $row = '';
            for ($x = 0; $x < $big; $x++) {
                $color = self::sampleColor($x, $y, $big, $radius, $glyph, $accent);
                $row .= chr($color[0]) . chr($color[1]) . chr($color[2]);
            }
            $canvas[$y] = $row;
        }

        return self::toPng(self::downsample($canvas, $big, $ss), $size, $size);
    }

    /** قناع الرمز داخل الأيقونة (ثلاثة أشرطة تمثل القائمة). */
    private static function glyphMask(int $size, array $accent): array
    {
        $mask  = [];
        $barH  = max(2, (int) round($size * 0.055));
        $left  = (int) round($size * 0.26);
        $right = (int) round($size * 0.74);
        $top   = (int) round($size * 0.30);
        $gap   = (int) round($size * 0.12);

        for ($y = 0; $y < $size; $y++) {
            $row = array_fill(0, $size, "\x00");
            for ($bar = 0; $bar < 3; $bar++) {
                $barTop = $top + $bar * $gap;
                if ($y >= $barTop && $y < $barTop + $barH) {
                    for ($x = $left; $x < $right; $x++) {
                        $row[$x] = "\x01";
                    }
                }
            }
            $mask[$y] = $row;
        }
        return $mask;
    }

    /** لون البكسل داخل المربع المستدير. */
    private static function sampleColor(int $x, int $y, int $size, int $radius, array $glyph, array $accent): array
    {
        // الزوايا المستديرة: خارج دائرة quarter-corner = شفاف
        $dx = min($x, $size - 1 - $x);
        $dy = min($y, $size - 1 - $y);
        if ($dx < $radius && $dy < $radius) {
            $ddx = $radius - $dx;
            $ddy = $radius - $dy;
            if ($ddx * $ddx + $ddy * $ddy > $radius * $radius) {
                return [0, 0, 0]; // شفاف
            }
        }

        // الخلفية متدرّجة من الداكن إلى الفاتح عموديًا
        $t  = $y / max(1, $size - 1);
        $bg = [
            (int) round(self::DARK[0] + (self::LIGHT[0] - self::DARK[0]) * $t),
            (int) round(self::DARK[1] + (self::LIGHT[1] - self::DARK[1]) * $t),
            (int) round(self::DARK[2] + (self::LIGHT[2] - self::DARK[2]) * $t),
        ];

        if (isset($glyph[$y][$x]) && $glyph[$y][$x] === "\x01") {
            return $accent;
        }
        return $bg;
    }

    /** تصغير بالمتوسط (تنعيم الحواف). */
    private static function downsample(array $canvas, int $big, int $factor): array
    {
        $out = (int) ($big / $factor);
        $small = [];
        for ($y = 0; $y < $out; $y++) {
            $row = '';
            for ($x = 0; $x < $out; $x++) {
                $r = $g = $b = $a = 0;
                $n = $factor * $factor;
                for ($dy = 0; $dy < $factor; $dy++) {
                    $sy = $y * $factor + $dy;
                    for ($dx = 0; $dx < $factor; $dx++) {
                        $sx = $x * $factor + $dx;
                        $i = $sx * 3;
                        $pr = ord($canvas[$sy][$i]);
                        $pg = ord($canvas[$sy][$i + 1]);
                        $pb = ord($canvas[$sy][$i + 2]);
                        if ($pr === 0 && $pg === 0 && $pb === 0) {
                            continue; // شفاف
                        }
                        $r += $pr; $g += $pg; $b += $pb; $a++;
                    }
                }
                if ($a === 0) {
                    $row .= "\x00\x00\x00\x00";
                } else {
                    $row .= chr((int) round($r / $a))
                          . chr((int) round($g / $a))
                          . chr((int) round($b / $a))
                          . chr((int) round(255 * $a / $n));
                }
            }
            $small[$y] = $row;
        }
        return $small;
    }

    /** ترميز PNG (RGBA، 8 بت لكل قناة) عبر zlib. */
    private static function toPng(array $pixels, int $width, int $height): string
    {
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\x00" . $pixels[$y];   // نوع الترشيح 0
        }

        $ihdr = pack('NN', $width, $height) . chr(8) . chr(6) . chr(0) . chr(0) . chr(0);

        return "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', $ihdr)
            . self::chunk('IDAT', gzcompress($raw, 9))
            . self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /** نسخة SVG بسيطة (تُستخدم كبديل في المانيفست). */
    private static function svg(string $initials, array $accent): string
    {
        $rgb = sprintf('#%02x%02x%02x', $accent[0], $accent[1], $accent[2]);
        $label = htmlspecialchars($initials !== '' ? $initials : 'ق', ENT_QUOTES, 'UTF-8');

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">
          <defs>
            <linearGradient id="g" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#111827"/>
              <stop offset="100%" stop-color="#f9fafb"/>
            </linearGradient>
          </defs>
          <rect width="512" height="512" rx="112" fill="url(#g)"/>
          <rect x="138" y="164" width="236" height="28" rx="14" fill="{$rgb}"/>
          <rect x="138" y="242" width="236" height="28" rx="14" fill="{$rgb}"/>
          <rect x="138" y="320" width="236" height="28" rx="14" fill="{$rgb}"/>
        </svg>
        SVG;
    }
}