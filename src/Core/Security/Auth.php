<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Config\ProjectConfig;

/**
 * جلسة المدير الواحد: تهيئة آمنة، تجديد المعرّف بعد الدخول، وانتهاء زمني.
 * تُخزَّن بيانات الدخول في storage/config/local.php فقط.
 */
final class Auth
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? '') === '443';

        session_name(ProjectConfig::ADMIN_SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        session_start();
        self::$started = true;

        self::enforceIdleTimeout();
    }

    private static function enforceIdleTimeout(): void
    {
        if (empty($_SESSION['admin_id'])) {
            return;
        }
        $now  = time();
        $idle = (int) ($_SESSION['last_seen'] ?? $now);
        $max  = (int) ($_SESSION['started_at'] ?? $now);

        $idleLimit = ProjectConfig::ADMIN_SESSION_IDLE_MIN * 60;
        $maxLimit  = ProjectConfig::ADMIN_SESSION_MAX_MIN * 60;

        if ($now - $idle > $idleLimit || $now - $max > $maxLimit) {
            self::logout();
            return;
        }
        $_SESSION['last_seen'] = $now;
    }

    public static function checkCredentials(string $username, string $password): bool
    {
        $expectedUser = (string) app_config('admin.username', '');
        $hash         = (string) app_config('admin.password_hash', '');

        if ($expectedUser === '' || $hash === '') {
            return false;
        }
        // مقارنة ثابتة الزمن لتفادي هجمات التوقيت على اسم المستخدم
        $userOk = hash_equals($expectedUser, $username);
        $passOk = password_verify($password, $hash);
        return $userOk && $passOk;
    }

    public static function login(string $username): void
    {
        self::start();
        session_regenerate_id(true);           // منع تثبيت معرّف الجلسة
        $_SESSION['admin_id']       = 1;
        $_SESSION['admin_username'] = $username;
        $_SESSION['started_at']     = time();
        $_SESSION['last_seen']      = time();
        $_SESSION['csrf']           = bin2hex(random_bytes(32));
    }

    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
            }
            session_destroy();
        }
    }

    public static function check(): bool
    {
        self::start();
        return !empty($_SESSION['admin_id']);
    }

    public static function username(): string
    {
        return (string) ($_SESSION['admin_username'] ?? '');
    }

    public static function token(): string
    {
        return (string) ($_SESSION['csrf'] ?? '');
    }

    /** تغيير كلمة المرور (يتطلب الجلسة الحالية). */
    public static function changePassword(string $newPassword): void
    {
        $configFile = app_config_file();
        $hash       = password_hash($newPassword, PASSWORD_DEFAULT);
        $php        = "<?php\n// ملف إعدادات محلي — لا يُرفع إلى أي مستودع ولا يُخدم عبر الويب.\nreturn "
                    . var_export([
                        'installed_at' => date('c'),
                        'debug'        => false,
                        'app_key'      => bin2hex(random_bytes(16)),
                        'app'          => ['base_url' => (string) app_config('app.base_url', '')],
                        'db'           => ['path' => (string) app_config('db.path', BASE_PATH . '/storage/database/app.sqlite')],
                        'admin'        => [
                            'username'      => (string) app_config('admin.username', ''),
                            'password_hash' => $hash,
                            'updated_at'    => date('c'),
                        ],
                    ], true)
                    . ";\n";
        \App\Core\StorageWriter::write($configFile, $php, 0640);
    }
}