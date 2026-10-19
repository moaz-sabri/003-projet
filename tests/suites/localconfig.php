<?php

declare(strict_types=1);

use App\Core\LocalConfig;
use App\Core\Security\Auth;

/**
 * كاتب الإعدادات المحلي وتغيير كلمة المرور.
 *
 * لا نلمس ملف الاختبار المشترك (tests/run.php يوجّه LC_CONFIG_FILE إليه):
 * نوجّه المتغير مؤقتًا إلى ملف خاص، ثم نعيده كما كان في finally — حتى لو
 * فشل اختبار في المنتصف لا تتلوّث بقية المجموعات.
 */

return static function (): void {
    Assert::group('كاتب الإعدادات المحلي');

    $original = getenv('LC_CONFIG_FILE');
    $tmp = sys_get_temp_dir() . '/lc-localcfg-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.php';
    file_put_contents(
        $tmp,
        "<?php\nreturn ['installed_at' => 'x', 'debug' => true, 'admin' => ['username' => 'owner', 'password_hash' => 'old']];\n"
    );
    putenv('LC_CONFIG_FILE=' . $tmp);

    try {
        Assert::same($tmp, LocalConfig::path(), 'المسار هو app_config_file نفسه');
        Assert::same('owner', LocalConfig::read()['admin']['username'], 'القراءة تعيد المحتوى');

        $path = LocalConfig::update(static function (array $config): array {
            $config['admin']['password_hash'] = 'new-hash';
            return $config;
        });
        Assert::same($tmp, $path, 'التحديث يعيد مسار الملف');

        $after = LocalConfig::read();
        Assert::same('new-hash', $after['admin']['password_hash'], 'التحديث يغيّر الحقل المطلوب');
        Assert::same('owner', $after['admin']['username'], 'التحديث يحفظ بقية حقول admin');
        Assert::same(true, $after['debug'], 'التحديث لا يعيد ضبط مفاتيح أخرى');
        Assert::same('x', $after['installed_at'], 'التحديث يحفظ installed_at');

        /* -----------------------------------------------------------------
         * تغيير كلمة المرور لا يُسقط الإعدادات الأخرى
         * ----------------------------------------------------------------- */
        $pwFile = sys_get_temp_dir() . '/lc-localcfg-pw-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents(
            $pwFile,
            "<?php\nreturn ['installed_at' => 'y', 'debug' => true, 'app_key' => 'KEEP-ME', "
            . "'app' => ['base_url' => 'https://test.local'], "
            . "'admin' => ['username' => 'owner', 'password_hash' => 'old']];\n"
        );
        putenv('LC_CONFIG_FILE=' . $pwFile);

        Auth::changePassword('BrandNewPass9!');
        $config = LocalConfig::read();

        Assert::true(
            password_verify('BrandNewPass9!', (string) ($config['admin']['password_hash'] ?? '')),
            'تغيير كلمة المرور يكتب تجزئة صحيحة'
        );
        Assert::false(
            password_verify('old', (string) ($config['admin']['password_hash'] ?? '')),
            'التجزئة القديمة لم تعد صالحة'
        );
        Assert::same('owner', $config['admin']['username'], 'تغيير كلمة المرور يحفظ اسم المستخدم');
        Assert::same('KEEP-ME', $config['app_key'], 'تغيير كلمة المرور لا يجدّد app_key');
        Assert::same(true, $config['debug'], 'تغيير كلمة المرور لا يُطفئ debug');
        Assert::same('https://test.local', $config['app']['base_url'], 'تغيير كلمة المرور يحفظ base_url');

        @unlink($pwFile);
    } finally {
        @unlink($tmp);
        if ($original === false) {
            putenv('LC_CONFIG_FILE');
        } else {
            putenv('LC_CONFIG_FILE=' . $original);
        }
    }
};
