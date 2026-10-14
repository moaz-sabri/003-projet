/* ==========================================================================
   api.js — طبقة الاتصال بالـ API مع كشف حالة الاتصال بصدق.
   لا تُعرض أي رسالة نجاح قبل وصول رد الخادم فعليًا.

   مسار الـ API يأتي من إعدادات الصفحة التي يحقنها index.php في الجذر
   (window.APP_CONFIG)، فيبقى صحيحًا مهما كان موضع تثبيت القالب.
   ========================================================================== */
(function (global) {
  'use strict';

  var CONFIG  = global.APP_CONFIG || {};
  var API_BASE = CONFIG.api || '../api/index.php';

  var Api = {
    online: navigator.onLine !== false,
    lastError: null,

    /** طلب JSON مع مهلة زمنية ومعالجة أخطاء منظمة. */
    request: function (route, options) {
      options = options || {};

      var url = API_BASE + '?r=' + encodeURIComponent(route);
      if (options.query) {
        var parts = [];
        Object.keys(options.query).forEach(function (key) {
          var value = options.query[key];
          if (value !== undefined && value !== null && value !== '') {
            parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
          }
        });
        if (parts.length) url += '&' + parts.join('&');
      }

      var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var timeout = setTimeout(function () {
        if (controller) controller.abort();
      }, options.timeout || 15000);

      var init = {
        method: options.method || 'GET',
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
        cache: options.cache || 'default',
        signal: controller ? controller.signal : undefined
      };

      if (options.body !== undefined) {
        if (options.body instanceof FormData) {
          init.body = options.body;
        } else {
          init.headers['Content-Type'] = 'application/json';
          init.body = JSON.stringify(options.body);
        }
      }
      if (options.headers) {
        Object.keys(options.headers).forEach(function (k) { init.headers[k] = options.headers[k]; });
      }

      return fetch(url, init)
        .then(function (response) {
          return response.text().then(function (text) {
            var payload = null;
            try { payload = text ? JSON.parse(text) : null; } catch (e) { payload = null; }

            if (!response.ok || !payload || payload.ok === false) {
              var message = 'تعذّر تنفيذ الطلب. حاول مجددًا.';
              var code = 'request_failed';
              if (payload && payload.error) {
                message = payload.error.message || message;
                code = payload.error.code || code;
              }
              var err = new Error(message);
              err.code = code;
              err.status = response.status;
              err.fields = payload && payload.error ? payload.error.fields : null;
              err.payload = payload;
              throw err;
            }

            Api.online = true;
            return payload.data;
          });
        })
        .catch(function (error) {
          // فشل شبكة أو مهلة أو خادم غير متاح
          Api.online = false;
          Api.lastError = error;
          if (error.name === 'AbortError') {
            var timeoutError = new Error('استغرق الطلب وقتًا طويلًا. تحقق من الاتصال وحاول مجددًا.');
            timeoutError.code = 'timeout';
            throw timeoutError;
          }
          if (!error.code) {
            error.code = 'network_error';
            error.message = navigator.onLine
              ? 'تعذّر الوصول إلى الخادم. تحقّق من الاتصال ثم أعد المحاولة.'
              : 'لا يوجد اتصال بالإنترنت. الطلب متوقف حتى يعود الاتصال.';
          }
          throw error;
        })
        .finally(function () { clearTimeout(timeout); });
    },

    /* ------------------------- مسارات ------------------------- */
    snapshot: function (search) {
      return Api.request('catalog.snapshot', { query: { q: search || '' }, cache: 'no-cache' });
    },

    product: function (id) {
      return Api.request('product.get', { query: { id: id } });
    },

    createOrder: function (payload, idempotencyKey) {
      return Api.request('order.create', {
        method: 'POST',
        body: payload,
        headers: { 'Idempotency-Key': idempotencyKey },
        timeout: 20000
      });
    },

    orderStatus: function (token) {
      return Api.request('order.status', { query: { token: token }, cache: 'no-store' });
    },

    locations: function () {
      return Api.request('locations.list');
    },

    ping: function () {
      return Api.request('ping', { cache: 'no-store', timeout: 6000 });
    }
  };

  global.App = global.App || {};
  global.App.Api = Api;
})(window);