/* ==========================================================================
   admin.js — منطق لوحة الإدارة (SPA خفيفة بلا مكتبات خارجية)
   ========================================================================== */
(function (global) {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };

  // إعدادات الصفحة تُمرَّر كبيانات JSON (تبقى متوافقة مع سياسة CSP الصارمة)
  var CFG = (function () {
    var node = document.getElementById('app-config');
    if (!node) return { api: '/api/index.php' };
    try { return JSON.parse(node.textContent || '{}'); } catch (e) { return { api: '/api/index.php' }; }
  })();

  /* أيقونات الرسائل — SVG مضمّن (سياسة CSP تمنع أي مورد خارجي) */
  var TOAST_ICONS = {
    success: '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" d="m5 12.5 4.5 4.5L19 7.5"/></svg>',
    error:   '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M12 7.5v5m0 3h.01M12 3.5 2.6 19a1.4 1.4 0 0 0 1.2 2.1h16.4a1.4 1.4 0 0 0 1.2-2.1L12 3.5Z"/></svg>',
    warn:    '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 8v4.5M12 15.5h.01"/></svg>',
    info:    '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 11v5.5M12 7.8h.01"/></svg>'
  };

  var TOAST_DURATION = { success: 2800, info: 3200, warn: 4000, error: 4800 };

  var state = {
    view: 'dashboard',
    csrf: CFG.csrf || '',
    settings: {},
    config: {},
    categories: [],
    products: [],
    locations: [],
    statuses: [],
    stats: {},
    instance: null,
    refreshTimer: null,
    modalSubmit: null,
    orderFilters: { status: '', location_id: '', q: '', date: '' },
    currentOrderId: null
  };

  /* ============================ الاتصال ============================ */

  function api(route, options) {
    options = options || {};
    var url = CFG.api + '?r=' + encodeURIComponent(route);
    if (options.query) {
      var parts = [];
      Object.keys(options.query).forEach(function (k) {
        var v = options.query[k];
        if (v !== undefined && v !== null && v !== '') parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
      });
      if (parts.length) url += '&' + parts.join('&');
    }

    var headers = { 'Accept': 'application/json' };
    if (options.method && options.method !== 'GET') headers['X-CSRF-Token'] = state.csrf;

    var init = { method: options.method || 'GET', headers: headers, credentials: 'same-origin' };

    if (options.body !== undefined) {
      if (options.body instanceof FormData) {
        init.body = options.body;
      } else {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(options.body);
      }
    }

    return fetch(url, init).then(function (res) {
      return res.text().then(function (text) {
        var payload = null;
        try { payload = text ? JSON.parse(text) : null; } catch (e) { payload = null; }

        if (!res.ok || !payload || payload.ok === false) {
          var err = new Error((payload && payload.error && payload.error.message) || 'تعذّر تنفيذ العملية.');
          err.code = payload && payload.error ? payload.error.code : 'error';
          err.status = res.status;
          throw err;
        }
        return payload.data;
      });
    }).catch(function (error) {
      if (error.code === 'unauthenticated' || error.code === 'invalid_csrf') {
        showLogin();
      }
      if (!error.code) {
        error.message = 'تعذّر الاتصال بالخادم. تحقق من الشبكة.';
        error.code = 'network';
      }
      throw error;
    });
  }

  /* ============================ أدوات ============================ */

  function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function money(minor) {
    var cfg = state.config || {};
    var n = Number(minor || 0);
    var text = (cfg.currency_position === 'before')
      ? (cfg.currency || '') + ' ' + n.toLocaleString('en-US')
      : n.toLocaleString('en-US') + ' ' + (cfg.currency || '');
    return text;
  }

  /**
   * إظهار/إخفاء عنصر بأمان.
   *
   * ملاحظة مهمة: عناصر التنبيه في هذه اللوحة تحمل الصنف hidden في HTML،
   * بينما تستخدم عناصر أخرى الخاصية hidden فقط. ضبط الاثنين معًا
   * يمنع بقاء رسالة الخطأ مخفية (كان الخطأ يظهر ولا يُعرض إطلاقًا).
   */
  function setHidden(el, hidden) {
    if (!el) return;
    el.hidden = hidden;
    el.classList.toggle('hidden', hidden);
  }

  function toast(message, type, options) {
    var wrap = $('toast-wrap');
    if (!wrap) return;

    if (type && typeof type === 'object') {
      options = type;
      type = options.type || 'info';
    }
    options = options || {};
    type = TOAST_ICONS[type] ? type : 'info';

    // ثلاث رسائل كحد أقصى حتى لا تتكدس الشاشة
    var live = wrap.querySelectorAll('.toast');
    while (live.length >= 3) {
      dismissToast(live[0]);
      live = wrap.querySelectorAll('.toast');
    }

    var duration = options.duration || (options.action ? 6500 : TOAST_DURATION[type]);

    var el = document.createElement('div');
    el.className = 'toast ' + type;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.innerHTML =
      '<span class="toast-icon">' + TOAST_ICONS[type] + '</span>' +
      '<span class="toast-text"></span>' +
      '<span class="toast-bar"></span>';

      // مدة الشريط تُضبط عبر CSSOM وليس سمة style مضمّنة،
      // لأن سياسة CSP في هذه اللوحة لا تسمح بالأنماط المضمّنة.
      el.querySelector('.toast-bar').style.animationDuration = duration + 'ms';
    el.querySelector('.toast-text').textContent = message;

    if (options.action && options.action.label) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'toast-action';
      button.textContent = options.action.label;
      button.addEventListener('click', function () {
        clearTimeout(timer);
        dismissToast(el);
        options.action.onClick();
      });
      el.insertBefore(button, el.querySelector('.toast-bar'));
    }

    var timer = setTimeout(function () { dismissToast(el); }, duration);
    el.addEventListener('click', function (event) {
      if (event.target.closest('.toast-action')) return;
      clearTimeout(timer);
      dismissToast(el);
    });

    wrap.appendChild(el);
  }

  function dismissToast(el) {
    if (!el || el.dataset.leaving) return;
    el.dataset.leaving = '1';
    el.classList.add('leaving');
    setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 220);
  }

  /* ------------------------------------------------------------------ *
   * نافذة التأكيد: بديل confirm/prompt في المتصفح.
   * السبب: النوافذ الأصلية لا تطابق هوية اللوحة، ولا تُشرح ما سيحدث،
   * ولا يمكن تنسيق نصوصها بالعربية.
   * ------------------------------------------------------------------ */
  function ask(options) {
    return new Promise(function (resolve) {
      $('confirm-title').textContent = options.title || 'تأكيد';
      $('confirm-text').textContent = options.message || '';

      var field = $('confirm-input-field');
      var input = $('confirm-input');
      field.hidden = !options.input;
      if (options.input) {
        $('confirm-input-label').textContent = options.input.label || '';
        input.value = options.input.value || '';
        input.placeholder = options.input.placeholder || '';
      }

      var ok = $('confirm-ok');
      ok.textContent = options.confirmText || 'تأكيد';
      ok.className = 'btn ' + (options.tone === 'danger' ? 'btn-danger' : 'btn-primary');

      var backdrop = $('confirm-backdrop');
      backdrop.hidden = false;
      if (options.input) setTimeout(function () { input.focus(); }, 60);
      else setTimeout(function () { ok.focus(); }, 60);

      function close(result) {
        backdrop.hidden = true;
        document.removeEventListener('keydown', onKey);
        resolve(result);
      }

      function onKey(event) {
        if (event.key === 'Escape') close(null);
      }

      function accept() {
        close(options.input ? String(input.value || '').trim() : true);
      }

      ok.onclick = accept;
      $('confirm-cancel').onclick = function () { close(null); };
      $('confirm-x').onclick = function () { close(null); };
      backdrop.onclick = function (event) {
        if (event.target.id === 'confirm-backdrop') close(null);
      };
      input.onkeydown = function (event) { if (event.key === 'Enter') accept(); };
      document.addEventListener('keydown', onKey);
    });
  }

  /** نسخ نص إلى الحافظة مع بديل للمتصفحات القديمة أو السياقات غير الآمنة. */
  function copyToClipboard(text) {
    if (global.navigator && navigator.clipboard && global.isSecureContext) {
      return navigator.clipboard.writeText(text).catch(function () { return fallbackCopy(text); });
    }
    return Promise.resolve(fallbackCopy(text));
  }

  function fallbackCopy(text) {
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(area);
    if (!ok) toast('تعذّر النسخ تلقائيًا — انسخ الرابط يدويًا.', 'warn');
    return ok;
  }

  function confirmAction(message, options) {
    options = options || {};
    return ask({
      title: options.title || 'تأكيد العملية',
      message: message,
      confirmText: options.confirmText || 'تأكيد',
      tone: options.tone || 'danger'
    });
  }

  /** زر أثناء تنفيذ عملية: يمنع التكرار ويعرض مؤشرًا بدل النص. */
  function setLoading(button, loading, label) {
    if (!button) return;
    if (loading) {
      if (!button.dataset.idleLabel) button.dataset.idleLabel = button.textContent;
      button.disabled = true;
      button.classList.add('is-loading');
      button.textContent = label || 'جارٍ التنفيذ…';
    } else {
      button.disabled = false;
      button.classList.remove('is-loading');
      if (button.dataset.idleLabel) {
        button.textContent = button.dataset.idleLabel;
        delete button.dataset.idleLabel;
      }
    }
  }

  /* ============================ المصادقة ============================ */

  function showLogin() {
    $('login-screen').hidden = false;
    $('admin-shell').hidden = true;
    stopAutoRefresh();
  }

  function showApp() {
    $('login-screen').hidden = true;
    $('admin-shell').hidden = false;
    startAutoRefresh();
  }

  function login(event) {
    event.preventDefault();
    var btn = $('login-submit');
    var username = $('login-username').value.trim();
    setLoading(btn, true, 'جارٍ التحقق…');
    setHidden($('login-error'), true);

    api('admin.login', {
      method: 'POST',
      body: { username: $('login-username').value, password: $('login-password').value }
    }).then(function (data) {
      state.csrf = data.csrf;
      CFG.username = data.username || username;
      $('login-password').value = '';
      showApp();
      boot();
      toast('مرحبًا ' + (CFG.username ? CFG.username + ' — ' : '') + 'تم تسجيل الدخول بنجاح.', 'success');
    }).catch(function (error) {
      $('login-error').textContent = error.message;
      setHidden($('login-error'), false);
      toast(error.message, 'error');
    }).finally(function () {
      setLoading(btn, false);
    });
  }

  function logout() {
    confirmAction('هل تريد تسجيل الخروج من اللوحة؟', {
      title: 'تسجيل الخروج',
      confirmText: 'خروج',
      tone: 'default'
    }).then(function (ok) {
      if (!ok) return;
      api('admin.logout', { method: 'POST' }).catch(function () { }).finally(function () {
        state.csrf = '';
        stopAutoRefresh();
        showLogin();
        $('login-password').value = '';
        toast('تم تسجيل الخروج بنجاح.', 'info');
      });
    });
  }

  /* ============================ التوجيه ============================ */

  var TITLES = {
    dashboard: 'الرئيسية', orders: 'الطلبات', products: 'المنتجات',
    categories: 'التصنيفات', locations: 'المواقع وQR', settings: 'إعدادات العرض',
    security: 'الأمان والنسخ الاحتياطي'
  };

  function go(view) {
    state.view = view;
    document.querySelectorAll('.side-link[data-view]').forEach(function (btn) {
      btn.classList.toggle('is-active', btn.getAttribute('data-view') === view);
    });
    document.querySelectorAll('.admin-view').forEach(function (el) {
      el.hidden = el.id !== 'view-' + view;
    });
    $('view-title').textContent = TITLES[view] || '';
    $('sidebar').classList.remove('open');
    global.location.hash = view;

    var loaders = {
      dashboard: loadDashboard, orders: loadOrders, products: loadProducts,
      categories: loadCategories, locations: loadLocations, settings: loadSettings,
      security: loadSecurity
    };
    if (loaders[view]) loaders[view]();
  }

  /* ============================ الرئيسية ============================ */

  function loadDashboard() {
    return api('admin.dashboard').then(function (data) {
      state.stats = data.stats;
      renderStats(data.stats);
      renderOrderTable($('dashboard-orders'), data.latest, false);
      updateOrderBadge();
    }).catch(function (error) { toast(error.message, 'error'); });
  }

  function renderStats(stats) {
    $('stats-grid').innerHTML = [
      card('طلبات جديدة', stats.new, 'accent'),
      card('قيد المعالجة', stats.active),
      card('طلبات اليوم', stats.today),
      card('إيراد اليوم', money(stats.today_total)),
      card('مكتملة اليوم', stats.completed_today)
    ].join('');

    function card(label, value, extra) {
      return '<div class="stat-card ' + (extra || '') + '">' +
        '<div class="label">' + escapeHtml(label) + '</div>' +
        '<div class="value">' + escapeHtml(value) + '</div></div>';
    }
  }

  /* ============================ الطلبات ============================ */

  function loadOrders() {
    var filters = state.orderFilters;
    return api('admin.orders', { query: filters }).then(function (data) {
      state.statuses = data.statuses || state.statuses;
      renderStatusFilter();
      renderOrderTable($('orders-table'), data.orders, true);
      updateOrderBadge();
    }).catch(function (error) { toast(error.message, 'error'); });
  }

  function renderOrderTable(container, orders, detailed) {
    if (!container) return;
    if (!orders || !orders.length) {
      container.innerHTML = '<p class="empty">لا توجد طلبات بعد.</p>';
      return;
    }

    var rows = orders.map(function (order) {
      var isNew = order.status === 'new';
      return '<tr' + (isNew ? ' class="order-row-new"' : '') + '>' +
        '<td><strong>#' + order.number + '</strong><div class="cell-sub">' + escapeHtml(order.time_label) + '</div></td>' +
        '<td class="cell-title">' + escapeHtml(order.customer_name || '—') + '</td>' +
        '<td>' + escapeHtml(order.location || order.order_type_label) + '</td>' +
        (detailed ? '<td>' + order.items_count + '</td>' : '') +
        '<td><strong>' + escapeHtml(order.total) + '</strong></td>' +
        '<td><span class="status-pill st-' + escapeHtml(order.status) + '">' + escapeHtml(order.status_label) + '</span></td>' +
        '<td class="actions">' +
          '<button class="btn btn-ghost btn-sm" data-order="' + order.id + '">تفاصيل</button>' +
          (isNew ? '<button class="btn btn-primary btn-sm" data-start="' + order.id + '">بدء</button>' : '') +
        '</td>' +
      '</tr>';
    }).join('');

    container.innerHTML = '<table><thead><tr>' +
      '<th>الطلب</th><th>الزبون</th><th>الموقع</th>' +
      (detailed ? '<th>الأصناف</th>' : '') +
      '<th>الإجمالي</th><th>الحالة</th><th></th>' +
      '</tr></thead><tbody>' + rows + '</tbody></table>';
  }

  function updateOrderBadge() {
    var badge = $('nav-orders-count');
    var count = (state.stats && state.stats.new) || 0;
    badge.textContent = count;
    badge.hidden = count === 0;
  }

  function openOrder(id) {
    state.currentOrderId = parseInt(id, 10);
    api('admin.order', { query: { id: id } }).then(function (data) {
      var order = data.order;
      openDrawer('الطلب #' + order.number, renderOrderDetail(order));
      loadQr(id);
    }).catch(function (error) { toast(error.message, 'error'); });
  }

  function renderOrderDetail(order) {
    var items = order.items.map(function (item) {
      return '<li><span>' + item.quantity + ' × ' + escapeHtml(item.name) + '</span><strong>' +
        escapeHtml(item.line_total) + '</strong></li>';
    }).join('');

    var fields = Object.keys(order.fields || {}).map(function (key) {
      return '<div class="detail-box"><div class="k">' + escapeHtml(key) + '</div><div class="v">' +
        escapeHtml(order.fields[key]) + '</div></div>';
    }).join('');

    var actions = (order.next_statuses || []).map(function (status) {
      var cfg = (state.statuses || []).filter(function (s) { return s.key === status; })[0] || { label: status };
      var cls = status === 'cancelled' ? 'btn-danger' : (status === 'completed' ? 'btn-ok' : 'btn-primary');
      return '<button class="btn ' + cls + '" data-status="' + escapeHtml(status) + '">' +
        escapeHtml(cfg.label) + '</button>';
    }).join(' ');

    var events = (order.events || []).map(function (event) {
      var from = event.from_status ? (statusLabel(event.from_status) + ' ← ') : '';
      return '<li>' + from + escapeHtml(statusLabel(event.to_status)) +
        ' <span class="cell-sub">(' + escapeHtml(event.created_at) + ')</span></li>';
    }).join('');

    return '<div class="detail-grid">' +
        box('الحالة', statusLabel(order.status)) +
        box('الزبون', order.customer_name || '—') +
        box('نوع الطلب', order.order_type_label) +
        box('الموقع', order.location || '—') +
        box('الإجمالي', order.total) +
        box('الوقت', order.created_at) +
      '</div>' +
      (order.notes ? '<div class="detail-box"><div class="k">ملاحظات</div><div class="v">' + escapeHtml(order.notes) + '</div></div>' : '') +
      (fields ? '<div class="detail-grid mt-sm">' + fields + '</div>' : '') +
      '<h4 class="section-title">العناصر</h4>' +
      '<ul class="items-list">' + items + '</ul>' +
      '<div class="qr-box" id="order-qr-box" hidden></div>' +
      '<div class="row-actions">' + actions + '</div>' +
      (events ? '<h4 class="section-title">سجل الحالة</h4><ul class="timeline">' + events + '</ul>' : '');

    function box(k, v) {
      return '<div class="detail-box"><div class="k">' + escapeHtml(k) + '</div><div class="v">' + escapeHtml(v) + '</div></div>';
    }
  }

  var FALLBACK_STATUS_LABELS = {
    new: 'جديد', in_progress: 'قيد التنفيذ', completed: 'مكتمل', cancelled: 'ملغى'
  };

  function statusLabel(key) {
    var cfg = (state.statuses || []).filter(function (s) { return s.key === key; })[0];
    return cfg ? cfg.label : (FALLBACK_STATUS_LABELS[key] || key);
  }

  function loadQr(orderId) {
    api('admin.order.qr', { query: { id: orderId } }).then(function (data) {
      var box = $('order-qr-box');
      if (!box || !data.qr_url) return;
      box.hidden = false;
      box.innerHTML = '<div class="k mb-sm">رمز متابعة الطلب (قراءة فقط)</div>' +
        '<img src="' + escapeHtml(CFG.api) + '?r=admin.qr&type=order&value=' + encodeURIComponent(data.token) + '" alt="QR">' +
        '<div class="qr-url">' + escapeHtml(data.qr_url) + '</div>';
    }).catch(function () { /* اختياري */ });
  }

  function changeStatus(id, status) {
    if (!id) return;

    var confirmAction_ = function (note) {
      return api('admin.order.status', { method: 'POST', body: { id: id, status: status, note: note || '' } })
        .then(function (data) {
          var label = statusLabel(status);
          toast('تم تحديث حالة الطلب #' + (data.order ? data.order.number : '') + ' إلى: ' + label, 'success');
          closeDrawer();
          state.stats = data.stats;
          updateOrderBadge();
          if (state.view === 'orders') loadOrders(); else loadDashboard();
        });
    };

    if (status === 'cancelled') {
      // سبب الإلغاء اختياري — نافذة واحدة تجمع السؤال والتأكيد
      ask({
        title: 'إلغاء الطلب',
        message: 'سيُعلَن الطلب كملغى. يمكنك كتابة سبب (اختياري) للمتابعة.',
        input: { label: 'سبب الإلغاء (اختياري)', placeholder: 'مثال: الزبون غادر' },
        confirmText: 'إلغاء الطلب',
        tone: 'danger'
      }).then(function (note) {
        if (note === null) return;
        confirmAction_(note);
      }).catch(function (error) { toast(error.message, 'error'); });
      return;
    }

    confirmAction_('').catch(function (error) { toast(error.message, 'error'); });
  }

  function renderStatusFilter() {
    var select = $('orders-status');
    if (!select || select.dataset.ready) return;
    select.innerHTML = '<option value="">كل الحالات</option>' +
      (state.statuses || []).map(function (s) {
        return '<option value="' + s.key + '">' + escapeHtml(s.label) + '</option>';
      }).join('');
    select.dataset.ready = '1';
    select.addEventListener('change', function () {
      state.orderFilters.status = select.value;
      loadOrders();
    });
  }

  /* ============================ التصنيفات ============================ */

  function loadCategories() {
    return api('admin.categories').then(function (data) {
      state.categories = data.categories;
      renderCategories();
    }).catch(function (error) { toast(error.message, 'error'); });
  }

  function renderCategories() {
    var container = $('categories-table');
    if (!state.categories.length) {
      container.innerHTML = '<p class="empty">لا توجد تصنيفات. أضف أول تصنيف.</p>';
      return;
    }
    container.innerHTML = '<table><thead><tr><th>الاسم</th><th>الترتيب</th><th>الحالة</th><th></th></tr></thead><tbody>' +
      state.categories.map(function (cat) {
        return '<tr>' +
          '<td class="cell-title">' + escapeHtml(cat.name) +
            (cat.description ? '<div class="cell-sub">' + escapeHtml(cat.description) + '</div>' : '') + '</td>' +
          '<td>' + cat.sort_order + '</td>' +
          '<td>' + (cat.is_active ? '<span class="status-pill st-completed">مفعّل</span>'
                                   : '<span class="status-pill st-off">موقوف</span>') + '</td>' +
          '<td class="actions">' +
            '<button class="btn btn-ghost btn-sm" data-cat-up="' + cat.id + '" title="لأعلى">↑</button>' +
            '<button class="btn btn-ghost btn-sm" data-cat-down="' + cat.id + '" title="لأسفل">↓</button>' +
            '<button class="btn btn-ghost btn-sm" data-cat-edit="' + cat.id + '">تعديل</button>' +
            '<button class="btn btn-ghost btn-sm" data-cat-del="' + cat.id + '">حذف</button>' +
          '</td>' +
        '</tr>';
      }).join('') + '</tbody></table>';
  }

  function editCategory(id) {
    var cat = id ? state.categories.filter(function (c) { return c.id === id; })[0] : null;

    openModal(cat ? 'تعديل تصنيف' : 'تصنيف جديد', '' +
      '<div class="field"><label>اسم التصنيف</label><input id="f-name" value="' + escapeHtml(cat ? cat.name : '') + '" maxlength="80"></div>' +
      '<div class="field"><label>وصف مختصر</label><input id="f-desc" value="' + escapeHtml(cat ? cat.description : '') + '" maxlength="300"></div>' +
      '<div class="field-row">' +
        '<div class="field"><label>الترتيب</label><input id="f-sort" type="number" value="' + (cat ? cat.sort_order : 0) + '"></div>' +
        '<div class="field"><label>الحالة</label><label class="switch">' +
          '<input type="checkbox" id="f-active"' + (cat && cat.is_active ? ' checked' : (!cat ? ' checked' : '')) + '>' +
          '<span class="switch-track"></span> مفعّل</label></div>' +
      '</div>',
      function () {
        var payload = {
          id: id || 0,
          name: $('f-name').value.trim(),
          description: $('f-desc').value.trim(),
          sort_order: parseInt($('f-sort').value || '0', 10),
          is_active: $('f-active').checked ? 1 : 0
        };
        if (!payload.name) { toast('اكتب اسم التصنيف قبل الحفظ.', 'error'); return false; }

        return api(id ? 'admin.category.update' : 'admin.category.create', { method: 'POST', body: payload })
          .then(function (data) {
            state.categories = data.categories;
            renderCategories();
            toast(id ? 'تم حفظ تعديلات التصنيف «' + payload.name + '».' : 'أُضيف التصنيف «' + payload.name + '».', 'success');
          });
      });
  }

  function deleteCategory(id) {
    var cat = state.categories.filter(function (c) { return c.id === id; })[0];
    if (!cat) return;

    confirmAction('سيُحذف التصنيف «' + cat.name + '» وتبقى منتجاته بدون تصنيف.', {
      title: 'حذف تصنيف',
      confirmText: 'حذف نهائي'
    }).then(function (ok) {
      if (!ok) return;
      return api('admin.category.delete', { method: 'POST', body: { id: id } }).then(function (data) {
        state.categories = data.categories;
        renderCategories();
        toast('تم حذف التصنيف «' + cat.name + '».', 'success');
      });
    }).catch(function (error) { toast(error.message, 'error'); });
  }

  function moveCategory(id, direction) {
    var ids = state.categories.map(function (c) { return c.id; });
    var index = ids.indexOf(id);
    var target = index + direction;
    if (index < 0 || target < 0 || target >= ids.length) {
      toast(direction < 0 ? 'هذا التصنيف في أعلى القائمة.' : 'هذا التصنيف في أسفل القائمة.', 'info');
      return;
    }
    ids.splice(target, 0, ids.splice(index, 1)[0]);

    api('admin.categories.reorder', { method: 'POST', body: { ids: ids } }).then(function (data) {
      state.categories = data.categories;
      renderCategories();
      toast('تم تحديث ترتيب التصنيفات.', 'success');
    }).catch(function (error) { toast(error.message, 'error'); });
  }

  /* ============================ المنتجات ============================ */

  function loadProducts() {
    return api('admin.products').then(function (data) {
      state.products = data.products;
      state.categories = data.categories;
      state.productFields = data.fields;
      state.detailFields = data.detail_fields || [];
      state.labels = data.labels || state.labels;
      renderProducts();
    }).catch(function (error) { toast(error.message, 'error'); });
  }

  function renderProducts() {
    var container = $('products-table');
    if (!state.products.length) {
      container.innerHTML = '<p class="empty">لا توجد منتجات. أضف أول منتج.</p>';
      return;
    }

    container.innerHTML = '<table><thead><tr>' +
      '<th></th><th>المنتج</th><th>التصنيف</th><th>السعر</th><th>الحالة</th><th></th>' +
      '</tr></thead><tbody>' +
      state.products.map(function (product) {
        var thumb = product.image
          ? '<img class="thumb" src="' + escapeHtml(product.image) + '" alt="">'
          : '<div class="thumb"></div>';
        return '<tr>' +
          '<td>' + thumb + '</td>' +
          '<td class="cell-title">' + escapeHtml(product.name) +
            (product.description ? '<div class="cell-sub">' + escapeHtml(product.description.slice(0, 60)) + '</div>' : '') + '</td>' +
          '<td>' + escapeHtml(product.category || '—') + '</td>' +
          '<td>' + escapeHtml(product.price) + '</td>' +
          '<td>' + (product.available
            ? '<span class="status-pill st-completed">متاح</span>'
            : '<span class="status-pill st-cancelled">غير متاح</span>') + '</td>' +
          '<td class="actions">' +
            '<button class="btn btn-ghost btn-sm" data-prod-edit="' + product.id + '">تعديل</button>' +
            '<button class="btn btn-ghost btn-sm" data-prod-del="' + product.id + '">حذف</button>' +
          '</td>' +
        '</tr>';
      }).join('') + '</tbody></table>';
  }

  function editProduct(id) {
    var product = id ? state.products.filter(function (p) { return p.id === id; })[0] : null;
    var categories = state.categories || [];

    var categoryOptions = '<option value="">— بدون تصنيف —</option>' +
      categories.map(function (c) {
        var selected = product && product.category_id === c.id ? ' selected' : '';
        return '<option value="' + c.id + '"' + selected + '>' + escapeHtml(c.name) + '</option>';
      }).join('');

    var extraFields = (state.productFields || []).map(function (field) {
      var value = (product && product.extra) ? (product.extra[field.key] || '') : '';
      if (field.type === 'boolean') {
        return '<div class="field"><label class="switch"><input type="checkbox" data-extra="' + escapeHtml(field.key) + '"' +
          (value ? ' checked' : '') + '><span class="switch-track"></span> ' + escapeHtml(field.label) + '</label></div>';
      }
      if (field.type === 'number') {
        return '<div class="field"><label>' + escapeHtml(field.label) + '</label>' +
          '<input type="number" data-extra="' + escapeHtml(field.key) + '" value="' + escapeHtml(value) + '"></div>';
      }
      return '<div class="field"><label>' + escapeHtml(field.label) + '</label>' +
        '<input type="text" data-extra="' + escapeHtml(field.key) + '" value="' + escapeHtml(value) + '"></div>';
    }).join('');

    /* حقول التفاصيل: تُملأ من ملف company.json/types — تظهر عند اختيار نوع النشاط */
    var detailFields = (state.detailFields || []).map(function (field) {
      var value = (product && product.extra) ? (product.extra[field.key] || '') : '';
      var hint = field.hint ? '<div class="field-hint">' + escapeHtml(field.hint) + '</div>' : '';
      if (field.type === 'select') {
        return '<div class="field full"><label>' + escapeHtml(field.label) + '</label><select data-detail="' +
          escapeHtml(field.key) + '"><option value="">—</option>' +
          (field.options || []).map(function (opt) {
            return '<option value="' + escapeHtml(opt.value) + '"' +
              (String(value) === String(opt.value) ? ' selected' : '') + '>' + escapeHtml(opt.label) + '</option>';
          }).join('') + '</select>' + hint + '</div>';
      }
      if (field.type === 'number') {
        return '<div class="field"><label>' + escapeHtml(field.label) + '</label>' +
          '<input type="number" data-detail="' + escapeHtml(field.key) + '" value="' + escapeHtml(value) + '">' + hint + '</div>';
      }
      return '<div class="field full"><label>' + escapeHtml(field.label) + '</label>' +
        '<textarea data-detail="' + escapeHtml(field.key) + '" maxlength="1200">' + escapeHtml(value) + '</textarea>' + hint + '</div>';
    }).join('');

    openModal(product ? 'تعديل منتج' : 'منتج جديد', '' +
      '<div class="field-row">' +
        '<div class="field"><label>اسم المنتج *</label><input id="p-name" value="' + escapeHtml(product ? product.name : '') + '" maxlength="120"></div>' +
        '<div class="field"><label>السعر</label><input id="p-price" inputmode="decimal" value="' + escapeHtml(product ? product.price : '') + '"></div>' +
      '</div>' +
      '<div class="field-row">' +
        '<div class="field"><label>التصنيف</label><select id="p-category">' + categoryOptions + '</select></div>' +
        '<div class="field"><label>الترتيب</label><input id="p-sort" type="number" value="' + (product ? product.sort_order : 0) + '"></div>' +
      '</div>' +
      '<div class="field"><label>الوصف</label><textarea id="p-desc" maxlength="3000">' +
        escapeHtml(product ? product.description : '') + '</textarea>' +
        '<div class="field-hint">إذا تجاوز الوصف 120 محرفًا ستُفتح صفحة تفاصيل للمنتج.</div></div>' +
      '<div class="field-row">' +
        '<div class="field"><label>الصورة</label><input type="file" id="p-image" accept="image/png,image/jpeg,image/webp,image/gif">' +
          (product && product.image ? '<div class="field-hint">الصورة الحالية: ' + escapeHtml(product.image) + '</div>' : '') + '</div>' +
        '<div class="field"><label>الحالة</label>' +
          '<label class="switch"><input type="checkbox" id="p-available"' + (product && !product.available ? '' : ' checked') + '>' +
          '<span class="switch-track"></span> متاح للطلب</label>' +
          '<label class="switch mt-sm"><input type="checkbox" id="p-featured"' + (product && product.featured ? ' checked' : '') + '>' +
          '<span class="switch-track"></span> مميّز</label>' +
        '</div>' +
      '</div>' +
      (extraFields ? '<h4 class="section-title bordered">حقول إضافية</h4>' + extraFields : '') +
      (detailFields ? '<h4 class="section-title bordered">تفاصيل المنتج</h4>' + detailFields : ''),
      function () {
        var name = $('p-name').value.trim();
        if (!name) { toast('اكتب اسم المنتج قبل الحفظ.', 'error'); return false; }

        var extra = {};
        document.querySelectorAll('[data-extra]').forEach(function (el) {
          extra[el.getAttribute('data-extra')] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
        });

        var details = {};
        document.querySelectorAll('[data-detail]').forEach(function (el) {
          details[el.getAttribute('data-detail')] = el.value;
        });

        var payload = {
          id: id || 0,
          name: name,
          price: $('p-price').value,
          category_id: parseInt($('p-category').value || '0', 10),
          sort_order: parseInt($('p-sort').value || '0', 10),
          description: $('p-desc').value,
          is_available: $('p-available').checked ? 1 : 0,
          is_featured: $('p-featured').checked ? 1 : 0,
          extra: extra,
          details: details
        };

        var chain = Promise.resolve();

        var fileInput = $('p-image');
        if (fileInput && fileInput.files && fileInput.files[0]) {
          chain = api('admin.product.image', { method: 'POST', body: (function () {
            var fd = new FormData();
            fd.append('image', fileInput.files[0]);
            return fd;
          })() }).then(function (data) {
            payload.image_path = data.image_path;
          });
        }

        return chain.then(function () {
          return api(id ? 'admin.product.update' : 'admin.product.create', { method: 'POST', body: payload });
        }).then(function (data) {
          state.products = data.products;
          renderProducts();
          toast(id ? 'تم حفظ تعديلات المنتج «' + name + '».' : 'أُضيف المنتج «' + name + '».', 'success');
        });
      });
  }

  function deleteProduct(id) {
    var product = state.products.filter(function (p) { return p.id === id; })[0];
    if (!product) return;

    confirmAction('سيُحذف المنتج «' + product.name + '» نهائيًا. الطلبات السابقة تحتفظ باسمه وسعره.', {
      title: 'حذف منتج',
      confirmText: 'حذف نهائي'
    }).then(function (ok) {
      if (!ok) return;
      return api('admin.product.delete', { method: 'POST', body: { id: id } }).then(function (data) {
        state.products = data.products;
        renderProducts();
        toast('تم حذف المنتج «' + product.name + '».', 'success');
      });
    }).catch(function (error) { toast(error.message, 'error'); });
  }

  /* ============================ المواقع ============================ */

  function loadLocations() {
    return api('admin.locations').then(function (data) {
      state.locations = data.locations;
      renderLocations(data.suggested_code);
      var select = $('orders-location');
      if (select && !select.dataset.ready) {
        select.innerHTML = '<option value="">كل المواقع</option>' +
          data.locations.map(function (l) {
            return '<option value="' + l.id + '">' + escapeHtml(l.name) + '</option>';
          }).join('');
        select.dataset.ready = '1';
        select.addEventListener('change', function () {
          state.orderFilters.location_id = select.value;
          if (state.view === 'orders') loadOrders();
        });
      }
    }).catch(function (error) { toast(error.message, 'error'); });
  }

  function renderLocations(suggested) {
    var container = $('locations-grid');
    if (!state.locations.length) {
      container.innerHTML = '<p class="empty">لا توجد مواقع بعد. أضف موقعًا (مثل الطاولة 1) لتوليد رموز QR.</p>';
      return;
    }

    container.innerHTML = state.locations.map(function (location) {
      var qr = CFG.api + '?r=admin.qr&type=location&value=' + encodeURIComponent(location.code);
      return '<div class="qr-card' + (location.active ? '' : ' inactive') + '">' +
        '<img src="' + escapeHtml(qr) + '" alt="رمز QR لـ' + escapeHtml(location.name) + '">' +
        '<h4>' + escapeHtml(location.name) + '</h4>' +
        '<div class="qr-code-text">' + escapeHtml(location.code) + '</div>' +
        '<div class="qr-url">' + escapeHtml(location.qr_url) + '</div>' +
        '<div class="qr-actions">' +
          '<button class="btn btn-ghost btn-sm" data-loc-edit="' + location.id + '">تعديل</button>' +
          '<button class="btn btn-ghost btn-sm" data-loc-copy="' + escapeHtml(location.qr_url) + '">نسخ الرابط</button>' +
          '<a class="btn btn-ghost btn-sm" href="' + escapeHtml(qr) + '" target="_blank" rel="noopener">فتح الرمز</a>' +
          '<button class="btn btn-ghost btn-sm" data-loc-print="' + escapeHtml(location.code) + '">طباعة</button>' +
          '<button class="btn btn-ghost btn-sm" data-loc-del="' + location.id + '">حذف</button>' +
        '</div>' +
      '</div>';
    }).join('');
  }

  function editLocation(id) {
    var location = id ? state.locations.filter(function (l) { return l.id === id; })[0] : null;

    openModal(location ? 'تعديل موقع' : 'موقع جديد', '' +
      '<div class="field-row">' +
        '<div class="field"><label>اسم الموقع *</label><input id="l-name" value="' +
          escapeHtml(location ? location.name : '') + '" placeholder="مثال: طاولة 5" maxlength="60"></div>' +
        '<div class="field"><label>الرمز (يظهر في رابط QR)</label><input id="l-code" dir="ltr" value="' +
          escapeHtml(location ? location.code : '') + '" placeholder="مثال: T05" maxlength="24"></div>' +
      '</div>' +
      '<div class="field-row">' +
        '<div class="field"><label>النوع (اختياري)</label><input id="l-type" value="' +
          escapeHtml(location ? location.type : '') + '" placeholder="indoor / terrace" maxlength="40"></div>' +
        '<div class="field"><label>الترتيب</label><input id="l-sort" type="number" value="' + (location ? location.sort_order : 0) + '"></div>' +
      '</div>' +
      '<div class="field"><label>ملاحظة</label><input id="l-note" value="' + escapeHtml(location ? location.note : '') + '" maxlength="200"></div>' +
      '<div class="field"><label class="switch"><input type="checkbox" id="l-active"' +
        (location && !location.active ? '' : ' checked') + '><span class="switch-track"></span> نشط</label>' +
        '<div class="field-hint">الموقع غير النشط لا يظهر للزبائن، ويرفض الخادم الطلبات عليه.</div></div>',
      function () {
        var payload = {
          id: id || 0,
          name: $('l-name').value.trim(),
          code: $('l-code').value.trim(),
          type: $('l-type').value.trim(),
          note: $('l-note').value.trim(),
          sort_order: parseInt($('l-sort').value || '0', 10),
          is_active: $('l-active').checked ? 1 : 0
        };
        if (!payload.name) { toast('اكتب اسم الموقع قبل الحفظ.', 'error'); return false; }

        return api('admin.location.save', { method: 'POST', body: payload }).then(function (data) {
          state.locations = data.locations;
          renderLocations();
          toast(id ? 'تم حفظ تعديلات الموقع «' + payload.name + '».' : 'أُضيف الموقع «' + payload.name + '».', 'success');
        });
      });
  }

  function deleteLocation(id) {
    var location = state.locations.filter(function (l) { return l.id === id; })[0];
    if (!location) return;

    confirmAction('سيُحذف الموقع «' + location.name + '» ورمز QR الخاص به. الطلبات السابقة تحتفظ باسمه.', {
      title: 'حذف موقع',
      confirmText: 'حذف نهائي'
    }).then(function (ok) {
      if (!ok) return;
      return api('admin.location.delete', { method: 'POST', body: { id: id } }).then(function (data) {
        state.locations = data.locations;
        renderLocations();
        toast('تم حذف الموقع «' + location.name + '».', 'success');
      });
    }).catch(function (error) { toast(error.message, 'error'); });
  }

  /* ============================ الإعدادات ============================ */

/**
   * بطاقة تعريفية داخل صفحة الإعدادات: تُظهر نوع النشاط والوضع وحالة ملف
   * الشركة. الغرض منها أن يعرف المستخدم من أين يأتي محتوى الموقع فعلًا،
   * فلا يبحث عن حقل مخفي في لوحة لا تحتويه أصلًا.
   */
  function renderProjectIdentity() {
    var config = state.config || {};
    var company = state.company || {};
    var box = $('project-identity');
    if (!box) return;

    var rows = [
      ['نوع النشاط', config.business_label || '—'],
      ['الوضع', config.has_ordering
        ? 'مع نظام الطلبات (سلة، إتمام طلب، متابعة)'
        : 'عرض فقط — بلا سلة ولا طلبات'],
      ['تسميات المنتجات', (config.product_singular || '') + ' / ' + (config.product_plural || '')],
      ['صفحة المنتجات', config.catalog_title || '—'],
      ['المواقع', config.locations_enabled
        ? (config.location_plural || 'مفعّلة')
        : 'معطّلة']
    ];

    var html = '<div class="detail-grid">' + rows.map(function (row) {
      return '<div class="detail-box"><div class="k">' + escapeHtml(row[0]) +
        '</div><div class="v">' + escapeHtml(row[1]) + '</div></div>';
    }).join('') + '</div>';

    html += '<p class="field-hint">لتغيير الوضع أو التسميات أو أنواع الطلبات: عدّل ' +
      '<code>src/Config/BusinessPresets.php</code> ثم نفّذ ' +
      '<code>php bin/console preset:apply</code>.</p>';

    box.innerHTML = html;
  }

  /** حالة ملف معلومات الشركة — المرجع الوحيد لمحتوى الصفحتين. */
  function renderCompanyFile() {
    var company = state.company || {};
    var box = $('company-file');
    if (!box) return;

    var path = company.path || 'app/data/company.json';

    var html = '<div class="detail-grid">' +
      '<div class="detail-box"><div class="k">الملف</div><div class="v"><code>' +
        escapeHtml(path) + '</code></div></div>' +
      '<div class="detail-box"><div class="k">الحالة</div><div class="v">' +
        (company.exists
          ? 'موجود · ' + (company.sections || 0) + ' قسم' +
            (company.has_contact ? ' · فيه وسائل تواصل' : ' · بلا وسائل تواصل بعد')
          : 'غير موجود — الصفحتان تعملان بإعدادات افتراضية') +
      '</div></div></div>';

    html += '<p class="field-hint">اسم النشاط والنبذة والصور ووسائل التواصل وأوقات العمل ' +
      'والأقسام كلها في هذا الملف الواحد. الدليل الكامل لكل مفتاح في ' +
      '<code>app/data/README.md</code>.</p>';

    box.innerHTML = html;
  }

  function loadSettings() {
    return api('admin.settings').then(function (data) {
      state.settings = data.settings;
      state.config = data.config;
      state.company = data.company || {};
      // حالات الطلبات متاحة ضمن إعدادات النسخة — تُحمَّل مرة واحدة
      if (data.config && data.config.statuses) {
        state.statuses = data.config.statuses;
      }
      renderSettingsForm();
    }).catch(function (error) { toast(error.message, 'error'); });
  }

  function renderSettingsForm() {
    var s = state.settings || {};
    var form = $('settings-form');

    form.innerHTML = '' +
      '<div class="field"><label>اسم النشاط</label><input id="s-name" value="' + escapeHtml(s.app_name || '') + '" maxlength="80"></div>' +
      '<div class="field"><label>وصف قصير</label><input id="s-tagline" value="' + escapeHtml(s.app_tagline || '') + '" maxlength="160"></div>' +
      '<div class="field full"><label>رسالة الترحيب</label><textarea id="s-welcome" maxlength="500">' + escapeHtml(s.welcome_message || '') + '</textarea></div>' +
      '<div class="field"><label>الشعار</label><input type="file" id="s-logo" accept="image/png,image/jpeg,image/webp,image/gif">' +
        (s.logo_path ? '<div class="field-hint">الشعار الحالي: ' + escapeHtml(s.logo_path) + '</div>' : '') + '</div>' +
      '<div class="field"><label>هاتف التواصل</label><input id="s-phone" value="' + escapeHtml(s.contact_phone || '') + '" maxlength="30"></div>' +
      '<div class="field"><label>إنستغرام</label><input id="s-instagram" value="' + escapeHtml(s.contact_instagram || '') + '" maxlength="60"></div>' +
      '<div class="field full"><label>خيارات العرض</label>' +
        toggle('s-show-welcome', 'إظهار صفحة الترحيب', s.show_welcome) +
        toggle('s-show-search', 'إظهار البحث', s.show_search) +
        toggle('s-show-contact', 'إظهار بيانات التواصل', s.show_contact) +
        toggle('s-show-prices', 'إظهار الأسعار', s.show_prices) +
      '</div>' +
      '<div class="form-actions"><button class="btn btn-primary" type="submit">حفظ الإعدادات</button></div>';

    renderProjectIdentity();

    form.onsubmit = function (event) {
      event.preventDefault();
      var btn = form.querySelector('button[type="submit"]');
      setLoading(btn, true, 'جارٍ الحفظ…');
      var fd = new FormData();
      ['app_name:s-name', 'app_tagline:s-tagline', 'welcome_message:s-welcome',
       'contact_phone:s-phone', 'contact_instagram:s-instagram'].forEach(function (pair) {
        var parts = pair.split(':');
        fd.append(parts[0], $(parts[1]).value);
      });
      ['show_welcome:s-show-welcome', 'show_search:s-show-search',
       'show_contact:s-show-contact', 'show_prices:s-show-prices'].forEach(function (pair) {
        var parts = pair.split(':');
        fd.append(parts[0], $(parts[1]).checked ? '1' : '0');
      });
      if ($('s-logo').files && $('s-logo').files[0]) fd.append('logo', $('s-logo').files[0]);

      api('admin.settings.update', { method: 'POST', body: fd }).then(function (data) {
        state.settings = data.settings;
        renderSettingsForm();
        renderBrandPreview();
    renderCompanyFile();
        toast('تم حفظ الإعدادات. تظهر التغييرات في واجهة الزبون مباشرة.', 'success');
      }).catch(function (error) { toast(error.message, 'error'); })
        .finally(function () { setLoading(btn, false); });
    };

    renderBrandPreview();
    renderCompanyFile();

    function toggle(id, label, checked) {
      return '<label class="switch me-lg"><input type="checkbox" id="' + id + '"' +
        (checked ? ' checked' : '') + '><span class="switch-track"></span> ' + label + '</label>';
    }
  }

  function renderBrandPreview() {
    var s = state.settings || {};
    var preview = $('brand-preview');
    preview.innerHTML = (s.logo_url ? '<img src="' + escapeHtml(s.logo_url) + '" alt="">' : '') +
      '<div><h4>' + escapeHtml(s.app_name || 'اسم النشاط') + '</h4>' +
      '<p>' + escapeHtml(s.app_tagline || '') + '</p></div>';
    $('side-app-name').textContent = s.app_name || 'لوحة الإدارة';
  }

  /* ============================ الأمان والنسخ ============================ */

  function loadSecurity() {
    api('admin.backups').then(function (data) {
      var container = $('backups-table');
      if (!data.backups.length) {
        container.innerHTML = '<p class="empty">لا توجد نسخ احتياطية بعد.</p>';
      } else {
        container.innerHTML = '<table><thead><tr><th>الملف</th><th>التاريخ</th><th>الحجم</th><th></th></tr></thead><tbody>' +
          data.backups.map(function (backup) {
            return '<tr><td class="cell-title">' + escapeHtml(backup.name) + '</td>' +
              '<td>' + escapeHtml(backup.date) + '</td><td>' + escapeHtml(backup.size) + '</td>' +
              '<td class="actions"><a class="btn btn-ghost btn-sm" href="' + escapeHtml(CFG.api) +
                '?r=admin.backup.download&name=' + encodeURIComponent(backup.name) + '">تنزيل</a></td></tr>';
          }).join('') + '</tbody></table>';
      }
    }).catch(function (error) { toast(error.message, 'error'); });

    $('version-info').innerHTML = '' +
      infoBox('نوع النشاط', state.config.business_type || '—') +
      infoBox('العملة', state.config.currency || '—') +
      infoBox('نوع الطلبات', (state.config.order_types || []).map(function (t) { return t.label; }).join('، ') || '—') +
      infoBox('التقنية', 'PHP ' + '· SQLite · PWA');

    function infoBox(k, v) {
      return '<div class="detail-box"><div class="k">' + escapeHtml(k) + '</div><div class="v">' + escapeHtml(v) + '</div></div>';
    }

    if (CFG.rebuild) loadInstance();
  }

  /* ==================================================================
     منطقة الخطر — تدمير النسخة والعودة إلى نقطة الصفر
     ==================================================================
     ثلاث خطوات لا يمكن تجاوز أي منها، وكل واحدة تُعيد الاستعلام قبل
     التالي: نعرض ما سيُحذف، ثم نطلب العبارة، ثم كلمة المرور. لا يوجد
     زر واحد يفجر كل شيء — لأن الفأرة تنقر في الثانية نفسها.
     ================================================================== */

  function loadInstance() {
    var container = $('instance-state');
    if (!container) return;

    return api('admin.instance.state').then(function (data) {
      state.instance = data;

      if (!data.installed) {
        container.innerHTML = infoLine('الحالة', 'غير مهيأة — بانتظار أول بناء');
        $('instance-destroy').disabled = true;
        return;
      }

      var c = data.counts;
      container.innerHTML = [
        infoLine('الطلبات', String(c.orders)),
        infoLine('المنتجات', String(c.products)),
        infoLine('التصنيفات', String(c.categories)),
        infoLine('المواقع', String(c.locations)),
        infoLine('حجم القاعدة', data.sizes.database),
        infoLine('حجم الصور', data.sizes.uploads)
      ].join('');
    }).catch(function (error) {
      // 404 = الميزة غير مفعّلة في هذه النسخة: نخفي المنطقة بدل إظهار زر لا يعمل.
      if (error.code === 'disabled') {
        var panel = container.closest('.panel-danger');
        if (panel) panel.hidden = true;
        return;
      }
      toast(error.message, 'error');
    });

    function infoLine(k, v) {
      return '<div class="detail-box"><div class="k">' + escapeHtml(k) +
        '</div><div class="v">' + escapeHtml(v) + '</div></div>';
    }
  }

  /** ما الذي سيُحذف — القائمة تُعرض قبل التأكيد لا بعده. */
  function destroyPlan(data) {
    var c = data.counts;
    return '' +
      item(c.orders + ' طلبًا') +
      item(c.products + ' منتجًا و ' + c.categories + ' تصنيفًا') +
      item(c.locations + ' موقعًا ورموزه المطبوعة') +
      item('كل الصور المرفوعة (' + data.sizes.uploads + ')') +
      item('بيانات الدخول ومفتاح التطبيق ورابط الموقع') +
      item('كل النسخ الاحتياطية (' + data.sizes.backups + ')') +
      item('<code>' + escapeHtml(data.paths.database) + '</code>') +
      item('<code>' + escapeHtml(data.paths.config) + '</code>') +
      item('<code>' + escapeHtml(data.paths.company) + '</code>') +
      '<div class="danger-note"><strong>لا يُحذف القالب.</strong> تبقى الشيفرة ' +
      'والواجهة واللوحة كما هي، وتعود النسخة إلى ما قبل أول تهيئة لتُبنى ' +
      'من جديد بنشاط مختلف تمامًا.</div>';

    function item(html) { return '<li>' + html + '</li>'; }
  }

  function destroyInstance() {
    var data = state.instance;
    if (!data || !data.installed) {
      toast('لا توجد نسخة مهيأة لتدميرها.', 'warn');
      return;
    }

    // نافذة مستقلة عن openModal/ submitModal: مسار التدمير يفتح نافذة
    // جديدة بعد نجاحه، ودورة حفظ النافذة المشتركة كانت تُعيد ضبط الزر
    // وتُغلق ما فتحناه. هنا نتحكم نحن في كل شيء.
    openModal('تدمير النسخة والعودة للبداية',
      '<ul class="danger-list">' + destroyPlan(data) + '</ul>' +
      '<p class="danger-field-label">لتأكيد الحذف، اكتب كلمة: ' +
      '<code>' + escapeHtml(data.confirm_word) + '</code></p>' +
      '<input type="text" id="destroy-confirm" autocomplete="off" ' +
      'placeholder="' + escapeHtml(data.confirm_word) + '">' +
      '<div class="field" style="margin-top:14px">' +
      '<label for="destroy-password">كلمة مرور المدير (للتأكيد)</label>' +
      '<input type="password" id="destroy-password" autocomplete="current-password">' +
      '</div>' +
      '<label class="danger-check"><input type="checkbox" id="destroy-archive" checked>' +
      '<span>حفظ نسخة أمان قبل الحذف في <code>storage/destroyed/</code></span></label>',
      null
    );

    wireModalButton('تدمير نهائي', 'btn btn-danger', submitDestroy);

    // Enter في حقل كلمة المرور ينفّذ التأكيد — السلوك المتوقع في نموذج.
    $('destroy-password').addEventListener('keydown', function (event) {
      if (event.key === 'Enter') submitDestroy();
    });

    function submitDestroy() {
      var word  = ($('destroy-confirm').value || '').trim();
      var pass  = $('destroy-password').value;
      var keep  = $('destroy-archive').checked;

      // تحقق في الواجهة أيضًا: يمنع رحلة مكلفة بلا نتيجة، ويجعل
      // سبب الرفض واضحًا قبل أن يصل الطلب إلى الخادم.
      if (word !== data.confirm_word) {
        toast('عبارة التأكيد غير مطابقة. لم يُحذف شيء.', 'error');
        return;
      }
      if (!pass) {
        toast('أدخل كلمة مرور المدير.', 'error');
        return;
      }

      var btn = $('modal-save');
      setLoading(btn, true, 'جارٍ التدمير…');

      api('admin.instance.destroy', {
        method: 'POST',
        body: { confirm: word, password: pass, archive: keep ? '1' : '0' }
      }).then(function (result) {
        // بعد النجاح لا شيء مما كان في الشاشة صحيحًا: أوقف المؤقتات
        // قبل فتح نافذة النتيجة، وإلا حاول طلبًا على قاعدة محذوفة.
        stopAutoRefresh();
        showDestroyed(result);
      }).catch(function (error) {
        toast(error.message, 'error');
        setLoading(btn, false);
      });
    }
  }

  /** يضبط زر النافذة لمهمة واحدة مع إعادة الحالة الأصلية عند الفتح. */
  function wireModalButton(label, className, handler) {
    var btn = $('modal-save');
    btn.className = className;
    btn.textContent = label;
    btn.disabled = false;
    btn.removeAttribute('is-loading');
    btn.onclick = handler;
  }

  /** ما بعد التدمير: إمّا البناء الجديد، أو تنبيه صريح إن كانت الصفحة محذوفة. */
  function showDestroyed(result) {
    var removed = (result.removed || []).length;

    openModal('انتهت هذه النسخة',
      '<div class="alert err"><strong>تم تدمير النسخة.</strong> حُذف ' + removed +
      ' عنصرًا، والموقع الآن في حالة ما قبل أول تهيئة.</div>' +
      (result.archive
        ? '<div class="alert ok" style="margin-top:12px">نسخة الأمان: <code>' +
          escapeHtml(result.archive) + '</code> — تحتفظ بقاعدة البيانات وبيانات ' +
          'الدخول إن احتجت الاسترجاع.</div>'
        : '<div class="alert warn" style="margin-top:12px">طلبتَ الحذف بلا نسخة أمان. ' +
          'لا سبيل للاسترجاع.</div>') +
      (result.rebuild_url
        ? '<div class="danger-note" style="margin-top:14px"><strong>احفظ رابط البناء ' +
          'الجديد — لن يُعرض مرة أخرى.</strong><br><code style="word-break:break-all">' +
          escapeHtml(result.rebuild_url) + '</code><br>بدونه تبقى صفحة التهيئة مقفلة، ' +
          'والمفتاح في حال ضياعه: <code>php bin/console instance state</code>.</div>'
        : '<div class="danger-note" style="margin-top:14px">لم يُحفظ رابط للنسخة، فابنِ ' +
          'من الطرفية: <code>php bin/console install --url=… --user=… --pass="…"</code>.</div>'),
      null
    );

    wireModalButton(
      result.rebuild_url ? 'ابدأ البناء من جديد' : 'إغلاق',
      'btn btn-primary',
      function () {
        global.location.href = result.rebuild_url
          || (CFG.setup_ready ? CFG.setup : CFG.home);
      }
    );
  }

  function changePassword(event) {
    event.preventDefault();
    var alert = $('password-error');
    var btn = $('password-form').querySelector('button[type="submit"]');
    setHidden(alert, true);

    var current = $('pw-current').value;
    var next = $('pw-new').value;
    if (next !== $('pw-confirm').value) {
      alert.textContent = 'كلمتا المرور غير متطابقتين.';
      setHidden(alert, false);
      toast('كلمتا المرور غير متطابقتين.', 'error');
      return;
    }

    setLoading(btn, true, 'جارٍ التحديث…');
    api('admin.password', { method: 'POST', body: { current_password: current, new_password: next } })
      .then(function () {
        $('password-form').reset();
        toast('تم تغيير كلمة المرور. استخدمها عند الدخول القادم.', 'success');
      })
      .catch(function (error) {
        alert.textContent = error.message;
        setHidden(alert, false);
        toast(error.message, 'error');
      })
      .finally(function () { setLoading(btn, false); });
  }

  function createBackup() {
    var btn = $('backup-create');
    setLoading(btn, true, 'جارٍ الإنشاء…');
    api('admin.backup.create', { method: 'POST' }).then(function (data) {
      toast(data.message || 'تم إنشاء نسخة احتياطية.', 'success');
      loadSecurity();
    }).catch(function (error) { toast(error.message, 'error'); })
      .finally(function () { setLoading(btn, false); });
  }

  /* ============================ التحديث الدوري ============================ */

  function startAutoRefresh() {
    stopAutoRefresh();
    if (!(state.config.features && state.config.features.auto_refresh)) return;
    state.refreshTimer = setInterval(function () {
      if (document.hidden) return;
      api('admin.orders.active').then(function (data) {
        var previous = (state.stats && state.stats.new) || 0;
        state.stats = data.stats;
        updateOrderBadge();
        if (state.view === 'dashboard') loadDashboard();
        else if (state.view === 'orders' && previous !== data.stats.new) loadOrders();
      }).catch(function () { /* تجاهل انقطاع قصير */ });
    }, 20000);
  }

  function stopAutoRefresh() {
    if (state.refreshTimer) clearInterval(state.refreshTimer);
    state.refreshTimer = null;
  }

  /* ============================ الدرج والنوافذ ============================ */

  function openDrawer(title, html) {
    $('drawer-title').textContent = title;
    $('drawer-body').innerHTML = html;
    $('drawer').hidden = false;
    $('drawer-backdrop').hidden = false;
  }

  function closeDrawer() {
    $('drawer').hidden = true;
    $('drawer-backdrop').hidden = true;
  }

  function openModal(title, html, onSave) {
    $('modal-title').textContent = title;
    $('modal-form').innerHTML = html;
    state.modalSubmit = onSave;

    /*
     * يُعاد ضبط زر الحفظ هنا في كل فتح. نافذة التدمير تستبدل onclick
     * بمعالجها الخاص (لأنها تفتح نافذة أخرى بعد نجاحها)، فلو بقي
     * المعالج القديم لاشتغل مع أي نافذة عادية تُفتح بعدها — بل وحتى
     * لونها الأحمر واسمها «تدمير نهائي» كانا سيبقيان.
     */
    var save = $('modal-save');
    save.onclick = null;
    save.className = 'btn btn-primary';
    save.disabled = false;
    save.textContent = 'حفظ';

    $('modal-backdrop').hidden = false;
    var first = $('modal-form').querySelector('input, select, textarea');
    if (first) first.focus();
  }

  function closeModal() {
    $('modal-backdrop').hidden = true;
    state.modalSubmit = null;
  }

  function submitModal() {
    if (!state.modalSubmit) return;
    var btn = $('modal-save');
    setLoading(btn, true, 'جارٍ الحفظ…');
    Promise.resolve(state.modalSubmit()).then(function (result) {
      if (result !== false) closeModal();
    }).catch(function (error) {
      toast(error.message, 'error');
    }).finally(function () { setLoading(btn, false); });
  }

  /* ============================ الأحداث ============================ */

  function bindEvents() {
    $('login-form').addEventListener('submit', login);
    $('logout-button').addEventListener('click', logout);
    $('menu-toggle').addEventListener('click', function () { $('sidebar').classList.toggle('open'); });

    document.querySelectorAll('.side-link[data-view]').forEach(function (btn) {
      btn.addEventListener('click', function () { go(btn.getAttribute('data-view')); });
    });
    document.querySelectorAll('[data-goto]').forEach(function (btn) {
      btn.addEventListener('click', function () { go(btn.getAttribute('data-goto')); });
    });

    $('refresh-button').addEventListener('click', function () {
      go(state.view);
      toast('تم تحديث البيانات.', 'success');
    });
    $('orders-clear').addEventListener('click', function () {
      state.orderFilters = { status: '', location_id: '', q: '', date: '' };
      $('orders-search').value = '';
      $('orders-status').value = '';
      $('orders-location').value = '';
      $('orders-date').value = '';
      loadOrders();
      toast('تم مسح كل الفلاتر.', 'info');
    });

    var searchTimer;
    $('orders-search').addEventListener('input', function (event) {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        state.orderFilters.q = event.target.value;
        loadOrders();
      }, 350);
    });
    $('orders-date').addEventListener('change', function (event) {
      state.orderFilters.date = event.target.value;
      loadOrders();
    });

    $('category-new').addEventListener('click', function () { editCategory(null); });
    $('product-new').addEventListener('click', function () { editProduct(null); });
    $('location-new').addEventListener('click', function () { editLocation(null); });
    $('backup-create').addEventListener('click', createBackup);
    // الزر غير موجود أصلًا إن كانت الميزة غير مفعّلة (انظر admin/index.php).
    if ($('instance-destroy')) $('instance-destroy').addEventListener('click', destroyInstance);
    $('password-form').addEventListener('submit', changePassword);
    $('print-qr').addEventListener('click', function () {
      global.open(CFG.api + '?r=admin.qr.sheet', '_blank');
      toast('فتحت صفحة طباعة كل الرموز في تبويب جديد.', 'info');
    });

    // أحداث الجداول (تفويض)
    document.body.addEventListener('click', function (event) {
      var el;

      if ((el = event.target.closest('[data-order]'))) return openOrder(el.getAttribute('data-order'));
      if ((el = event.target.closest('[data-start]'))) return changeStatus(parseInt(el.getAttribute('data-start'), 10), 'in_progress');
      if ((el = event.target.closest('[data-status]'))) return changeStatus(state.currentOrderId, el.getAttribute('data-status'));
      if ((el = event.target.closest('[data-cat-edit]'))) return editCategory(parseInt(el.getAttribute('data-cat-edit'), 10));
      if ((el = event.target.closest('[data-cat-del]'))) return deleteCategory(parseInt(el.getAttribute('data-cat-del'), 10));
      if ((el = event.target.closest('[data-cat-up]'))) return moveCategory(parseInt(el.getAttribute('data-cat-up'), 10), -1);
      if ((el = event.target.closest('[data-cat-down]'))) return moveCategory(parseInt(el.getAttribute('data-cat-down'), 10), 1);
      if ((el = event.target.closest('[data-prod-edit]'))) return editProduct(parseInt(el.getAttribute('data-prod-edit'), 10));
      if ((el = event.target.closest('[data-prod-del]'))) return deleteProduct(parseInt(el.getAttribute('data-prod-del'), 10));
      if ((el = event.target.closest('[data-loc-edit]'))) return editLocation(parseInt(el.getAttribute('data-loc-edit'), 10));
      if ((el = event.target.closest('[data-loc-del]'))) return deleteLocation(parseInt(el.getAttribute('data-loc-del'), 10));
      if ((el = event.target.closest('[data-loc-copy]'))) {
        var text = el.getAttribute('data-loc-copy');
        copyToClipboard(text).then(function () {
          toast('نُسخ رابط الموقع. الصقه حيث تشاء.', 'success');
        });
        return;
      }
      if ((el = event.target.closest('[data-loc-print]'))) {
        var code = el.getAttribute('data-loc-print');
        global.open(CFG.api + '?r=admin.qr&type=location&value=' + encodeURIComponent(code), '_blank');
        return;
      }
    });

    $('drawer-close').addEventListener('click', closeDrawer);
    $('drawer-backdrop').addEventListener('click', closeDrawer);
    $('modal-close').addEventListener('click', closeModal);
    $('modal-cancel').addEventListener('click', closeModal);
    $('modal-save').addEventListener('click', submitModal);
    $('modal-backdrop').addEventListener('click', function (event) {
      if (event.target.id === 'modal-backdrop') closeModal();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;
      if (!$('modal-backdrop').hidden) closeModal();
      else if (!$('drawer').hidden) closeDrawer();
    });

    global.addEventListener('hashchange', function () {
      var view = (global.location.hash || '').replace('#', '');
      if (view && view !== state.view) go(view);
    });
  }

  /* ============================ الإقلاع ============================ */

  function boot() {
    go((global.location.hash || '#dashboard').replace('#', '') || 'dashboard');
    loadSettings();
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindEvents();
    if (CFG.authenticated) {
      showApp();
      boot();
    } else {
      showLogin();
    }
  });
})(window);