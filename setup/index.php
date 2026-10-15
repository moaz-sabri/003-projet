<?php

declare(strict_types=1);

/**
 * معالج التهيئة عبر الويب — يعمل مرة واحدة فقط.
 * بعد إنشاء ملف الإعدادات، يرفض العمل نهائيًا.
 *
 * يعمل على استضافة PHP عادية دون الحاجة إلى SSH أو Composer.
 */

declare(strict_types=1);

// لا نعتمد على bootstrap لأنه يحوّل إلى هذه الصفحة عند غياب الإعدادات.
// لكن app_config_file() معرّفة في Autoloader، فنحمّل ذلك الملف مع عَلَم
// يمنع التوجيه الذاتي. والمحمّل المكرر الذي كان هنا كان سبب خطأ قاتل
// في كل طلب يصل إلى صفحة التهيئة.
define('BASE_PATH', dirname(__DIR__));
define('LC_IN_SETUP', true);

require_once BASE_PATH . '/src/Core/Autoloader.php';

use App\Core\Web\Paths;

$configFile = app_config_file();
$installed  = is_file($configFile);

$errors  = [];
$warnings = [];
$success = false;
$generatedPassword = '';
$securityAlert = null;

/**
 * أنواع النشاط الجاهزة — المصدر الوحيد لتسميات المشروع ووضعه.
 * تُقرأ مباشرة من BusinessPresets حتى لا تتكرر القوائم في مكانين.
 *
 * @var array<string, array<string, mixed>>
 */
$presets = \App\Config\BusinessPresets::all();

/** يبني مسوّدة company.json من إجابات التهيئة (نفس البنية في app/data/company.json). */
$buildCompanyJson = static function (array $preset, string $name, string $tagline): array {
    $doc = [
        'schema_version' => \App\Core\CompanyProfile::SCHEMA_VERSION,
        'brand' => [
            'name'        => $name,
            'tagline'     => $tagline,
            'logo'        => '',
            'hero_image'  => '',
            'cover_image' => '',
        ],
        'about' => [
            'summary'    => 'اكتب هنا نبذة من سطرين تصف نشاطك: ماذا تقدّم ولمن.',
            'story'      => '',
            'highlights' => [],
            'gallery'    => [],
        ],
        'contact' => [
            'phone' => '', 'whatsapp' => '', 'email' => '',
            'instagram' => '', 'facebook' => '', 'telegram' => '',
            'tiktok' => '', 'youtube' => '', 'address' => '', 'map_url' => '',
            'working_hours' => [],
        ],
        'sections' => [],
        'cta' => [
            'title'        => 'جاهز للبدء؟',
            'text'         => 'تواصل معنا مباشرة وسنرد عليك في أقرب وقت.',
            'button_label' => 'تواصل معنا',
            'button_url'   => '',
        ],
        'seo' => ['title' => '', 'description' => '', 'keywords' => ''],
        'footer' => ['note' => '', 'links' => []],
    ];

    // نبذة واحدة قابلة للتعديل: تُملأ مرة واحدة في كل المشاريع
    if (($preset['mode'] ?? '') === \App\Config\BusinessPresets::MODE_CATALOG) {
        $doc['sections'][] = [
            'icon'    => 'users',
            'title'   => 'من نحن؟',
            'body'    => 'اكتب هنا قصتك: منذ متى تعمل، وما الذي يقدّمه نشاطك بالضبط. '
                       . 'هذه الفقرة هي أول ما يقرؤه الزائر، فاجعلها واضحة ومختصرة.',
            'bullets' => [],
        ];
    }

    return $doc;
};

/** كتابة ملف الإعدادات المحلية بصلاحيات آمنة. */
$writeConfig = static function (array $config) use ($configFile): void {
    $dir = dirname($configFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $php = "<?php\n"
         . "// إعدادات محلية للنسخة — لا ترفع هذا الملف إلى أي مستودع ولا تخدمه عبر الويب.\n"
         . "return " . var_export($config, true) . ";\n";
    \App\Core\StorageWriter::write($configFile, $php, 0640);
};

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $url     = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
    $user    = trim((string) ($_POST['username'] ?? ''));
    $pass    = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if ($url === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url    = $scheme . '://' . $host;
    }
    if (!preg_match('#^https?://#i', $url)) {
        $errors[] = 'رابط الموقع يجب أن يبدأ بـ http:// أو https://';
    }
    if ($user === '' || mb_strlen($user) < 3) {
        $errors[] = 'اسم المستخدم يجب ألا يقل عن 3 محارف.';
    }
    if (mb_strlen($pass) < 8) {
        $errors[] = 'كلمة المرور يجب ألا تقل عن 8 محارف.';
    }
    if ($pass !== $confirm) {
        $errors[] = 'كلمتا المرور غير متطابقتين.';
    }
    if (!is_writable(BASE_PATH . '/storage')) {
        $errors[] = 'مجلد storage غير قابل للكتابة. أعطِه صلاحية 755 أو 775 من لوحة الاستضافة.';
    }

    // نوع النشاط: يحدد الوضع (طلبات أو عرض فقط) وكل التسميات
    $presetId = trim((string) ($_POST['business_preset'] ?? ''));
    if (!isset($presets[$presetId])) {
        $presetId = \App\Config\BusinessPresets::defaultId();
    }
    $preset = $presets[$presetId];

    $companyName = trim((string) ($_POST['company_name'] ?? ''));
    if ($companyName === '') {
        $errors[] = 'اسم النشاط مطلوب — يظهر في رأس الصفحة ورسائل QR.';
    } elseif (mb_strlen($companyName) > 80) {
        $errors[] = 'اسم النشاط طويل جدًا (80 محرفًا كحد أقصى).';
    }

    $companyTagline = mb_substr(trim((string) ($_POST['company_tagline'] ?? '')), 0, 160);

    if ($errors === []) {
        $writeConfig([
            'installed_at' => date('c'),
            'debug'        => false,
            'app_key'      => bin2hex(random_bytes(16)),
            'app'          => ['base_url' => $url],
            'db'           => ['path' => BASE_PATH . '/storage/database/app.sqlite'],
            'admin'        => [
                'username'      => $user,
                'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'updated_at'    => date('c'),
            ],
        ]);

        // إنشاء المخطط
        try {
            require_once BASE_PATH . '/src/bootstrap.php';
            \App\Core\Migrations::migrate();

            // اختيار المستخدم يبقى قراره هو: نحفظ معرّفه ثم نطبّقه،
            // فلا تُستبدل تسمياتُه بما في الثوابت.
            \App\Config\ProjectConfig::setOverrides(\App\Config\BusinessPresets::toOverrides($presetId));

            $settings = \App\Config\ProjectConfig::defaultRuntimeSettings();
            $settings['app_name']        = $companyName;
            $settings['app_tagline']     = $companyTagline;
            $settings['welcome_message'] = '';
            $settings['show_welcome']    = '0'; // الغلاف بدل صفحة الترحيب القديمة
            $settings['business_preset'] = $presetId;
            \App\Core\RuntimeSettings::setMany($settings);
            \App\Core\RuntimeSettings::flush();

            // ملف معلومات الشركة: لا نكتبه إن كان المستخدم كتبه بنفسه
            if (!\App\Core\CompanyProfile::exists()) {
                $companyJson = BASE_PATH . '/app/data/company.json';
                if (!is_dir(dirname($companyJson))) {
                    mkdir(dirname($companyJson), 0775, true);
                }
                $doc = $buildCompanyJson($preset, $companyName, $companyTagline);
                $encoded = json_encode(
                    $doc,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                if (!\App\Core\StorageWriter::write($companyJson, $encoded . "\n", 0644)) {
                    $warnings[] = 'تعذّر إنشاء ملف معلومات الشركة (app/data/company.json). '
                        . 'أنشئه يدويًا أو امنح المجلد صلاحية 755.';
                }
            }

            foreach (['uploads', 'backups', 'logs', 'tmp'] as $folder) {
                $path = BASE_PATH . '/storage/' . $folder;
                if (!is_dir($path)) {
                    mkdir($path, 0775, true);
                }
            }
            // توليد الأيقونات اختياري: نقص الصلاحيات لا يجب أن يُفشل التهيئة كلها.
// إن فشل، يمكن توليدها لاحقًا بالأمر: php bin/console icons
try {
    require_once BASE_PATH . '/src/Tools/IconGenerator.php';
    \App\Tools\IconGenerator::generateAll();
} catch (Throwable $e) {
    $warnings[] = 'تعذّر توليد أيقونات PWA تلقائيًا: ' . $e->getMessage()
        . ' — ولّدها لاحقًا بالأمر: php bin/console icons';
}

            // لا نُعلن النجاح قبل التأكد أن الخادم يحترم .htaccess.
            // بعض الاستضافات تُهيّأ بـ AllowOverride None فتُتجاهَل الحماية بصمت،
            // وتصبح قاعدة البيانات (بكل الطلبات وأسماء الزبائن) قابلة للتحميل.
            //
            // نجرب عدة عناوين لأن الخادم قد لا يستطيع الوصول إلى رابطه العلني
            // من داخل نفسه (سلوك شائع في الحاويات وبيئات الاستضافة).
            $candidates = [$url];

            $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $serverPort = (string) ($_SERVER['SERVER_PORT'] ?? '');

            if (!empty($_SERVER['HTTP_HOST'])) {
                $candidates[] = $scheme . '://' . $_SERVER['HTTP_HOST'];
            }
            if (!empty($_SERVER['SERVER_ADDR'])) {
                $candidates[] = $scheme . '://' . $_SERVER['SERVER_ADDR'];
                if ($serverPort !== '') {
                    $candidates[] = $scheme . '://' . $_SERVER['SERVER_ADDR'] . ':' . $serverPort;
                }
            }

            // عناوين بلا منفذ: تعمل على الإعداد الافتراضي (80) حتى لو كانت
            // قيمة SERVER_PORT منقولة من ترويسة Host لا تطابق منفذ الخادم الفعلي.
            $candidates[] = $scheme . '://127.0.0.1';
            $candidates[] = $scheme . '://localhost';
            if ($serverPort !== '') {
                $candidates[] = $scheme . '://127.0.0.1:' . $serverPort;
            }

            $exposure = ['state' => 'unknown', 'checked' => 0, 'exposed' => [], 'hint' => 'تعذّر الفحص.'];
            foreach (array_unique($candidates) as $candidate) {
                // نمرّر رابط المستخدم للعرض، فقد يكون الفحص على عنوان داخلي
                $attempt = \App\Core\Security\ExposureCheck::run($candidate, 6, $url);
                if ($attempt['state'] === 'unknown') {
                    continue;
                }
                $exposure = $attempt;
                break;
            }

            // حجب التهيئة عند تسريب بيانات الزبائن أو بيانات الدخول فقط.
            // كشف شيفرة المشروع (bin/console) أقل خطورة ولا يمنع الإعداد.
            if ($exposure['state'] === 'critical') {
                $securityAlert = $exposure;
            } elseif ($exposure['state'] === 'source') {
                $warnings[] = 'بيانات الزبائن محميّة، لكن شيفرة المشروع مقروءة للزوار.'
                    . ' احذف bin/ و tests/ و tools/ من النسخة المنشورة لإخفائها.';
            }

            $success = $exposure['state'] !== 'critical';
        } catch (Throwable $e) {
            $errors[] = 'تعذّر إنشاء قاعدة البيانات: ' . $e->getMessage();
        }
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>تهيئة النسخة</title>
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 24px; min-height: 100vh; display: grid; place-items: center;
    font-family: "Segoe UI", system-ui, Tahoma, sans-serif;
    background: linear-gradient(160deg, #f5f6f8, #eef0f4); color: #16181d;
  }
  .card {
    width: min(520px, 100%); background: #fff; border: 1px solid #e6e8ec;
    border-radius: 20px; padding: 32px 28px; box-shadow: 0 18px 44px rgba(16,24,40,.10);
  }
  h1 { margin: 0 0 6px; font-size: 1.4rem; }
  p.sub { margin: 0 0 24px; color: #6b7280; font-size: .92rem; }
  label { display: block; margin: 14px 0 6px; font-size: .86rem; font-weight: 600; color: #4b5563; }
  input {
    width: 100%; padding: 12px 14px; border: 1px solid #e6e8ec; border-radius: 10px;
    background: #fafbfc; font: inherit; outline: none;
  }
  input:focus { border-color: #c2410c; box-shadow: 0 0 0 3px rgba(194,65,12,.14); }
  button {
    margin-top: 22px; width: 100%; padding: 14px; border: 0; border-radius: 12px;
    background: #c2410c; color: #fff; font: inherit; font-weight: 700; cursor: pointer;
  }
  .alert { border-radius: 12px; padding: 12px 14px; margin-bottom: 16px; font-size: .9rem; }
  .alert.err { background: #fdeded; color: #b91c1c; border: 1px solid #f3c6c6; }
  .alert.warn { background: #fdf6e3; color: #92400e; border: 1px solid #f0dfae; }
  .alert.ok { background: #e9f7ee; color: #15803d; border: 1px solid #bde5c9; }
  .alert ul { margin: 6px 0 0; padding-inline-start: 20px; }
  .links { display: flex; gap: 10px; margin-top: 18px; }
  .links a {
    flex: 1; text-align: center; padding: 12px; border: 1px solid #e6e8ec;
    border-radius: 12px; text-decoration: none; color: #16181d; font-weight: 600; font-size: .9rem;
  }
  .links a.primary { background: #16181d; color: #fff; border-color: #16181d; }
  code { background: #f3f4f6; padding: 2px 6px; border-radius: 6px; font-size: .86em; direction: ltr; display: inline-block; }
  .hint { font-size: .82rem; color: #6b7280; margin-top: 6px; }
  fieldset { border: 0; padding: 0; margin: 26px 0 0; }
  legend { padding: 0; margin-bottom: 4px; font-size: 1rem; font-weight: 700; }
  .preset-grid { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
  .preset { position: relative; display: block; }
  .preset input { position: absolute; opacity: 0; pointer-events: none; }
  .preset-card {
    display: block; height: 100%; cursor: pointer;
    border: 1.5px solid #e6e8ec; border-radius: 14px; padding: 14px 15px;
    background: #fafbfc; transition: border-color .18s, background .18s, box-shadow .18s;
  }
  .preset-card:hover { border-color: #c9ced6; background: #fff; }
  .preset input:checked + .preset-card {
    border-color: #c2410c; background: #fff; box-shadow: 0 0 0 3px rgba(194,65,12,.12);
  }
  .preset input:focus-visible + .preset-card { outline: 2px solid #c2410c; outline-offset: 2px; }
  .preset-top { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
  .preset-emoji { font-size: 1.3rem; line-height: 1; }
  .preset-name { font-weight: 700; font-size: .95rem; }
  .preset-sum { font-size: .8rem; color: #6b7280; line-height: 1.6; }
  .preset-badge {
    display: inline-block; margin-top: 9px; padding: 3px 9px;
    border-radius: 999px; font-size: .7rem; font-weight: 700;
  }
  .preset-badge.orders { background: #fdf1e8; color: #9a3412; }
  .preset-badge.catalog { background: #eaf0fe; color: #1d4ed8; }
  .chosen {
    margin-top: 12px; padding: 12px 14px; border-radius: 12px;
    background: #f5f6f8; border: 1px solid #e6e8ec;
    font-size: .85rem; color: #4b5563; line-height: 1.75;
  }
  .chosen b { color: #16181d; }
</style>
</head>
<body>
<div class="card">
  <h1>تهيئة النسخة</h1>
  <p class="sub">خطوة واحدة تفصلك عن تشغيل القالب.</p>

  <?php if ($securityAlert !== null): ?>
    <div class="alert err">
      <strong>⛔ توقف: بيانات الزبائن مكشوفة على الإنترنت</strong>
      <p style="margin:8px 0 0">
        خادمك لا يمنع الوصول إلى مجلدات البيانات، فأي شخص يعرف الرابط يستطيع
        تنزيل قاعدة البيانات كاملة أو قراءة بيانات الدخول.
        <strong>أصلح هذا قبل أي شيء آخر.</strong>
      </p>
      <ul>
        <?php foreach ($securityAlert['exposed'] as $item): ?>
          <?php if ($item['severity'] !== 'critical') { continue; } ?>
          <li><code><?= e($item['path']) ?></code> — <?= e($item['label']) ?> (رد <?= (int) $item['status'] ?>)</li>
        <?php endforeach; ?>
      </ul>
      <pre style="white-space:pre-wrap;text-align:start;margin:12px 0 0;font-size:.82rem;line-height:1.7"><?= e($securityAlert['hint']) ?></pre>
    </div>

  <?php elseif ($installed && !$success): ?>
    <div class="alert ok">
      النسخة مهيأة بالفعل. هذه الصفحة معطّلة الآن — احذف مجلد <code>setup/</code> من السيرفر.
    </div>
    <div class="links">
      <a class="primary" href="<?= htmlspecialchars(Paths::base(1), ENT_QUOTES, 'UTF-8') ?>">واجهة الزبون</a>
      <a href="../admin/">لوحة الإدارة</a>
    </div>

  <?php elseif ($success): ?>
    <div class="alert ok">
      تمت التهيئة بنجاح. يمكنك الآن استخدام القالب.
    </div>
    <p class="hint">
      نصيحة أمنية: احذف مجلد <code>setup/</code> الآن من السيرفر.
    </p>
    <div class="links">
      <a class="primary" href="<?= htmlspecialchars(Paths::base(1), ENT_QUOTES, 'UTF-8') ?>">واجهة الزبون</a>
      <a href="../admin/">لوحة الإدارة</a>
    </div>

  <?php else: ?>
    <?php if ($errors !== []): ?>
      <div class="alert err">
        <strong>تعذّرت التهيئة:</strong>
        <ul>
          <?php foreach ($errors as $error): ?>
            <li><?= e($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($warnings !== []): ?>
      <div class="alert warn">
        <strong>تمت التهيئة مع تحذيرات:</strong>
        <ul>
          <?php foreach ($warnings as $warning): ?>
            <li><?= e($warning) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">

      <fieldset>
        <legend>ما نوع نشاطك؟</legend>
        <p class="hint" style="margin-bottom:14px">
          الاختيار يحدد ما سيظهر في الموقع كله: التسميات، وأنواع الطلبات، وما إذا كانت
          هناك سلة أم لا. يمكنك تعديله لاحقًا من <code>src/Config/ProjectConfig.php</code>.
        </p>
        <div class="preset-grid">
          <?php
          $icons = [
              'restaurant' => '🍽️', 'store' => '🛍️', 'showroom' => '🚗',
              'clinic' => '🩺', 'studio' => '🎨', 'general' => '🏢',
          ];
          $postedPreset = (string) ($_POST['business_preset'] ?? \App\Config\BusinessPresets::defaultId());
          foreach ($presets as $key => $item):
              $isOrders = ($item['mode'] ?? '') === \App\Config\BusinessPresets::MODE_ORDERS;
          ?>
            <label class="preset">
              <input type="radio" name="business_preset" value="<?= e($key) ?>"
                     data-sum="<?= e($item['summary'] ?? '') ?>"
                     data-orders="<?= $isOrders ? '1' : '0' ?>"
                     <?= $postedPreset === $key ? 'checked' : '' ?>>
              <span class="preset-card">
                <span class="preset-top">
                  <span class="preset-emoji" aria-hidden="true"><?= $icons[$key] ?? '🏢' ?></span>
                  <span class="preset-name"><?= e($item['label'] ?? $key) ?></span>
                </span>
                <span class="preset-sum"><?= e($item['summary'] ?? '') ?></span>
                <span class="preset-badge <?= $isOrders ? 'orders' : 'catalog' ?>">
                  <?= $isOrders ? 'مع نظام الطلبات' : 'عرض فقط — بلا طلبات' ?>
                </span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="chosen" id="preset-detail"></div>
      </fieldset>

      <label for="company_name">اسم النشاط *</label>
      <input type="text" id="company_name" name="company_name" required maxlength="80"
             value="<?= e($_POST['company_name'] ?? '') ?>"
             placeholder="مثال: مؤسسة النور للتجارة">

      <label for="company_tagline">وصف قصير</label>
      <input type="text" id="company_tagline" name="company_tagline" maxlength="160"
             value="<?= e($_POST['company_tagline'] ?? '') ?>"
             placeholder="مثال: تجارة جملة وتوزيع منذ 1998">
      <div class="hint">سطر واحد يظهر تحت الاسم في كل الصفحات.</div>

      <label for="base_url">رابط الموقع</label>
      <input type="url" id="base_url" name="base_url" dir="ltr"
             value="<?= e($_POST['base_url'] ?? '') ?>"
             placeholder="https://mysite.com">
      <div class="hint">يُستخدم في رموز QR. اتركه فارغًا ليُكتشف تلقائيًا.</div>

      <label for="username">اسم المستخدم</label>
      <input type="text" id="username" name="username" dir="ltr" required
             value="<?= e($_POST['username'] ?? 'admin') ?>">

      <label for="password">كلمة المرور</label>
      <input type="password" id="password" name="password" required minlength="8">
      <div class="hint">8 محارف على الأقل. احفظها في مكان آمن.</div>

      <label for="password_confirm">تأكيد كلمة المرور</label>
      <input type="password" id="password_confirm" name="password_confirm" required minlength="8">

      <button type="submit">تهيئة الآن</button>
    </form>

    <script nonce="setup-local">
    (function () {
      var box = document.getElementById('preset-detail');
      var inputs = document.querySelectorAll('input[name="business_preset"]');
      if (!box || !inputs.length) return;

      function paint() {
        var picked = document.querySelector('input[name="business_preset"]:checked');
        if (!picked) { box.textContent = ''; return; }
        var orders = picked.dataset.orders === '1';
        var result = orders
          ? 'ستحصل على سلة وصفحة إتمام الطلب ومتابعة الطلب، وأنواع طلبات قابلة للتعديل (مثل: في المحل، سفري، توصيل).'
          : 'ستحصل على معرض منتجات للتصفح فقط بلا سلة ولا طلبات. قناة الاستفسار الوحيدة هي صفحة "تواصل معنا".';
        box.textContent = 'النتيجة: ' + result;
      }

      inputs.forEach(function (input) { input.addEventListener('change', paint); });
      paint();
    })();
    </script>
  <?php endif; ?>
</div>
</body>
</html>