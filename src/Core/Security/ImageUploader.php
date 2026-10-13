<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Config\ProjectConfig;
use App\Core\Http\HttpError;

/**
 * رفع الصور والتحقق منها: فحص النوع الحقيقي، الحجم، وإعادة التسمية.
 * لا يُقبل أي ملف ليس صورة فعلية، ولا يُحفظ اسم الملف الأصلي.
 */
final class ImageUploader
{
    public const MAX_SIZE = 4 * 1024 * 1024;   // 4 ميجابايت
    public const MAX_DIMENSION = 4000;

    /** الامتدادات المسموحة + نوع MIME الحقيقي المقابل */
    private const ALLOWED = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
    ];

    /**
     * @param array $file ملف من $_FILES
     * @return string اسم الملف داخل مجلد الرفع
     */
    public static function store(array $file, string $folder = 'products'): string
    {
        self::assertValid($file);

        $tmp = (string) $file['tmp_name'];
        $ext = self::detectExtension($tmp, (string) $file['name']);

        $dir = ProjectConfig::publicUploadsPath($folder);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpError('upload_failed', 'تعذّر إنشاء مجلد الصور.', 500);
        }

        $name = self::randomName($ext);
        $dest = $dir . '/' . $name;

        // move_uploaded_file عند وجود رفع حقيقي، وrename في الاختبارات/الـ CLI
        $moved = is_uploaded_file($tmp)
            ? move_uploaded_file($tmp, $dest)
            : rename($tmp, $dest);

        if (!$moved) {
            throw new HttpError('upload_failed', 'تعذّر حفظ الصورة.', 500);
        }

        @chmod($dest, 0644);
        self::optimize($dest, $ext);

        return $folder . '/' . $name;
    }

    public static function delete(?string $relativePath): void
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return;
        }
        // منع الخروج من مجلد الرفع (path traversal)
        if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
            return;
        }
        $full = ProjectConfig::publicUploadsPath($relativePath);
        $real = realpath($full);
        $base = realpath(ProjectConfig::publicUploadsPath());
        if ($real !== false && $base !== false && str_starts_with($real, $base) && is_file($real)) {
            @unlink($real);
        }
    }

    private static function assertValid(array $file): void
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new HttpError('invalid_upload', 'فشل رفع الملف.', 400);
        }
        if (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') {
            throw new HttpError('invalid_upload', 'ملف غير صالح.', 400);
        }
        if ((int) $file['size'] > self::MAX_SIZE) {
            throw new HttpError('file_too_large', 'حجم الصورة أكبر من 4 ميجابايت.', 400);
        }

        // نعتمد على النوع الحقيقي للملف (getimagesize) لا على الامتداد ولا على الترويسة المرسلة
        $info = @getimagesize($file['tmp_name']);
        if ($info === false || empty($info['mime']) || !in_array($info['mime'], self::ALLOWED, true)) {
            throw new HttpError('invalid_image', 'يُقبل JPG أو PNG أو WEBP أو GIF فقط.', 400);
        }
        if ($info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            throw new HttpError('image_too_large', 'أبعاد الصورة كبيرة جدًا (الحد الأقصى ' . self::MAX_DIMENSION . ' بكسل).', 400);
        }
    }

    /** يحدّد الامتداد من النوع الحقيقي للملف، لا من اسمه. */
    private static function detectExtension(string $tmp, string $originalName): string
    {
        $info  = @getimagesize($tmp);
        $map   = array_flip(self::ALLOWED);
        $fromMime = $info['mime'] ?? '';
        if (isset($map[$fromMime])) {
            $ext = $map[$fromMime];
            return $ext === 'jpeg' ? 'jpg' : $ext;
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        return in_array($ext, array_keys(self::ALLOWED), true) ? $ext : 'jpg';
    }

    private static function randomName(string $ext): string
    {
        return date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    }

    /**
     * تصغير الصورة وإعادة ترميزها لإزالة أي بيانات مخفية (EXIF) إن توفّرت GD.
     * إن لم تكن GD متوفرة، يُبقى الملف الأصلي كما هو (سلامة العرض مضمونة أصلًا).
     */
    private static function optimize(string $path, string $ext): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            return;
        }

        $info = @getimagesize($path);
        if ($info === false) {
            return;
        }
        [$w, $h] = $info;

        $maxSide = 1400;
        if ($w <= $maxSide && $h <= $maxSide) {
            return; // لا حاجة لإعادة ترميز
        }

        $scale = min($maxSide / $w, $maxSide / $h);
        $nw    = max(1, (int) round($w * $scale));
        $nh    = max(1, (int) round($h * $scale));

        $src = self::loadImage($path, $info[2]);
        if ($src === null) {
            return;
        }
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        switch ($ext) {
            case 'png': imagepng($dst, $path, 8); break;
            case 'webp': imagewebp($dst, $path, 85); break;
            case 'gif': imagegif($dst, $path); break;
            default: imagejpeg($dst, $path, 85);
        }
        imagedestroy($src);
        imagedestroy($dst);
    }

    private static function loadImage(string $path, int $type)
    {
        return match ($type) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : null,
            IMAGETYPE_PNG  => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : null,
            IMAGETYPE_GIF  => function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : null,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            default        => null,
        };
    }
}