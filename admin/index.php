<?php

declare(strict_types=1);

/**
 * لوحة الإدارة — صفحة واحدة (SPA).
 * تُقدِّم PHP الغلاف فقط: حالة الجلسة ورمز الحماية، وكل الباقي عبر API.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Config\ProjectConfig;
use App\Core\RuntimeSettings;
use App\Core\Security\Auth;

Auth::start();

$authenticated = Auth::check();
$settings      = RuntimeSettings::publicPayload();
$appName       = $settings['app_name'] ?: ProjectConfig::DEFAULT_APP_NAME;

// Nonce للاستخدامات المضمّنة (سياسة CSP تبقى صارمة)
$nonce = base64_encode(random_bytes(16));

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');
// سياسة أمان محتوى صارمة: بلا inline scripts، وinline style مسموح عبر nonce فقط
$csp = "default-src 'self'; "
    . "img-src 'self' data:; "
    . "style-src 'self' 'nonce-" . $nonce . "'; "
    . "script-src 'self'; "
    . "connect-src 'self'; "
    . "form-action 'self'; "
    . "base-uri 'none'; "
    . "frame-ancestors 'none'";
header('Content-Security-Policy: ' . $csp);

// ألوان الحالات تأتي من ProjectConfig (تعريف بنيوي) وتُحقن كأنماط CSS
$statusStyles = '';
foreach (ProjectConfig::ORDER_STATUSES as $key => $def) {
    $statusStyles .= '.st-' . preg_replace('/[^a-z0-9_\-]/i', '', (string) $key)
        . '{background:' . preg_replace('/[^#a-zA-Z0-9]/', '', (string) $def['color']) . '}' . "\n";
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>لوحة الإدارة — <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="icon" href="../app/assets/icons/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="assets/admin.css?v=<?= (int) @filemtime(__DIR__ . '/assets/admin.css') ?>">
  <style nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
    /* ألوان الحالات — من ProjectConfig */
    <?= $statusStyles ?>
  </style>
</head>
<body>

<!-- ===================== شاشة الدخول ===================== -->
<div class="login-screen" id="login-screen" <?= $authenticated ? 'hidden' : '' ?>>
  <form class="login-card" id="login-form" autocomplete="on">
    <div class="login-brand">
      <img src="../app/assets/icons/icon-192.png" alt="" width="56" height="56">
      <h1>لوحة الإدارة</h1>
      <p><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="alert" id="login-error" hidden></div>

    <div class="field">
      <label for="login-username">اسم المستخدم</label>
      <input type="text" id="login-username" name="username" autocomplete="username" required autofocus>
    </div>

    <div class="field">
      <label for="login-password">كلمة المرور</label>
      <input type="password" id="login-password" name="password" autocomplete="current-password" required>
    </div>

    <button class="btn btn-primary btn-lg btn-block" type="submit" id="login-submit">تسجيل الدخول</button>
    <p class="login-hint">بيانات الدخول محفوظة على الخادم فقط.</p>
  </form>
</div>

<!-- ===================== التطبيق ===================== -->
<div class="admin-shell" id="admin-shell" <?= $authenticated ? '' : 'hidden' ?>>

  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <img src="../app/assets/icons/icon-192.png" alt="" width="36" height="36">
      <div>
        <strong id="side-app-name"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></strong>
        <small>لوحة الإدارة</small>
      </div>
    </div>

    <nav class="side-nav">
      <button class="side-link is-active" data-view="dashboard">
        <span class="ico">◫</span> الرئيسية
      </button>
      <button class="side-link" data-view="orders">
        <span class="ico">☰</span> الطلبات
        <span class="badge-count" id="nav-orders-count" hidden>0</span>
      </button>
      <button class="side-link" data-view="products">
        <span class="ico">✦</span> المنتجات
      </button>
      <button class="side-link" data-view="categories">
        <span class="ico">▤</span> التصنيفات
      </button>
      <button class="side-link" data-view="locations">
        <span class="ico">⌖</span> المواقع وQR
      </button>
      <button class="side-link" data-view="settings">
        <span class="ico">⚙</span> إعدادات العرض
      </button>
      <button class="side-link" data-view="security">
        <span class="ico">⛨</span> الأمان والنسخ
      </button>
    </nav>

    <div class="sidebar-foot">
      <a class="side-link" href="../app/index.html" target="_blank" rel="noopener">
        <span class="ico">↗</span> عرض الموقع
      </a>
      <button class="side-link" id="logout-button">
        <span class="ico">⏻</span> تسجيل الخروج
      </button>
    </div>
  </aside>

  <main class="admin-main">
    <header class="topbar">
      <button class="icon-btn only-mobile" id="menu-toggle" aria-label="القائمة">☰</button>
      <h2 class="topbar-title" id="view-title">الرئيسية</h2>
      <div class="topbar-actions">
        <span class="refresh-note" id="refresh-note"></span>
        <button class="btn btn-ghost" id="refresh-button" type="button">تحديث</button>
      </div>
    </header>

    <section class="admin-view" id="view-dashboard">
      <div class="stats-grid" id="stats-grid"></div>
      <div class="panel">
        <div class="panel-head">
          <h3>أحدث الطلبات</h3>
          <button class="btn btn-ghost btn-sm" data-goto="orders">كل الطلبات</button>
        </div>
        <div class="table-wrap" id="dashboard-orders"></div>
      </div>
    </section>

    <section class="admin-view" id="view-orders" hidden>
      <div class="toolbar">
        <input type="search" id="orders-search" placeholder="بحث برقم الطلب أو الاسم…">
        <select id="orders-status">
          <option value="">كل الحالات</option>
        </select>
        <select id="orders-location"><option value="">كل المواقع</option></select>
        <input type="date" id="orders-date">
        <button class="btn btn-ghost" id="orders-clear">مسح الفلاتر</button>
      </div>
      <div class="panel">
        <div class="table-wrap" id="orders-table"></div>
      </div>
    </section>

    <section class="admin-view" id="view-products" hidden>
      <div class="panel-head">
        <h3>المنتجات</h3>
        <button class="btn btn-primary" id="product-new">+ منتج جديد</button>
      </div>
      <div class="panel">
        <div class="table-wrap" id="products-table"></div>
      </div>
    </section>

    <section class="admin-view" id="view-categories" hidden>
      <div class="panel-head">
        <h3>التصنيفات</h3>
        <button class="btn btn-primary" id="category-new">+ تصنيف جديد</button>
      </div>
      <div class="panel">
        <div class="table-wrap" id="categories-table"></div>
      </div>
    </section>

    <section class="admin-view" id="view-locations" hidden>
      <div class="panel-head">
        <h3>المواقع ورموز QR</h3>
        <div>
          <button class="btn btn-ghost" id="print-qr" target="_blank">طباعة كل الرموز</button>
          <button class="btn btn-primary" id="location-new">+ موقع جديد</button>
        </div>
      </div>
      <div class="panel">
        <div class="qr-grid" id="locations-grid"></div>
      </div>
    </section>

    <section class="admin-view" id="view-settings" hidden>
      <div class="panel">
        <div class="panel-head"><h3>إعدادات العرض</h3></div>
        <form id="settings-form" class="form-grid" enctype="multipart/form-data"></form>
      </div>
      <div class="panel">
        <div class="panel-head"><h3>معاينة البطاقة</h3></div>
        <div class="brand-preview" id="brand-preview"></div>
      </div>
    </section>

    <section class="admin-view" id="view-security" hidden>
      <div class="panel">
        <div class="panel-head"><h3>كلمة مرور المدير</h3></div>
        <form id="password-form" class="form-grid">
          <div class="field">
            <label for="pw-current">كلمة المرور الحالية</label>
            <input type="password" id="pw-current" autocomplete="current-password" required>
          </div>
          <div class="field">
            <label for="pw-new">كلمة المرور الجديدة</label>
            <input type="password" id="pw-new" autocomplete="new-password" minlength="8" required>
          </div>
          <div class="field">
            <label for="pw-confirm">تأكيد كلمة المرور</label>
            <input type="password" id="pw-confirm" autocomplete="new-password" minlength="8" required>
          </div>
          <div class="alert" id="password-error" hidden></div>
          <div><button class="btn btn-primary" type="submit">تحديث كلمة المرور</button></div>
        </form>
      </div>

      <div class="panel">
        <div class="panel-head">
          <h3>النسخ الاحتياطي</h3>
          <button class="btn btn-primary" id="backup-create">إنشاء نسخة الآن</button>
        </div>
        <div class="table-wrap" id="backups-table"></div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3>معلومات النسخة</h3></div>
        <div class="info-grid" id="version-info"></div>
      </div>
    </section>
  </main>
</div>

<!-- ===================== الطبقات والنوافذ ===================== -->
<div class="drawer-backdrop" id="drawer-backdrop" hidden></div>
<aside class="drawer" id="drawer" hidden aria-modal="true" role="dialog">
  <header class="drawer-head">
    <h3 id="drawer-title">تفاصيل</h3>
    <button class="icon-btn" id="drawer-close" aria-label="إغلاق">×</button>
  </header>
  <div class="drawer-body" id="drawer-body"></div>
</aside>

<div class="modal-backdrop" id="modal-backdrop" hidden>
  <div class="modal" role="dialog" aria-modal="true">
    <header class="modal-head">
      <h3 id="modal-title">تحرير</h3>
      <button class="icon-btn" id="modal-close" aria-label="إغلاق">×</button>
    </header>
    <div class="modal-body" id="modal-form"></div>
    <footer class="modal-foot">
      <button class="btn btn-ghost" id="modal-cancel" type="button">إلغاء</button>
      <button class="btn btn-primary" id="modal-save" type="button">حفظ</button>
    </footer>
  </div>
</div>

<div class="toast" id="toast" hidden></div>

<script type="application/json" id="app-config"><?= json_encode([
    'api'          => '../api/index.php',
    'csrf'         => Auth::token(),
    'authenticated'=> $authenticated,
    'username'     => Auth::username(),
    'version'      => '1.0.0',
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="assets/admin.js?v=<?= (int) @filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
</body>
</html>