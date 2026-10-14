/* ==========================================================================
   sw.js — Service Worker (نطاق الموقع الجذري)
   يعمل على المستوى الجذر حتى يتمكن من اعتراض طلبات الـ API وتخزينها
   للعمل دون اتصال، مع استثناء أي استجابة حساسة.

   ملاحظات:
   - يخزّن ملفات الواجهة وبيانات الكتالوج فقط.
   - لا يخزّن لوحة الإدارة ولا متابعة الطلبات ولا طلبات POST إطلاقًا.
   - لا يوجد طابور إرسال: الطلب دون اتصال لا يُرسل ولا يُؤجَّل.
   ========================================================================== */

const VERSION = 'v1.2.0';
const SHELL_CACHE = `lc-shell-${VERSION}`;
const DATA_CACHE = `lc-data-${VERSION}`;

// جذر التطبيق: هو نفسه جذر الموقع ("/")، وملفات الواجهة داخل /app/
const SCOPE = self.registration.scope;
const APP_BASE = new URL('./app/', SCOPE).pathname;

// ملفات هيكل الواجهة.
// ملاحظة: صفحة الهيكل نفسها هي جذر الموقع ("/") فهي لم تعد تُقدَّم من
// app/index.html — الروابط النظيفة مثل /cart و /checkout تبدأ من الجذر.

const shellUrl = SCOPE;

const SHELL_ASSETS = [
  SCOPE,
  APP_BASE + 'css/app.css',
  APP_BASE + 'js/api.js',
  APP_BASE + 'js/store.js',
  APP_BASE + 'js/ui.js',
  APP_BASE + 'js/app.js',
  APP_BASE + 'manifest.webmanifest',
  APP_BASE + 'assets/icons/icon.svg',
  APP_BASE + 'assets/icons/icon-192.png',
  APP_BASE + 'assets/icons/icon-512.png',
];

/**
 * تخزين ملفات الهيكل مسبقًا.
 *
 * ملاحظة مهمة: لا نستخدم cache.addAll لأنها ترسل كل الطلبات دفعة واحدة
 * وتفشل كليًا إذا تعذّر ملف واحد. الفشل شائع على الخوادم قليلة العمّال
 * (مثل خادم PHP المدمج أحادي العملية) حيث تتزاحم الطلبات المتوازية.
 * هنا نخزّن كل ملف على حدة، ونعيد محاولة الفاشل، فالمنصة تعمل ولو جزئيًا.
 */
async function precache() {
  const cache = await caches.open(SHELL_CACHE);

  const results = await Promise.allSettled(
    SHELL_ASSETS.map((asset) => cache.add(new Request(asset, { cache: 'reload' })))
  );

  const failed = results
    .map((result, index) => (result.status === 'rejected' ? SHELL_ASSETS[index] : null))
    .filter(Boolean);

  if (failed.length > 0) {
    // محاولة ثانية للأصول التي تعذّر تخزينها في الجولة الأولى
    await Promise.allSettled(failed.map((asset) => cache.add(asset)));
  }

  // تسجيل ما لم يُخزَّن لتشخيص مبكر
  const stored = await cache.keys();
  if (stored.length < SHELL_ASSETS.length) {
    console.warn('[sw] تم تخزين ' + stored.length + ' من ' + SHELL_ASSETS.length + ' ملف هيكل');
  }
}

self.addEventListener('install', (event) => {
  // لا نمنع التفعيل أبدًا حتى لو فشل التخزين كليًا
  event.waitUntil(precache().then(() => self.skipWaiting()).catch(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys
          .filter((key) => key !== SHELL_CACHE && key !== DATA_CACHE)
          .map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  if (event.data === 'skip-waiting') self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
  const request = event.request;

  // لا نتدخل في أي عملية كتابة أو تعديل
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  const route = url.searchParams.get('r') || '';

  // استثناء كل مسارات الإدارة (بيانات حساسة لا تُخزَّن إطلاقًا)
  if (url.pathname.includes('/admin/') || route.startsWith('admin.')) return;

  // استجابة متابعة الطلب تُقرأ مباشرة ولا تُخزَّن
  if (route === 'order.status' || route === 'ping') return;

  // صور المنتجات: الكاش أولًا
  if (url.pathname.includes('/app/uploads/')) {
    event.respondWith(cacheFirst(request, DATA_CACHE));
    return;
  }

  // ملفات هيكل التطبيق: الكاش أولًا مع تحديث في الخلفية
  if (isShellAsset(url.pathname)) {
    event.respondWith(staleWhileRevalidate(request, SHELL_CACHE));
    return;
  }

  // بيانات الكتالوج: تُخزَّن للعمل دون اتصال
  if (route === 'catalog.snapshot' || route === 'product.get' || route === 'locations.list') {
    event.respondWith(staleWhileRevalidate(request, DATA_CACHE));
  }
});

/* --------------------------- الاستراتيجيات --------------------------- */

/**
 * مطابقة دقيقة لمسار ضمن هيكل التطبيق.
 * ملاحظة: لا نستخدم endsWith('') لأن ذلك يطابق كل المسارات.
 */
function isShellAsset(pathname) {
  return SHELL_ASSETS.some((asset) => {
    if (asset.endsWith('/')) {
      return pathname === asset || pathname === asset.slice(0, -1);
    }
    return pathname === asset;
  });
}

// ملاحظة مهمة: لا نستخدم ignoreSearch مع مسارات الـ API،
// لأن ذلك قد يجعل استجابة مسار تُعاد لبند مسار آخر.
async function cacheFirst(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  if (cached) return cached;

  try {
    const response = await fetch(request);
    if (response && response.ok) cache.put(request, response.clone());
    return response;
  } catch (error) {
    return new Response('', { status: 504, statusText: 'Offline' });
  }
}

async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);

  const network = fetch(request)
    .then((response) => {
      if (response && response.ok) cache.put(request, response.clone());
      return response;
    })
    .catch(() => null);

  if (cached) return cached;

  const response = await network;
  if (response) return response;

  // لا اتصال ولا نسخة مخزنة
  if (request.mode === 'navigate') {
    const shell = await cache.match(shellUrl);
    if (shell) return shell;
  }
  return new Response(
    JSON.stringify({ ok: false, error: { code: 'offline', message: 'لا يوجد اتصال' } }),
    { status: 503, headers: { 'Content-Type': 'application/json; charset=utf-8' } }
  );
}
