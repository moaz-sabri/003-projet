<?php

declare(strict_types=1);

/**
 * وصول الطوارئ — عقد «كسر الزجاج».
 *
 * كل الاختبارات تعمل على مجلد رمل مؤقت لا على ملفات المشروع: مفاتيح
 * الطوارئ أسرار، وأسوأ ما يمكن أن يفعله اختبار هو كتابة مفتاح في
 * storage/config الحقيقي أو تعطيل الباب الحقيقي.
 */

use App\Services\EmergencyService;

return static function (): void {
    $root = sys_get_temp_dir() . '/lc-emergency-' . getmypid() . '-' . bin2hex(random_bytes(3));
    @mkdir($root . '/storage/config', 0775, true);

    $cleanup = static function () use ($root): void {
        if (!is_dir($root)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($root);
    };

    $service = new EmergencyService();

    /* =====================================================================
     * ١. البيانات الافتراضية
     * ===================================================================== */
    Assert::group('وصول الطوارئ — البيانات الافتراضية');

    $defaults = EmergencyService::defaults();
    Assert::same('admin', EmergencyService::DEFAULT_USERNAME, 'الثابت: اسم المستخدم admin');
    Assert::same('Test1234@', EmergencyService::DEFAULT_PASSWORD, 'الثابت: كلمة المرور Test1234@');
    Assert::same('admin', $defaults['username'], 'defaults(): اسم المستخدم');
    Assert::same('Test1234@', $defaults['password'], 'defaults(): كلمة المرور');

    Assert::true($service->verifyCredentials('admin', 'Test1234@', $root), 'الافتراضية تُقبل');
    Assert::true($service->verifyCredentials('  admin  ', 'Test1234@', $root), 'المسافات حول الاسم لا تمنع');
    Assert::false($service->verifyCredentials('admin', 'wrong', $root), 'كلمة مرور خاطئة تُرفض');
    Assert::false($service->verifyCredentials('root', 'Test1234@', $root), 'اسم مستخدم خاطئ يُرفض');
    Assert::false($service->verifyCredentials('', '', $root), 'الفراغ يُرفض');

    // بيانات النسخة الحقيقية (قراءة فقط عبر المصادقة) تبقى مقبولة.
    Assert::true(
        $service->verifyCredentials('tester', 'TestPass123!'),
        'بيانات النسخة الحقيقية تُقبل عبر المصادقة'
    );
    Assert::false(
        $service->verifyCredentials('tester', 'خاطئة'),
        'كلمة مرور نسخة خاطئة تُرفض'
    );

    /* =====================================================================
     * ٢. المفتاح السرّي
     * ===================================================================== */
    Assert::group('وصول الطوارئ — المفتاح السرّي');

    Assert::same(null, $service->key($root), 'قبل التوليد: لا مفتاح');
    Assert::false($service->isArmed($root), 'قبل التوليد: الباب مغلق');
    Assert::false($service->verifyKey('anything', $root), 'بلا مفتاح: أي قيمة تُرفض');
    Assert::false($service->verifyKey(null, $root), 'بلا مفتاح: null يُرفض');
    Assert::same('', $service->url($root), 'بلا مفتاح: لا رابط');

    $key = $service->issueKey($root);
    Assert::same(32, strlen($key), 'المفتاح 32 محرفًا');
    Assert::same(1, preg_match('/^[0-9a-f]{32}$/', $key), 'المفتاح سداسي عشري فقط');
    Assert::true(is_file($root . '/' . EmergencyService::KEY_FILE), 'ملف المفتاح مكتوب في مساره');
    Assert::same($key, $service->key($root), 'المفتاح يُقرأ كما كُتب');
    Assert::true($service->isArmed($root), 'بالمفتاح: الباب مفعّل');

    Assert::false($service->verifyKey('', $root), 'مفتاح فارغ يُرفض');
    Assert::false($service->verifyKey('deadbeef', $root), 'مفتاح خاطئ يُرفض');
    Assert::false($service->verifyKey(substr($key, 0, 31), $root), 'مفتاح ناقص حرفًا يُرفض');
    Assert::false($service->verifyKey($key . 'x', $root), 'مفتاح زائد حرفًا يُرفض');
    Assert::true($service->verifyKey($key, $root), 'المفتاح الصحيح يُقبل');
    Assert::true($service->verifyKey('  ' . $key . '  ', $root), 'المسافات الطرفية لا تمنع');

    Assert::same($key, $service->ensureKey($root), 'ensureKey لا يبدّل مفتاحًا موجودًا');

    $newKey = $service->rotateKey($root);
    Assert::notSame($key, $newKey, 'التدوير ينتج مفتاحًا جديدًا');
    Assert::false($service->verifyKey($key, $root), 'المفتاح القديم يبطل بعد التدوير');
    Assert::true($service->verifyKey($newKey, $root), 'المفتاح الجديد يُقبل');

    /* =====================================================================
     * ٣. رابط التدمير
     * ===================================================================== */
    Assert::group('وصول الطوارئ — رابط التدمير');

    $url = $service->url($root);
    Assert::contains($url, '/emergency.php?key=', 'الرابط يشير إلى emergency.php مع المفتاح');
    Assert::contains($url, rawurlencode($newKey), 'الرابط يحمل المفتاح نفسه');

    /* =====================================================================
     * ٤. التخصيص والتعطيل
     * ===================================================================== */
    Assert::group('وصول الطوارئ — التخصيص والتعطيل');

    // تخصيص كامل: يحلّ محلّ الافتراضي المعلوم بدل أن يُضاف إليه.
    file_put_contents(
        $root . '/' . EmergencyService::OVERRIDE_FILE,
        "<?php\nreturn " . var_export([
            'username'      => 'boss',
            'password_hash' => password_hash('S3cret!pass', PASSWORD_DEFAULT),
        ], true) . ";\n"
    );
    Assert::true($service->verifyCredentials('boss', 'S3cret!pass', $root), 'تخصيص: البيانات المخصّصة تُقبل');
    Assert::false($service->verifyCredentials('admin', 'Test1234@', $root), 'تخصيص: الافتراضية المعلومة لم تعد تُقبل');
    Assert::true($service->verifyKey($newKey, $root), 'التخصيص لا يعطّل الباب');
    @unlink($root . '/' . EmergencyService::OVERRIDE_FILE);

    Assert::true($service->verifyCredentials('admin', 'Test1234@', $root), 'بعد إزالة التخصيص: الافتراضية تعمل مجددًا');

    // تعطيل كامل بلا بيانات اعتماد بديلة.
    file_put_contents($root . '/' . EmergencyService::OVERRIDE_FILE, "<?php\nreturn ['enabled' => false];\n");
    Assert::false($service->enabled($root), 'التعطيل: enabled = false');
    Assert::false($service->isArmed($root), 'التعطيل: الباب مغلق رغم وجود المفتاح');
    Assert::false($service->verifyKey($newKey, $root), 'التعطيل: حتى المفتاح الصحيح يُرفض');
    @unlink($root . '/' . EmergencyService::OVERRIDE_FILE);

    Assert::true($service->verifyKey($newKey, $root), 'بعد إزالة التعطيل: المفتاح يعمل مجددًا');

    /* =====================================================================
     * ٥. إبطال الباب بحذف المفتاح
     * ===================================================================== */
    Assert::group('وصول الطوارئ — إبطال الباب');

    @unlink($root . '/' . EmergencyService::KEY_FILE);
    Assert::false($service->isArmed($root), 'حذف المفتاح يُبطل الباب');
    Assert::false($service->verifyKey($newKey, $root), 'بعد الحذف: المفتاح لم يعد يعمل');
    Assert::same('', $service->url($root), 'بعد الحذف: لا رابط');

    $cleanup();
};
