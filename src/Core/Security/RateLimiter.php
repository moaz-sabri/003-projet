<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Database;
use App\Core\Http\Request;

/**
 * تحديد محاولات تسجيل الدخول ومنع إساءة استخدام واجهة إنشاء الطلبات.
 * يعتمد على جدول login_attempts داخل SQLite (بلا خدمات خارجية).
 */
final class RateLimiter
{
    /** نافذة العدّ بالثواني. لا علاقة لها بحدّ الطلبات (وهو عدد/دقيقة في ProjectConfig). */
    public const WINDOW_SECONDS = 60;

    /** كل كم استدعاء نُشغّل تنظيف ملفات العدّ المنتهية. */
    private const CLEANUP_EVERY = 50;

    public static function tooManyLoginAttempts(Request $request): bool
    {
        $ip      = $request->ip();
        $since   = date('Y-m-d H:i:s', time() - \App\Config\ProjectConfig::ADMIN_LOCKOUT_MINUTES * 60);
        $fails   = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND success = 0 AND created_at >= :since',
            ['ip' => $ip, 'since' => $since]
        );
        return $fails >= \App\Config\ProjectConfig::ADMIN_MAX_LOGIN_ATTEMPTS;
    }

    public static function recordLogin(Request $request, string $username, bool $success): void
    {
        Database::insert('login_attempts', [
            'username'   => mb_substr($username, 0, 60),
            'ip'         => $request->ip(),
            'success'    => $success ? 1 : 0,
            'created_at' => Database::now(),
        ]);
        // تنظيف السجلات القديمة (بعد يومين)
        Database::run('DELETE FROM login_attempts WHERE created_at < :before', [
            'before' => date('Y-m-d H:i:s', time() - 2 * 86400),
        ]);
    }

    /** حماية خفيفة ضد إغراق واجهة الطلبات من نفس العنوان. */
    public static function hitPublicApi(Request $request, string $bucket, int $limitPerMinute = 30): bool
    {
        $key   = substr($bucket . '|' . $request->ip(), 0, 120);
        $file  = BASE_PATH . '/storage/tmp/rate-' . sha1($key) . '.json';
        $dir   = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $now   = time();

        // القفل على نفس الملف للقراءة والكتابة معًا. القراءة كانت خارج القفل،
        // فكان N طلب متزامن يقرأ العدّ نفسه ويكتبه (lost update) والحدّ الفعلي
        // ≈ الحدّ × N — أي تجاوز كامل للمحدّد تحت أي حمل حقيقي.
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            // تعذّر الفتح (قرص ممتلئ مثلًا): نسمح بدل تعطيل الحماية كلها بصمت.
            error_log('RateLimiter: تعذّر فتح ملف العدّ ' . $file);
            return true;
        }

        $allowed = true;
        if (flock($handle, LOCK_EX)) {
            $state = ['start' => $now, 'count' => 0];
            $raw   = stream_get_contents($handle);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['start'], $decoded['count'])) {
                    $state = $decoded;
                    if ($now - (int) $decoded['start'] >= self::WINDOW_SECONDS) {
                        $state = ['start' => $now, 'count' => 0];
                    }
                }
            }
            $state['count'] = (int) $state['count'] + 1;
            $allowed = (int) $state['count'] <= $limitPerMinute;

            // الكتابة داخل القفل نفسه + تفريغ المخزن قبلها، وإلا تبقّت
            // البايتات القديمة فتصبح JSON تالفًا.
            $encoded = json_encode($state);
            if ($encoded !== false && ftruncate($handle, 0) && rewind($handle)) {
                fwrite($handle, $encoded);
                fflush($handle);
            }
            flock($handle, LOCK_UN);
        } else {
            error_log('RateLimiter: تعذّر قفل ملف العدّ ' . $file);
        }
        fclose($handle);

        // تنظيف دوري للملفات المنتهية — بدونه ينمو المجلد بلا حدّ: ملف واحد
        // لكل (bucket|IP) إلى الأبد، وفضاء عناوين IPv6 يكاد يكون لا نهائيًا.
        if (random_int(1, self::CLEANUP_EVERY) === 1) {
            self::sweepTmp($now);
        }

        return $allowed;
    }

    /** يحذف ملفات العدّ التي انتهت نافذتها من storage/tmp. */
    private static function sweepTmp(int $now): void
    {
        $dir = BASE_PATH . '/storage/tmp';
        if (!is_dir($dir)) {
            return;
        }
        $files = @glob($dir . '/rate-*.json') ?: [];
        foreach ($files as $f) {
            if ($now - (int) @filemtime($f) < self::WINDOW_SECONDS) {
                continue;
            }
            $raw = @file_get_contents($f);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['start'])
                    && $now - (int) $decoded['start'] < self::WINDOW_SECONDS
                ) {
                    continue;   // ما زال داخل نافذته
                }
            }
            @unlink($f);
        }
    }

    public static function clearLoginAttempts(Request $request): void
    {
        Database::delete('login_attempts', 'ip = :ip AND success = 0', ['ip' => $request->ip()]);
    }
}