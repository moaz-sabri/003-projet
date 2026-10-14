<?php

declare(strict_types=1);

namespace App\Core\Web;

/**
 * مسارات الموقع كما يراها المتصفح.
 *
 * القاعدة: كل رابط في المشروع يُبنى من هذا الصنف بدل كتابة مسارات ثابتة،
 * ليعمل القالب في أي من الحالتين بلا تعديل:
 *   1) على جذر النطاق            → "/"        (مثال: https://example.com)
 *   2) داخل مجلد فرعي على الاستضافة → "/shop/"  (مثال: https://example.com/shop)
 *
 * ملاحظة: webRoot() في ProjectConfig يعيد مسارًا نسبيًا ("../") وهو مخصص
 * لعناصر HTML التي تُبنى داخل قوالب موجودة مسبقًا. هذا الصنف يعيد مسارات
 * مطلقة صالحة للاستخدام في JavaScript وروابط QR.
 */
final class Paths
{
    /**
     * جذر مجلد السكربت الحالي، بشرطة مائلة أخيرة.
     *
     * @param int $levelsUp عدد المستويات للأعلى (0 لجذر المشروع، 1 لمجلد
     *                      فرعي مثل admin/ أو setup/).
     */
    public static function base(int $levelsUp = 0): string
    {
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));

        // في CLI أو الإعدادات غير المعتادة قد لا يوجد SCRIPT_NAME — نفترض الجذر.
        if ($dir === '' || $dir === '.' || $dir[0] !== '/') {
            $dir = '/';
        }

        $dir = rtrim($dir, '/');

        for ($i = 0; $i < max(0, $levelsUp); $i++) {
            $pos = strrpos($dir, '/');
            $dir = $pos === false ? '' : substr($dir, 0, $pos);
        }

        return $dir === '' ? '/' : $dir . '/';
    }

    /**
     * رابط مطلق داخل جذر المشروع.
     *
     * @param string   $path     مسار نسبي مثل 'app/css/app.css'
     * @param int      $levelsUp عدد المستويات من موضع السكربت (1 للسكربتات
     *                          داخل مجلد فرعي مثل admin/ و setup/)
     */
    public static function url(string $path = '', int $levelsUp = 0): string
    {
        return self::base($levelsUp) . ltrim($path, '/');
    }

    /** ملفات واجهة الزبون (/app/...). */
    public static function shop(string $path = '', int $levelsUp = 0): string
    {
        return self::url('app/' . ltrim($path, '/'), $levelsUp);
    }

    /** نقطة الدخول للـ API. */
    public static function api(int $levelsUp = 0): string
    {
        return self::url('api/index.php', $levelsUp);
    }

    /** رابط لوحة الإدارة (يضمن الشرطة الأخيرة ليعمل DirectoryIndex). */
    public static function admin(int $levelsUp = 0): string
    {
        return self::url('admin/', $levelsUp);
    }

    /** عامل الخدمة — على جذر الموقع ليشمل نطاق /api/ أيضًا. */
    public static function serviceWorker(int $levelsUp = 0): string
    {
        return self::url('sw.js', $levelsUp);
    }

    /** رابط القائمة بدون أي اسم ملف: "/" أو "/shop/". */
    public static function home(int $levelsUp = 0): string
    {
        return self::url('', $levelsUp);
    }

    /**
     * تحويل مسار الطلب إلى مسار داخلي (بدون جذر الموقع ولا شرطة أولى).
     * مثال: base="/" و REQUEST_URI="/cart" → "cart"
     */
    public static function route(): string
    {
        $path = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = explode('?', $path, 2)[0];
        $path = rawurldecode($path);

        $base = self::base();
        if ($base !== '/' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        } elseif ($base !== '/' && $path === rtrim($base, '/')) {
            $path = '/';
        }

        return trim($path, '/');
    }

    /**
     * هل يبدو المسار كملف (له امتداد)؟
     * هذه القاعدة تمنع تحويل طلب ملف مفقود (صورة أو سكربت) إلى صفحة HTML،
     * فلا يتحوّل خطأ 404 حقيقي إلى صفحة صامتة.
     */
    public static function looksLikeFile(string $route): bool
    {
        $last = basename($route);
        return (bool) preg_match('/\.[A-Za-z0-9]{1,8}$/', $last);
    }

    /** رابط مطلق لمورد داخل مجلد الرفع العام. */
    public static function upload(string $file, int $levelsUp = 0): string
    {
        return self::shop('uploads/' . ltrim($file, '/'), $levelsUp);
    }
}