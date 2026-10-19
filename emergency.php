<?php

declare(strict_types=1);

/**
 * صفحة الطوارئ السرّية — «كسر الزجاج».
 *
 * تُفتح برابط فيه مفتاح عشوائي وُلد وقت التهيئة (storage/config/emergency.key)،
 * ثم تُطلب بيانات الطوارئ الافتراضية + كلمة التأكيد. عند النجاح:
 *   - تُؤخذ نسخة أمان إجبارية (InstanceService::createArchive)
 *   - تُمحى بيانات النسخة كاملة ويعود الموقع إلى ما قبل أول تهيئة
 *   - يُعرض رابط إعادة البناء بالمفتاح الجديد
 *
 * ما لا تفعله هذه الصفحة:
 *   - لا تظهر إلّا بالمفتاح الصحيح. أي طلب بلا مفتاح أو بمفتاح خاطئ
 *     يردّ 404 نصيًا بلا أي تفصيل — فلا يُكشف وجود الصفحة أصلًا.
 *   - لا تحذف شيئًا بلا نسخة أمان: فشل الأرشفة يوقف العملية.
 *   - لا تُدير المفتاح بنفسها: حذف الملف يُبطل الباب (404)، وإعادة
 *     التدوير من الطرفية عبر: php bin/console admin:reset --force
 */

define('BASE_PATH', __DIR__);
define('LC_IN_SETUP', true);

require_once __DIR__ . '/src/bootstrap.php';

use App\Core\Http\Request;
use App\Core\Logger;
use App\Core\Security\RateLimiter;
use App\Services\EmergencyService;
use App\Services\InstanceService;
use App\Config\ProjectConfig;

$emergency = new EmergencyService();

/* --------------------------------------------------------------------
 * ١. الحارس: لا شيء قبل صحة المفتاح
 * -------------------------------------------------------------------- */

$givenKey = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
if (!$emergency->verifyKey($givenKey)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo "404 Not Found\n";
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$service   = new InstanceService();
$installed = $service->isInstalled();
$request   = Request::capture();

$errors = [];
$report = null;

/* --------------------------------------------------------------------
 * ٢. تنفيذ التدمير عند الطلب
 * -------------------------------------------------------------------- */

if ($request->method() === 'POST' && $installed) {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = trim((string) ($_POST['confirm'] ?? ''));
    $archive  = ($_POST['archive'] ?? '') !== '' && ($_POST['archive'] ?? '') !== '0';

    $locked = false;
    try {
        $locked = RateLimiter::tooManyLoginAttempts($request);
    } catch (\Throwable) {
        $locked = false; // لا قاعدة بيانات: لا قفل، والتدمير نفسه سيفشل بأمان.
    }

    if ($locked) {
        $errors[] = 'تم إيقاف المحاولات مؤقتًا بعد محاولات فاشلة متكرّرة. انتظر قليلًا ثم أعِد المحاولة.';
        Logger::warning('Emergency destroy blocked: too many attempts', ['ip' => $request->ip()]);
    } elseif (!$emergency->verifyCredentials($username, $password)) {
        try {
            RateLimiter::recordLogin($request, $username, false);
        } catch (\Throwable) {
            // تسجيل المحاولة ليس شرطًا لرفضها.
        }
        Logger::warning('Emergency destroy rejected: bad credentials', [
            'ip' => $request->ip(),
            'username' => mb_substr($username, 0, 60),
        ]);
        $errors[] = 'بيانات الدخول غير صحيحة.';
    } elseif (!hash_equals(InstanceService::CONFIRM_WORD, $confirm)) {
        $errors[] = 'عبارة التأكيد غير مطابقة. المطلوب كتابتها حرفيًا: ' . InstanceService::CONFIRM_WORD;
    } else {
        try {
            $report = $service->destroy('', [
                'confirm'      => $confirm,
                'archive'      => $archive,
                'keep_backups' => false,
            ]);
            try {
                RateLimiter::recordLogin($request, $username, true);
            } catch (\Throwable) {
            }
            Logger::warning('Instance destroyed via emergency link', [
                'ip'      => $request->ip(),
                'archive' => $report['archive'],
                'removed' => count($report['removed']),
            ]);
        } catch (\Throwable $e) {
            $errors[] = 'تعذّر التدمير: ' . $e->getMessage();
            Logger::error('Emergency destroy failed', [
                'ip'    => $request->ip(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}

/** ترميز آمن للعرض. */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$defaults       = EmergencyService::defaults();
$baseUrl        = rtrim((string) app_config('app.base_url', ''), '/');
$rebuildKey     = $service->rebuildKey();
$rebuildUrl     = $baseUrl !== '' && $rebuildKey !== null
    ? $baseUrl . '/setup/index.php?key=' . rawurlencode($rebuildKey)
    : '';
$installCommand = 'php bin/console install --url=' . ($baseUrl !== '' ? $baseUrl : 'https://example.com')
    . ' --user=' . $defaults['username'] . ' --pass="' . $defaults['password'] . '" --preset=restaurant';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>وصول الطوارئ</title>
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 24px; min-height: 100vh; display: grid; place-items: center;
    font-family: "Segoe UI", system-ui, Tahoma, sans-serif;
    background: linear-gradient(160deg, #fdf3f1, #f6f0ee); color: #16181d;
  }
  .card {
    width: min(560px, 100%); background: #fff; border: 1px solid #f0d9d4;
    border-radius: 20px; padding: 30px 28px; box-shadow: 0 18px 44px rgba(120,20,20,.12);
  }
  h1 { margin: 0 0 6px; font-size: 1.35rem; color: #b91c1c; }
  p.sub { margin: 0 0 20px; color: #6b7280; font-size: .9rem; line-height: 1.7; }
  label { display: block; margin: 14px 0 6px; font-size: .86rem; font-weight: 600; color: #4b5563; }
  input[type=text], input[type=password] {
    width: 100%; padding: 12px 14px; border: 1px solid #e6e8ec; border-radius: 10px;
    background: #fafbfc; font: inherit; outline: none;
  }
  input:focus { border-color: #b91c1c; box-shadow: 0 0 0 3px rgba(185,28,28,.14); }
  button {
    margin-top: 22px; width: 100%; padding: 14px; border: 0; border-radius: 12px;
    background: #b91c1c; color: #fff; font: inherit; font-weight: 700; cursor: pointer;
  }
  button:hover { background: #991b1b; }
  .alert { border-radius: 12px; padding: 12px 14px; margin-bottom: 16px; font-size: .9rem; line-height: 1.7; }
  .alert.err { background: #fdeded; color: #b91c1c; border: 1px solid #f3c6c6; }
  .alert.warn { background: #fdf6e3; color: #92400e; border: 1px solid #f0dfae; }
  .alert.ok { background: #e9f7ee; color: #15803d; border: 1px solid #bde5c9; }
  .alert ul { margin: 6px 0 0; padding-inline-start: 20px; }
  code {
    background: #f3f4f6; padding: 2px 6px; border-radius: 6px; font-size: .85em;
    direction: ltr; display: inline-block; word-break: break-all;
  }
  .hint { font-size: .82rem; color: #6b7280; margin-top: 6px; line-height: 1.7; }
  label.archive { display: flex; align-items: center; gap: 9px; margin-top: 16px; font-weight: 500; color: #4b5563; }
  label.archive input { width: auto; }
  .kv { font-size: .88rem; color: #374151; line-height: 2; }
  .kv b { color: #16181d; }
</style>
</head>
<body>
<div class="card">
  <h1>وصول الطوارئ</h1>

<?php if ($report !== null): ?>
  <?php /* ─── نجح التدمير ─── */ ?>
  <p class="sub">تم تنفيذ التدمير من رابط الطوارئ.</p>
  <div class="alert ok">
    <strong>تم تدمير النسخة.</strong>
    حُذف <?= count($report['removed']) ?> عنصرًا، والموقع الآن في حالة ما قبل أول تهيئة.
    القالب لم يُمس.
  </div>
  <?php if ($report['archive'] !== ''): ?>
    <div class="alert warn">
      نسخة الأمان في <code><?= $e($report['archive']) ?></code> —
      فيها قاعدة البيانات وبيانات الدخول إن أردت الاسترجاع لاحقًا.
    </div>
  <?php endif; ?>

  <div class="alert ok">
    <strong>احفظ رابط البناء الجديد الآن</strong> — لا يُعرض مرة أخرى:
    <br><code><?= $e($report['rebuildUrl'] !== '' ? $report['rebuildUrl'] : 'setup/index.php?key=' . $report['rebuildKey']) ?></code>
  </div>

  <p class="hint">الخطوة التالية — البناء من جديد:</p>
  <p class="hint">١) افتح رابط البناء أعلاه — مفتاحه إلزامي وبدونه الصفحة مقفلة.</p>
  <p class="hint">٢) أو من الطرفية مباشرة بالبيانات الافتراضية:</p>
  <p><code><?= $e($installCommand) ?></code></p>
  <p class="hint">
    وبعد البناء: <code>php bin/console admin:reset --force</code> يفرض بيانات الدخول
    الافتراضية ويجدّد رابط الطوارئ.
  </p>

<?php elseif (!$installed): ?>
  <?php /* ─── لا شيء لتدميره ─── */ ?>
  <p class="sub">لا توجد نسخة مهيأة لتدميرها — الموقع بالفعل في حالة ما قبل أول تهيئة.</p>
  <div class="alert warn">
    لإعادة البناء: افتح صفحة التهيئة، أو شغّل من الطرفية:
    <br><code><?= $e($installCommand) ?></code>
  </div>
  <?php if ($rebuildUrl !== ''): ?>
    <div class="alert ok">
      رابط البناء (بمفتاح مضبوط):
      <br><code><?= $e($rebuildUrl) ?></code>
    </div>
  <?php endif; ?>

<?php else: ?>
  <?php /* ─── نموذج التدمير ─── */ ?>
  <p class="sub">
    هذه الصفحة مخصّصة للطوارئ فقط. تنفيذ التدمير يمحو كل بيانات النسخة
    (الطلبات، المنتجات، الصور، بيانات الدخول) بعد أخذ نسخة أمان إجبارية،
    ثم يعيد الموقع إلى ما قبل أول تهيئة ويعرض رابط البناء الجديد.
  </p>

  <div class="alert warn">
    تعمل هذه الصفحة بالمفتاح السرّي + بيانات الطوارئ الافتراضية + كلمة التأكيد.
    بيانات الطوارئ الافتراضية:
    <br>اسم المستخدم: <b><?= $e($defaults['username']) ?></b>
    — كلمة المرور: <b><?= $e($defaults['password']) ?></b>
    <br>غيّرها فور استرجاع الوصول:
    <code>php bin/console admin:password --pass="…"</code>
  </div>

  <?php if ($errors !== []): ?>
    <div class="alert err">
      <strong>لم يُحذف شيء:</strong>
      <ul>
        <?php foreach ($errors as $error): ?>
          <li><?= $e($error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <input type="hidden" name="key" value="<?= $e($givenKey) ?>">

    <label for="username">اسم المستخدم</label>
    <input type="text" id="username" name="username" dir="ltr" required
           value="<?= $e((string) ($_POST['username'] ?? $defaults['username'])) ?>">

    <label for="password">كلمة المرور</label>
    <input type="password" id="password" name="password" required>

    <label for="confirm">
      اكتب <code><?= $e(InstanceService::CONFIRM_WORD) ?></code> للتأكيد
    </label>
    <input type="text" id="confirm" name="confirm" required
           placeholder="<?= $e(InstanceService::CONFIRM_WORD) ?>">

    <label class="archive">
      <input type="checkbox" name="archive" value="1" checked>
      حفظ نسخة أمان قبل الحذف
    </label>

    <button type="submit">تدمير النسخة والعودة للبداية</button>
  </form>

  <p class="hint">
    لإبطال هذا الرابط في أي وقت: احذف الملف
    <code>storage/config/emergency.key</code> (يصبح 404)،
    وأعد تفعيله بالأمر <code>php bin/console admin:reset --force</code>.
  </p>
<?php endif; ?>
</div>
</body>
</html>
