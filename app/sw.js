/* ==========================================================================
   sw.js — Service Worker
   يخزّن ملفات الواجهة وبيانات الكتالوج المحملة ليعمل التصفح دون اتصال.
   لا يخزّن أي استجابة حساسة (لوحة الإدارة أو الطلبات) ولا يفعّل طابور إرسال.
   ========================================================================== */

const VERSION = 'v1.0.1';
const SHELL_CACHE = `lc-shell-${VERSION}`;
const DATA_CACHE = `lc-data-${VERSION}`;

const SHELL_ASSETS = [
  './',
  './index.html',
  './css/app.css',
  './js/api.js',
  './js/store.js',
  './js/ui.js',
  './js/app.js',
  './manifest.webmanifest',
  './assets/icons/icon.svg',
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE)
      .then((cache) => cache.addAll(SHELL_ASSETS))
      .then(() => self.skipWaiting())
      .catch(() => self.skipWaiting())
  );
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
  if (request.method !== 'GET') return;               // لا نتدخل في الطلبات المرسلة

  const url = new URL(request.url);

  // تجاهل أي شيء خارج نطاق التطبيق
  if (url.origin !== self.location.origin) return;

  // لا نخزّن أبدًا لوحة الإدارة أو مسارات الطلبات الحساسة
  if (url.pathname.includes('/admin/') || url.searchParams.get('r')?.startsWith('admin.')) {
    return;
  }
  if (url.searchParams.get('r') === 'order.status' || url.searchParams.get('r') === 'ping') {
    return;
  }

  // صور المنتجات: الكاش أولًا
  if (url.pathname.includes('/uploads/')) {
    event.respondWith(cacheFirst(request, DATA_CACHE));
    return;
  }

  // ملفات الواجهة: الكاش أولًا مع تحديث في الخلفية
  if (SHELL_ASSETS.some((asset) => url.pathname.endsWith(asset.replace('./', '')))) {
    event.respondWith(staleWhileRevalidate(request, SHELL_CACHE));
    return;
  }

  // بيانات الكتالوج من الـ API: الكاش أولًا مع تحديث (لعمل دون اتصال)
  const route = url.searchParams.get('r');
  if (route === 'catalog.snapshot' || route === 'product.get' || route === 'locations.list') {
    event.respondWith(staleWhileRevalidate(request, DATA_CACHE));
  }
});

/* --------------------------- الاستراتيجيات --------------------------- */

// ملاحظة مهمة: لا نستخدم ignoreSearch إطلاقًا مع مسارات الـ API،
// لأن ذلك قد يجعل استجابة مسار يُعاد استخدام مسار آخر (مثل product.get
// بدل catalog.snapshot). المفتاح يشمل الاستعلام كاملًا.
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
    const shell = await cache.match('./index.html');
    if (shell) return shell;
  }
  return new Response(JSON.stringify({ ok: false, error: { code: 'offline', message: 'لا يوجد اتصال' } }), {
    status: 503,
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });
}