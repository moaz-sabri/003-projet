<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\ProjectConfig;
use App\Core\StorageWriter;
use App\Core\Security\Auth;

/**
 * الوصول الطارئ — «كسر الزجاج».
 *
 * ## المشكلة التي يحلّها
 *
 * قد يفقد صاحب النسخة بيانات الدخول (نُسيَت كلمة المرور، أو فسد ملف
 * الإعدادات)، وقد يكون مالك الخادم نفسه غير قادر على فتح قاعدة البيانات
 * يدويًا. حينها لا مخرج من صفحة الويب، ولا من لوحة الإدارة.
 *
 * ## الحل
 *
 * ممرّان مستقلّان، كلاهما **لا يعمل إلّا بإرادة صاحب النسخة**:
 *
 *  ١) من الطرفية: `php bin/console admin:reset --force`
 *     يفرض بيانات الدخول الافتراضية المعروفة ويطبع رابط التدمير السري.
 *
 *  ٢) من الويب عبر رابط سرّي: `emergency.php?key=…`
 *     لا يُفتح إلّا بمفتاح عشوائي يُولَّد وقت التهيئة ويُخزَّن خارج
 *     جذر الويب، وتُطلب معه بيانات الطوارئ الافتراضية + كلمة التأكيد.
 *
 * ## لماذا مفتاح + كلمة مرور معًا؟
 *
 * لأن كلمة المرور الافتراضية مكتوبة في الكود، فهي **معلومة للجميع**.
 * لو كان باب التدمير يكفيه وحده لصار أي شخص يعرف الرابط يمحو النسخة.
 * فالمفتاح العشوائي (32 محرفًا سادسًا) هو السرّ الحقيقي، وكلمة المرور
 * طبقة ثانية لا تُصلح خطأ تسريب المفتاح وحده.
 *
 * ## كيف يُعطَّل؟
 *
 * احذف الملف storage/config/emergency.key فيصبح الرابط بلا أثر (404)،
 * وأعد تدويره متى شئت بالأمر نفسه. ويمكن تعطيل الصفحة كليًا بإنشاء
 * storage/config/emergency.php يحوي `['enabled' => false]`.
 *
 * ## ما لا يفعله هذا الصنف
 *
 * لا يمحو شيئًا بنفسه: التدمير يبقى في InstanceService بكل ضماناته
 * (نسخة أمان إجبارية + مفتاح إعادة بناء). هذا الصنف يفتح الباب ويوثّق
 * المحاولة فقط.
 */
final class EmergencyService
{
    /** اسم المستخدم الافتراضي لحالة الطوارئ. */
    public const DEFAULT_USERNAME = 'admin';

    /**
     * كلمة المرور الافتراضية لحالة الطوارئ.
     *
     * ⚠ معلومة للجميع لأنها في الكود. غيّرها فور استرجاع الوصول.
     */
    public const DEFAULT_PASSWORD = 'Test1234@';

    /** ملف المفتاح السرّي — خارج ما يقرأه المتصفح. */
    public const KEY_FILE = 'storage/config/emergency.key';

    /** ملف تخصيص اختياري: `['enabled'=>bool,'username'=>..,'password_hash'=>..]`. */
    public const OVERRIDE_FILE = 'storage/config/emergency.php';

    /* =====================================================================
     * أ. المسارات
     * ===================================================================== */

    private function root(string $root = ''): string
    {
        return rtrim(
            str_replace('\\', '/', $root !== '' ? $root : ProjectConfig::basePath()),
            '/'
        );
    }

    public function keyPath(string $root = ''): string
    {
        return $this->root($root) . '/' . self::KEY_FILE;
    }

    public function overridePath(string $root = ''): string
    {
        return $this->root($root) . '/' . self::OVERRIDE_FILE;
    }

    /* =====================================================================
     * ب. المفتاح السرّي
     * ===================================================================== */

    /** يعيد المفتاح المحفوظ، أو null إن لم يوجد (أي أن الباب مغلق). */
    public function key(string $root = ''): ?string
    {
        $path = $this->keyPath($root);
        if (!is_file($path)) {
            return null;
        }
        $key = trim((string) @file_get_contents($path));
        return $key === '' ? null : $key;
    }

    /** يولّد مفتاحًا جديدًا (32 محرفًا سادسًا) ويطغى على القديم. */
    public function issueKey(string $root = ''): string
    {
        $key = bin2hex(random_bytes(16));
        StorageWriter::write($this->keyPath($root), $key, 0600);
        return $key;
    }

    /** يضمن وجود مفتاح: يعيده إن وُجد، وإلّا يولّد واحدًا. */
    public function ensureKey(string $root = ''): string
    {
        return $this->key($root) ?? $this->issueKey($root);
    }

    /** يدوّر المفتاح (يُبطل كل رابط سابق). */
    public function rotateKey(string $root = ''): string
    {
        return $this->issueKey($root);
    }

    /** يقارن مفتاحًا واردًا بالمحفوظ مقارنة ثابتة الزمن. */
    public function verifyKey(?string $given, string $root = ''): bool
    {
        if (!$this->enabled($root)) {
            return false;
        }
        $expected = $this->key($root);
        if ($expected === null) {
            return false;
        }
        $given = trim((string) $given);
        return $given !== '' && hash_equals($expected, $given);
    }

    /* =====================================================================
     * ج. الحالة والبيانات
     * ===================================================================== */

    /** هل صفحة الطوارئ مسموحة؟ (ملف التخصيص قد يعطّلها). */
    public function enabled(string $root = ''): bool
    {
        $override = $this->override($root);
        return !(is_array($override) && array_key_exists('enabled', $override) && !$override['enabled']);
    }

    /** هل هناك مفتاح فعّال الآن؟ */
    public function isArmed(string $root = ''): bool
    {
        return $this->enabled($root) && $this->key($root) !== null;
    }

    /**
     * يتحقق من بيانات الدخول الطارئة.
     *
     * القاعدة: إن وُجد تخصيص كامل (اسم + تجزئة) في
     * storage/config/emergency.php فهو **يحلّ محلّ** الافتراضي المعلوم
     * بدل أن يُضاف إليه — حتى لا يبقى «admin / Test1234@» صالحًا بعد أن
     * اختار المالك بيانات خاصة. وإن لم يوجد تخصيص، فالافتراضي المعلوم
     * مقبول. وفي الحالتين تبقى بيانات النسخة الحقيقية مقبولة إن كانت
     * متاحة (من لم يفقد كلمة مروره يستخدمها هي).
     */
    public function verifyCredentials(string $username, string $password, string $root = ''): bool
    {
        $username = trim($username);

        $override   = $this->override($root);
        $overrideOk = is_array($override)
            && (string) ($override['username'] ?? '') !== ''
            && (string) ($override['password_hash'] ?? '') !== '';

        if ($overrideOk) {
            if (hash_equals((string) $override['username'], $username)
                && password_verify($password, (string) $override['password_hash'])) {
                return true;
            }
        } elseif (hash_equals(self::DEFAULT_USERNAME, $username)
            && hash_equals(self::DEFAULT_PASSWORD, $password)) {
            return true;
        }

        // بيانات النسخة الحقيقية — لا تُقرأ إلّا في السياق الحيّ (لا في اختبار رمل).
        if ($root === '') {
            try {
                if (Auth::checkCredentials($username, $password)) {
                    return true;
                }
            } catch (\Throwable) {
                // لا قاعدة بيانات أو لا إعدادات: نُكمل بلا انهيار.
            }
        }

        return false;
    }

    /**
     * يبني رابط التدمير الكامل من رابط الموقع المحفوظ.
     * يعيد '' إن لم يُعرف النطاق أو لم يوجد مفتاح.
     */
    public function url(string $root = ''): string
    {
        $key = $this->key($root);
        if ($key === null) {
            return '';
        }
        $base = rtrim((string) app_config('app.base_url', ''), '/');
        if ($base === '') {
            return '';
        }
        return $base . '/emergency.php?key=' . rawurlencode($key);
    }

    /** وصف مختصر للعرض في الطرفية. @return array{username:string,password:string} */
    public static function defaults(): array
    {
        return ['username' => self::DEFAULT_USERNAME, 'password' => self::DEFAULT_PASSWORD];
    }

    /** @return array<string,mixed>|null */
    private function override(string $root = ''): ?array
    {
        $path = $this->overridePath($root);
        if (!is_file($path)) {
            return null;
        }
        $config = @include $path;
        return is_array($config) ? $config : null;
    }
}
