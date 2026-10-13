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
            mkdir($dir, 0775, true);
        }
        $now   = time();
        $state = ['start' => $now, 'count' => 0];
        if (is_file($file)) {
            $raw = json_decode((string) file_get_contents($file), true);
            if (is_array($raw) && isset($raw['start'], $raw['count'])) {
                $state = $raw;
                if ($now - (int) $raw['start'] >= 60) {
                    $state = ['start' => $now, 'count' => 0];
                }
            }
        }
        $state['count']++;
        file_put_contents($file, json_encode($state), LOCK_EX);

        return $state['count'] <= $limitPerMinute;
    }

    public static function clearLoginAttempts(Request $request): void
    {
        Database::delete('login_attempts', 'ip = :ip AND success = 0', ['ip' => $request->ip()]);
    }
}