<?php

declare(strict_types=1);

namespace App\Core\Qr;

/**
 * مولّد رموز QR كامل بلغة PHP خالصة — بدون أي مكتبة خارجية أو خدمة مدفوعة.
 *
 * المعتمد: ISO/IEC 18004 — وضع البايت + ECI 26 لقراءة العربية،
 * تصحيح أخطاء Reed–Solomon، اختيار القناع حسب نقاط الغرامة.
 * المخرجات: مصفوفة وحدات (true = أسود) + SVG جاهزة للطباعة.
 *
 * خوارزمية الكتابة تتبع المرجع القياسي (Nayuki QR Code generator).
 */
final class QrCode
{
    /**
     * توزيع كتل التصحيح لكل إصدار (1..20) والمستويات L/M/Q/H.
     * الصيغة لكل مستوى: [عدد كلمات التصحيح لكل كتلة, [[عدد الكتل, كلمات البيانات لكل كتلة], ...]]
     */
    private const RS_BLOCKS = [
        1  => [7,  [[1, 19]],             10, [[1, 16]],           13, [[1, 13]],        17, [[1, 9]]],
        2  => [10, [[1, 34]],             16, [[1, 28]],           22, [[1, 22]],        28, [[1, 16]]],
        3  => [15, [[1, 55]],             26, [[1, 44]],           18, [[2, 17]],        22, [[2, 13]]],
        4  => [20, [[1, 80]],             18, [[2, 32]],           26, [[2, 24]],        16, [[4, 9]]],
        5  => [26, [[1, 108]],            24, [[2, 43]],           18, [[2, 15], [2, 16]],  22, [[2, 11], [2, 12]]],
        6  => [18, [[2, 68]],             16, [[4, 27]],           24, [[4, 19]],        28, [[4, 15]]],
        7  => [20, [[2, 78]],             18, [[4, 31]],           18, [[2, 14], [4, 15]],  26, [[4, 13], [1, 14]]],
        8  => [24, [[2, 97]],             22, [[2, 38], [2, 39]],  22, [[4, 18], [2, 19]],   26, [[4, 14], [2, 15]]],
        9  => [30, [[2, 116]],            22, [[3, 36], [2, 37]],  20, [[4, 16], [4, 17]],   24, [[4, 12], [4, 13]]],
        10 => [18, [[2, 68], [2, 69]],    26, [[4, 43], [1, 44]],  24, [[6, 19], [2, 20]],   28, [[6, 15], [2, 16]]],
        11 => [20, [[4, 81]],             30, [[1, 50], [4, 51]],  28, [[4, 22], [4, 23]],   24, [[3, 12], [8, 13]]],
        12 => [24, [[2, 92], [2, 93]],    22, [[6, 36], [2, 37]],  26, [[4, 20], [6, 21]],   28, [[7, 14], [4, 15]]],
        13 => [26, [[4, 107]],            22, [[8, 37], [1, 38]],  24, [[8, 20], [4, 21]],   22, [[12, 11], [4, 12]]],
        14 => [30, [[3, 115], [1, 116]],  24, [[4, 40], [5, 41]],  20, [[11, 16], [5, 17]],  24, [[11, 12], [5, 13]]],
        15 => [22, [[5, 87], [1, 88]],    24, [[5, 41], [5, 42]],  30, [[5, 24], [7, 25]],   24, [[11, 12], [7, 13]]],
        16 => [24, [[5, 98], [1, 99]],    28, [[7, 45], [3, 46]],  24, [[15, 19], [2, 20]],  30, [[3, 15], [13, 16]]],
        17 => [28, [[1, 107], [5, 108]],  28, [[10, 46], [1, 47]], 28, [[1, 22], [15, 23]],  28, [[2, 14], [17, 15]]],
        18 => [30, [[5, 120], [1, 121]],  26, [[9, 43], [4, 44]],  28, [[17, 22], [1, 23]],  28, [[2, 14], [19, 15]]],
        19 => [28, [[3, 113], [4, 114]],  26, [[3, 44], [11, 45]], 26, [[17, 21], [4, 22]],  26, [[9, 13], [16, 14]]],
        20 => [28, [[3, 107], [5, 108]],  26, [[3, 41], [13, 42]], 30, [[15, 24], [5, 25]],  28, [[15, 15], [10, 16]]],
    ];

    /** مواضع مراكز أنماط المحاذاة لكل إصدار. */
    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
        11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62], 14 => [6, 26, 46, 66],
        15 => [6, 26, 48, 70], 16 => [6, 26, 50, 74], 17 => [6, 30, 54, 78],
        18 => [6, 30, 56, 82], 19 => [6, 30, 58, 86], 20 => [6, 34, 62, 90],
    ];

    /** بتات مستوى التصحيح داخل معلومات الصيغة: L=1, M=0, Q=3, H=2 */
    private const ECC_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    private const PENALTY_N1 = 3;
    private const PENALTY_N2 = 3;
    private const PENALTY_N3 = 40;
    private const PENALTY_N4 = 10;

    private string $eccLevel = 'M';
    private bool $useEci = true;

    public function __construct(string $eccLevel = 'M')
    {
        $this->setEcc($eccLevel);
    }

    public function setEcc(string $level): self
    {
        $level = strtoupper($level);
        $this->eccLevel = isset(self::ECC_BITS[$level]) ? $level : 'M';
        return $this;
    }

    public function setEci(bool $enabled): self
    {
        $this->useEci = $enabled;
        return $this;
    }

    public function eccLevel(): string
    {
        return $this->eccLevel;
    }

    /**
     * ينشئ الرمز ويعيد مصفوفة الوحدات (true = أسود).
     *
     * @return array<int, array<int, bool>>
     */
    public function matrix(string $text): array
    {
        $text     = $this->toBytes($text);
        $needsEci = $this->needsEci($text);
        $version  = $this->pickVersion(strlen($text), $needsEci);
        $bits     = $this->buildBitStream($text, $version, $needsEci);

        [$blockSizes, $ecPerBlock] = $this->structure($version);

        $dataCapacity = array_sum($blockSizes);
        $codewords    = $this->finalizeCodewords($bits, $dataCapacity);
        $interleaved  = $this->interleaveWithEcc($codewords, $blockSizes, $ecPerBlock);

        [$matrix, $reserved] = $this->buildFunctionPatterns($version);
        $this->placeCodewords($matrix, $reserved, $interleaved);

        return $this->applyBestMask($matrix, $reserved);
    }

    /**
     * إخراج SVG — مثالي للطباعة (حاد عند أي حجم وبلا تبعيات).
     */
    public function svg(string $text, int $moduleSize = 8, int $quietZone = 4, string $dark = '#111111', string $light = '#ffffff'): string
    {
        $matrix = $this->matrix($text);
        $count  = count($matrix);
        $size   = ($count + $quietZone * 2) * $moduleSize;

        $path = [];
        foreach ($matrix as $r => $row) {
            foreach ($row as $c => $isDark) {
                if ($isDark) {
                    $x = ($c + $quietZone) * $moduleSize;
                    $y = ($r + $quietZone) * $moduleSize;
                    $path[] = 'M' . $x . ' ' . $y . 'h' . $moduleSize . 'v' . $moduleSize . 'h-' . $moduleSize . 'z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '"'
            . ' viewBox="0 0 ' . $size . ' ' . $size . '" shape-rendering="crispEdges" role="img" aria-label="QR code">'
            . '<rect width="' . $size . '" height="' . $size . '" fill="' . self::esc($light) . '"/>'
            . '<path fill="' . self::esc($dark) . '" d="' . implode('', $path) . '"/>'
            . '</svg>';
    }

    /**
     * إخراج PNG عند توفّر GD، وإلا يرجع null (الواجهة تستخدم SVG دائمًا).
     */
    public function png(string $text, int $moduleSize = 8, int $quietZone = 4, string $darkHex = '#111111'): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $matrix = $this->matrix($text);
        $count  = count($matrix);
        $size   = ($count + $quietZone * 2) * $moduleSize;

        $img = imagecreatetruecolor($size, $size);
        [$dr, $dg, $db] = self::hexToRgb($darkHex);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, $dr, $dg, $db);
        imagefilledrectangle($img, 0, 0, $size, $size, $white);

        foreach ($matrix as $r => $row) {
            foreach ($row as $c => $isDark) {
                if ($isDark) {
                    $x = ($c + $quietZone) * $moduleSize;
                    $y = ($r + $quietZone) * $moduleSize;
                    imagefilledrectangle($img, $x, $y, $x + $moduleSize - 1, $y + $moduleSize - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($img, null, 9);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        return $bytes;
    }

    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /* ==================================================================
     * 1) الترميز
     * ================================================================== */

    /** يضمن أن النص UTF-8 صالح قبل معالجته كبايتات. */
    private function toBytes(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8');
    }

    private function buildBitStream(string $bytes, int $version, bool $withEci): string
    {
        $bits   = '';
        $length = strlen($bytes);

        if ($withEci) {
            $bits .= '0111';       // وضع ECI
            $bits .= '00011010';   // تعيين 26 = UTF-8
        }

        $bits .= '0100';           // وضع البايت

        // مؤشر الطول: 8 بت للإصدارات 1-9، و16 بت للإصدار 10 فما فوق
        $bits .= str_pad(decbin($length), $this->lengthBits($version), '0', STR_PAD_LEFT);

        for ($i = 0; $i < $length; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }

        return $bits;
    }

    /** هل نحتاج ترويسة ECI 26 لأن النص يحتوي محارف خارج ISO-8859-1؟ */
    private function needsEci(string $bytes): bool
    {
        return $this->useEci && preg_match('/[\x80-\xFF]/', $bytes) === 1;
    }

    /** حجم مؤشر الطول: 8 بت للإصدارات 1-9، و16 بت للإصدار 10 فما فوق. */
    private function lengthBits(int $version): int
    {
        return $version < 10 ? 8 : 16;
    }

    /** @return array{0: array<int,int>, 1: int} أحجام كتل البيانات وعدد كلمات التصحيح */
    private function structure(int $version): array
    {
        $row = self::RS_BLOCKS[$version];
        $idx = match ($this->eccLevel) {
            'L' => 0, 'M' => 1, 'Q' => 2, default => 3,
        };
        $ecPerBlock = $row[$idx * 2];
        $groups     = $row[$idx * 2 + 1];

        $sizes = [];
        foreach ($groups as [$count, $words]) {
            for ($i = 0; $i < $count; $i++) {
                $sizes[] = $words;
            }
        }
        return [$sizes, $ecPerBlock];
    }

    private function pickVersion(int $byteLength, bool $withEci): int
    {
        for ($v = 1; $v <= 20; $v++) {
            [$sizes]      = $this->structure($v);
            $capacityBits = array_sum($sizes) * 8;
            $needed = 4 + $this->lengthBits($v) + ($withEci ? 12 : 0) + $byteLength * 8;
            if ($needed <= $capacityBits) {
                return $v;
            }
        }
        throw new \RuntimeException('النص أطول من سعة الرمز المسموح بها (حتى الإصدار 20).');
    }

    /**
     * كود-words نهائية: terminator + إكمال لحدود-capacity + بايتات حشو.
     *
     * @return array<int,int>
     */
    private function finalizeCodewords(string $bits, int $capacity): array
    {
        // terminator: أربع بتات صفرية على الأكثر
        $bits .= '0000';
        $bits = substr($bits, 0, $capacity * 8);

        $pads = [0xEC, 0x11];
        $i    = 0;
        while (strlen($bits) < $capacity * 8) {
            $bits .= str_pad(decbin($pads[$i % 2]), 8, '0', STR_PAD_LEFT);
            $i++;
        }

        $out = [];
        foreach (str_split($bits, 8) as $chunk) {
            $out[] = bindec($chunk);
        }
        return $out;
    }

    /**
     * تقسيم الكتل + حساب Reed–Solomon + التشابك.
     * @return array<int,int>
     */
    private function interleaveWithEcc(array $codewords, array $blockSizes, int $ecPerBlock): array
    {
        [$exp, $log] = $this->gfTables();

        $dataBlocks = [];
        $eccBlocks  = [];
        $offset     = 0;
        foreach ($blockSizes as $size) {
            $chunk = array_slice($codewords, $offset, $size);
            $offset += $size;
            $dataBlocks[] = $chunk;
            $eccBlocks[]  = $this->reedSolomon($chunk, $ecPerBlock, $exp, $log);
        }

        $result = [];
        $maxData = max($blockSizes);
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($eccBlocks as $block) {
                $result[] = $block[$i];
            }
        }
        return $result;
    }

    /* ==================================================================
     * 2) Reed–Solomon على GF(256)
     * ================================================================== */

    /** @return array<int,int> */
    private function reedSolomon(array $data, int $ecCount, array $exp, array $log): array
    {
        $generator = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $generator = $this->polyMul($generator, [1, $exp[$i]], $exp, $log);
        }

        $message = array_merge($data, array_fill(0, $ecCount, 0));
        $len = count($generator);
        for ($i = 0; $i < count($data); $i++) {
            $coef = $message[$i];
            if ($coef === 0) {
                continue;
            }
            for ($j = 0; $j < $len; $j++) {
                $message[$i + $j] ^= $this->gfMul($generator[$j], $coef, $exp, $log);
            }
        }

        return array_slice($message, count($data));
    }

    /** @return array<int,int> */
    private function polyMul(array $a, array $b, array $exp, array $log): array
    {
        $result = array_fill(0, count($a) + count($b) - 1, 0);
        foreach ($a as $i => $av) {
            if ($av === 0) {
                continue;
            }
            foreach ($b as $j => $bv) {
                if ($bv === 0) {
                    continue;
                }
                $result[$i + $j] ^= $this->gfMul($av, $bv, $exp, $log);
            }
        }
        return $result;
    }

    private function gfMul(int $a, int $b, array $exp, array $log): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return $exp[($log[$a] + $log[$b]) % 255];
    }

    /** جداول Logs/Exps لمجال GF(256) مع كثير الحدود الأولي 0x11D. */
    private function gfTables(): array
    {
        static $tables = null;
        if ($tables !== null) {
            return $tables;
        }
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x   = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        return $tables = [$exp, $log];
    }

    /* ==================================================================
     * 3) أنماط الدالة وتخطيط الوحدات
     * ================================================================== */

    /**
     * @return array{0: array<int,array<int,bool>>, 1: array<int,array<int,bool>>}
     *         [0] مصفوفة الوحدات، [1] مصفوفة الوحدات المحجوزة (لا قناع عليها)
     */
    private function buildFunctionPatterns(int $version): array
    {
        $size     = 17 + 4 * $version;
        $matrix   = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        $set = function (int $r, int $c, bool $dark) use (&$matrix, &$reserved): void {
            $matrix[$r][$c]   = $dark;
            $reserved[$r][$c] = true;
        };

        // أنماط البحث الثلاثة في الزوايا
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$fr, $fc]) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $rr = $fr + $r;
                    $cc = $fc + $c;
                    if ($rr < 0 || $cc < 0 || $rr >= $size || $cc >= $size) {
                        continue;
                    }
                    $inRing = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                           || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6));
                    $inCore = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
                    $set($rr, $cc, $inRing || $inCore);
                }
            }
        }

        // خطوط التوقيت
        for ($i = 8; $i < $size - 8; $i++) {
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }

        // أنماط المحاذاة
        foreach (self::ALIGNMENT[$version] ?? [] as $ar) {
            foreach (self::ALIGNMENT[$version] ?? [] as $ac) {
                $nearFinder = ($ar <= 8 && $ac <= 8)
                            || ($ar <= 8 && $ac >= $size - 9)
                            || ($ar >= $size - 9 && $ac <= 8);
                if ($nearFinder) {
                    continue;
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $set($ar + $r, $ac + $c, max(abs($r), abs($c)) !== 1);
                    }
                }
            }
        }

        // الوحدة الداكنة الثابتة
        $set($size - 8, 8, true);

        // معلومات الصيغة (تُكتب لاحقًا عند اختيار القناع)
        for ($i = 0; $i <= 8; $i++) {
            if ($i !== 6) {
                $set(8, $i, false);
                $set($i, 8, false);
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $set(8, $size - 1 - $i, false);
            $set($size - 1 - $i, 8, false);
        }

        // معلومات الإصدار (للإصدار 7 فما فوق)
        if ($version >= 7) {
            $this->writeVersionBits($set, $version, $size);
        }

        return [$matrix, $reserved];
    }

    /** يكتب بتات معلومات الإصدار (18 بت: 6 بيانات + 12 BCH). */
    private function writeVersionBits(callable $set, int $version, int $size): void
    {
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($version << 12) | $rem;

        for ($i = 0; $i < 18; $i++) {
            $bit = (bool) (($bits >> $i) & 1);
            $a   = $size - 11 + ($i % 3);
            $b   = intdiv($i, 3);
            $set($b, $a, $bit);
            $set($a, $b, $bit);
        }
    }

    /**
     * توزيع بتات البيانات بشكل متعرّج من أسفل اليمين،
     * مع تخطي الأعمدة المحجوزة.
     */
    private function placeCodewords(array &$matrix, array $reserved, array $codewords): void
    {
        $size = count($matrix);
        $totalBits = count($codewords) * 8;
        $bitIndex = 0;
        $upward  = true;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;   // تخطي عمود التوقيت
            }
            for ($v = 0; $v < $size; $v++) {
                $row = $upward ? ($size - 1 - $v) : $v;
                for ($j = 0; $j < 2; $j++) {
                    $col = $right - $j;
                    if ($reserved[$row][$col]) {
                        continue;
                    }
                    $bit = false;
                    if ($bitIndex < $totalBits) {
                        $cw  = intdiv($bitIndex, 8);
                        $bit = (bool) (($codewords[$cw] >> (7 - ($bitIndex % 8))) & 1);
                    }
                    $matrix[$row][$col] = $bit;
                    $bitIndex++;
                }
            }
            $upward = !$upward;
        }
    }

    /* ==================================================================
     * 4) الأقنعة ومعلومات الصيغة واختيار الأفضل
     * ================================================================== */

    private function applyBestMask(array $matrix, array $reserved): array
    {
        $size = count($matrix);
        $eccBits = self::ECC_BITS[$this->eccLevel];
        $best = null;
        $bestScore = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $test = $matrix;
            $this->applyMask($test, $reserved, $mask);
            $this->writeFormatBits($test, $eccBits, $mask, $size);

            $score = $this->penalty($test, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $test;
            }
        }
        return $best ?? $matrix;
    }

    private function applyMask(array &$m, array $reserved, int $mask): void
    {
        $size = count($m);
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($reserved[$r][$c]) {
                    continue;
                }
                if ($this->maskCondition($mask, $r, $c)) {
                    $m[$r][$c] = !$m[$r][$c];
                }
            }
        }
    }

    private function maskCondition(int $mask, int $r, int $c): bool
    {
        return match ($mask) {
            0 => ($r + $c) % 2 === 0,
            1 => $r % 2 === 0,
            2 => $c % 3 === 0,
            3 => ($r + $c) % 3 === 0,
            4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
            5 => (($r * $c) % 2) + (($r * $c) % 3) === 0,
            6 => ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0,
            default => ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0,
        };
    }

    /** يكتب معلومات الصيغة (15 بت) في النسختين. */
    private function writeFormatBits(array &$m, int $eccBits, int $mask, int $size): void
    {
        $data = ($eccBits << 3) | $mask;
        $rem  = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        $bit = static fn(int $i): bool => (bool) (($bits >> $i) & 1);

        // النسخة الأولى حول نمط البحث الأعلى أيسر
        for ($i = 0; $i <= 5; $i++) {
            $m[$i][8] = $bit($i);
        }
        $m[7][8] = $bit(6);
        $m[8][8] = $bit(7);
        $m[8][7] = $bit(8);
        for ($i = 9; $i < 15; $i++) {
            $m[8][14 - $i] = $bit($i);
        }

        // النسخة الثانية
        for ($i = 0; $i < 8; $i++) {
            $m[8][$size - 1 - $i] = $bit($i);
        }
        for ($i = 8; $i < 15; $i++) {
            $m[$size - 15 + $i][8] = $bit($i);
        }

        $m[$size - 8][8] = true;   // الوحدة الداكنة
    }

    /** نقاط الغرامة الأربع لاختيار أفضل قناع. */
    private function penalty(array $m, int $size): int
    {
        $score = 0;

        // القاعدة 1: تتابع 5 وحدات أو أكثر بنفس اللون
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $size; $a++) {
                $run = 1;
                for ($b = 1; $b < $size; $b++) {
                    $cur  = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                    $prev = $pass === 0 ? $m[$a][$b - 1] : $m[$b - 1][$a];
                    if ($cur === $prev) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $score += self::PENALTY_N1 + ($run - 5);
                        }
                        $run = 1;
                    }
                }
                if ($run >= 5) {
                    $score += self::PENALTY_N1 + ($run - 5);
                }
            }
        }

        // القاعدة 2: مربعات 2×2 بنفس اللون
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $v = $m[$r][$c];
                if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) {
                    $score += self::PENALTY_N2;
                }
            }
        }

        // القاعدة 3: نمط 1:1:3:1:1 مع أربعة بيضاء (أو أربعة سوداء قبله)
        $patterns = [
            [true, false, true, true, true, false, true, false, false, false, false],
            [false, false, false, false, true, false, true, true, true, false, true],
        ];
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $size; $a++) {
                for ($b = 0; $b <= $size - 11; $b++) {
                    foreach ($patterns as $pattern) {
                        $match = true;
                        for ($k = 0; $k < 11; $k++) {
                            if (($pass === 0 ? $m[$a][$b + $k] : $m[$b + $k][$a]) !== $pattern[$k]) {
                                $match = false;
                                break;
                            }
                        }
                        if ($match) {
                            $score += self::PENALTY_N3;
                        }
                    }
                }
            }
        }

        // القاعدة 4: توازن الداكن/الفاتح
        $dark = 0;
        $total = $size * $size;
        foreach ($m as $row) {
            foreach ($row as $cell) {
                if ($cell) {
                    $dark++;
                }
            }
        }
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        $score += $k * self::PENALTY_N4;

        return $score;
    }
}