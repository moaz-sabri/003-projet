/* ==========================================================================
   app.js — الإقلاع، التوجيه، والأحداث.

   التوجيه: روابط نظيفة حقيقية (/cart، /checkout، /order/{رمز}) عبر History API.
   إن كان الخادم لا يوجّه المسارات (استضافة مشتركة بلا إعادة توجيه)، يتحوّل
   التطبيق تلقائيًا إلى وضع الهاش (#/cart) بدل أن يفتح رابط 404 للزبون.
   ========================================================================== */
(function (global) {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };
  var Api = global.App.Api, Store = global.App.Store, UI = global.App.UI;

  var CONFIG = global.APP_CONFIG || {};
  var BASE   = CONFIG.base || '/';
  var HOME   = CONFIG.home || BASE;

  var ROUTER_KEY = 'lc_router_mode';

  /* الشاشات المتاحة في الواجهة */
  var VIEWS = ['menu', 'cart', 'checkout', 'success', 'track'];

  var VIEW_TITLES = {
    menu: 'القائمة', cart: 'السلة', checkout: 'إتمام الطلب',
    success: 'تم استلام طلبك', track: 'متابعة الطلب'
  };

  var state = {
    view: 'menu',
    categoryId: '',
    search: '',
    orderType: '',
    location: null,
    submitting: false,
    trackingToken: ''
  };

  /* ============================== التوجيه ============================== */

  var Router = {
    mode: 'path',
    current: function () { return state.view; },

    /** المسار الداخلي بدون جذر الموقع: /cart → cart */
    route: function () {
      if (Router.mode === 'hash') {
        return strip((location.hash || '').replace(/^#\/?/, ''));
      }
      var path = location.pathname || '/';
      if (BASE !== '/' && path.indexOf(BASE) === 0) path = path.slice(BASE.length);
      return strip(path);
    },

    /** الانتقال إلى مسار داخلي: '' | 'cart' | 'order/TOKEN' */
    go: function (path, replace) {
      path = strip(path || '');

      if (Router.mode === 'hash') {
        var target = '#/' + path;
        if (location.hash === target) { render(); return; }
        if (replace) {
          history.replaceState(null, '', BASE + target + location.search);
          render();
        } else {
          location.hash = target;
        }
        return;
      }

      var url = BASE + path;
      var current = location.pathname + location.search;
      if (url === current) { render(); return; }

      if (replace) history.replaceState(null, '', url + location.search);
      else history.pushState(null, '', url);
      render();
    }
  };

  function strip(value) {
    return String(value || '').replace(/^\/+|\/+$/g, '');
  }

  /**
   * هل يدعم الخادم المسارات النظيفة؟
   * 1) إن فُتح التطبيق على مسار غير الجذر فهو يوجّه أصلًا.
   * 2) وإلا نُجري فحصًا واحدًا خفيفًا يُحفظ في التخزين المحلي (مرة واحدة).
   */
  function detectRouting() {
    var cached = null;
    try { cached = localStorage.getItem(ROUTER_KEY); } catch (e) { /* تجاهل */ }
    if (cached === 'path' || cached === 'hash') return Promise.resolve(cached);

    if (Router.route() !== '') return Promise.resolve('path');

    if (!global.fetch) return Promise.resolve('hash');

    return fetch(BASE + '__route_probe__', { cache: 'no-store', headers: { 'X-LC-Probe': '1' } })
      .then(function (response) {
        // ترويسة X-Shop-Route موجودة فقط حين يوجّه الخادم المسار إلى index.php
        return response.headers.get('x-shop-route') ? 'path' : 'hash';
      })
      .catch(function () { return 'path'; })
      .then(function (mode) {
        try { localStorage.setItem(ROUTER_KEY, mode); } catch (e) { /* تجاهل */ }
        return mode;
      });
  }

  function splitRoute() {
    var parts = Router.route().split('/').filter(Boolean);
    return { name: parts[0] || '', arg: parts[1] ? decodeURIComponent(parts[1]) : '' };
  }

  function render() {
    var route = splitRoute();

    switch (route.name) {
      case '':
      case 'menu':
        return show('menu');

      case 'cart':
        return show('cart');

      case 'checkout':
        return show('checkout');

      case 'success':
        return show('success');

      case 'order':
      case 'track':
        if (route.arg) {
          state.trackingToken = route.arg;
          return show('track');
        }
        return unknownRoute();

      case 'product':
        if (route.arg) {
          show('menu');
          openProductDetails(parseInt(route.arg, 10));
          return;
        }
        return unknownRoute();

      default:
        return unknownRoute();
    }
  }

  function unknownRoute() {
    Router.go('', true);
    UI.toast('الصفحة التي تبحث عنها غير موجودة. أعدنا بك إلى القائمة.', 'warn');
  }

  function show(name) {
    var changed = state.view !== name;
    state.view = name;

    VIEWS.forEach(function (view) {
      var el = $('view-' + view);
      if (el) el.hidden = (view !== name);
    });
    $('view-welcome').hidden = true;
    $('header-nav').hidden = (name !== 'menu');

    if (changed) {
      window.scrollTo(0, 0);
      var content = $('content');
      if (content && content.focus) content.focus({ preventScroll: true });
      applyViewTitle();
      UI.renderCart();
    }

    if (name === 'menu') renderMenu();
    if (name === 'cart') UI.renderCart();
    if (name === 'checkout') prepareCheckout();
    if (name === 'success') restoreLastSuccess();
    if (name === 'track') loadTracking();
  }

  /** عنوان الصفحة يوضّح أين نحن — مهم عند فتح الرابط في تبويب جديد. */
  function applyViewTitle() {
    var app = (Store.settings && Store.settings.app_name) || 'القائمة';
    var title = VIEW_TITLES[state.view] || '';
    document.title = state.view === 'menu' ? app : title + ' · ' + app;
  }

  /* ============================== التحميل ============================== */

  function loadCatalog() {
    return Api.snapshot(state.search)
      .then(function (data) {
        Store.setSnapshot(data);
        applySnapshot();
      })
      .catch(function (error) {
        // لا يوجد اتصال؟ نعرض النسخة المحفوظة إن وُجدت (readme §11)
        var cached = Store.getCachedSnapshot();
        if (cached) {
          Store.snapshot = cached;
          applySnapshot();
          UI.setOffline(true);
          var age = Store.cacheAge();
          var notice = $('menu-notice');
          if (notice && age !== null) {
            notice.innerHTML = '<span aria-hidden="true">⚠</span><span>' +
              'تعرض الآن نسخة محفوظة من القائمة' +
              (age > 3600000 ? ' عمرها ' + Math.round(age / 3600000) + ' ساعة.' : ' قبل دقائق.') +
              '</span>';
            UI.setHidden(notice, false);
          }
          // الخدمة المحدّثة حدّثت الكاش في الخلفية — نقرأ النسخة الأحدث بعد لحظات
          if (Api.online) {
            setTimeout(function () {
              loadCatalog().then(function () {
                var fresh = $('menu-notice');
                if (fresh) UI.setHidden(fresh, true);
              });
            }, 2500);
          }
          return;
        }

        UI.hideSkeleton();
        UI.setOffline(true);
        UI.toast(error.message, 'error');
      });
  }

  function applySnapshot() {
    var settings = Store.settings || {};
    UI.renderBrand(settings, Store.config || {});
    UI.renderSearch(Store.settings.show_search !== false);
    UI.hideSkeleton();
    applyViewTitle();
    renderMenu();
  }

  function renderMenu() {
    if (!Store.snapshot) return;
    var features = (Store.config && Store.config.features) || {};

    UI.renderTabs(Store.snapshot.categories || [], state.categoryId);
    UI.renderLocationChip(Store.location, Store.config || {});

    var products = (Store.snapshot.products || []).filter(function (product) {
      var matchCategory = !state.categoryId || String(product.category_id) === String(state.categoryId);
      return matchCategory;
    });

    UI.renderProducts(products, { search: state.search });
    UI.renderCart();
  }

  /* =============================== الموقع =============================== */

  /** ?loc=CODE قادم من رمز QR: يُطبَّق ثم يُنظَّف الرابط. */
  function applyLocationFromUrl() {
    var params = new URLSearchParams(location.search);
    var code = params.get('loc');
    if (!code) return false;

    var locations = (Store.snapshot && Store.snapshot.locations) || [];
    var match = locations.filter(function (l) { return l.code === code; })[0];

    if (match && match.active) {
      Store.setLocation(match);
      state.location = match;
      cleanQuery(['loc']);
      return true;
    }

    UI.toast('رمز الموقع غير معروف. اختر موقعك من القائمة.', 'warn');
    cleanQuery(['loc']);
    return false;
  }

  /** إزالة معاملات انتهت صلاحيتها من الرابط دون إعادة تحميل الصفحة. */
  function cleanQuery(keys) {
    if (!keys.length) return;
    var params = new URLSearchParams(location.search);
    var changed = false;
    keys.forEach(function (key) {
      if (params.has(key)) { params.delete(key); changed = true; }
    });
    if (!changed) return;

    var query = params.toString();
    var url = location.pathname + (query ? '?' + query : '') + location.hash;
    history.replaceState(null, '', url);
  }

  /* ============================== المنتج ============================== */

  function openProductDetails(id) {
    var local = findProduct(id);
    if (!local) {
      // قد يكون التطبيق ما زال يحمّل الكتالوج: ننتظر ثم نفتح
      if (Store.snapshot === null) {
        loadCatalog().then(function () { openProductDetails(id); });
        return;
      }
      UI.toast('هذا المنتج لم يعد متاحًا.', 'warn');
      Router.go('', true);
      return;
    }

    UI.renderProductSheet(local, Store.config || {});
    var footer = $('sheet-footer');
    footer.hidden = local.available === false;
    footer.setAttribute('data-product-id', String(id));
    $('product-sheet').hidden = false;
    $('sheet-backdrop').hidden = false;
    document.body.style.overflow = 'hidden';
    var close = $('sheet-close');
    if (close) close.focus({ preventScroll: true });

    // إن كان للمنتج تفاصيل أعرضها من الخادم للتأكد
    if (local.has_details) {
      Api.product(id).then(function (detail) {
        if (!$('product-sheet').hidden) UI.renderProductSheet(detail, Store.config || {});
      }).catch(function () { /* نعرض النسخة المحلية */ });
    }
  }

  function closeSheet() {
    $('product-sheet').hidden = true;
    $('sheet-backdrop').hidden = true;
    document.body.style.overflow = '';
    if (Router.route().indexOf('product/') === 0) Router.go('', true);
  }

  function findProduct(id) {
    var products = (Store.snapshot && Store.snapshot.products) || [];
    for (var i = 0; i < products.length; i++) {
      if (products[i].id === id) return products[i];
    }
    return null;
  }

  /* =============================== السلة =============================== */

  function addToCart(id, quantity) {
    var product = findProduct(id);
    if (!product) return;

    if (product.available === false) {
      UI.toast('هذا المنتج لم يعد متاحًا.', 'warn');
      return;
    }

    var already = Store.quantityOf(id);
    Store.addItem(product, quantity || 1);
    UI.renderCart();
    renderMenu();

    UI.toast(
      '«' + product.name + '» في السلة · العدد ' + Store.quantityOf(id),
      'success'
    );
  }

  /* ============================== الدفع ============================== */

  function prepareCheckout() {
    if (!Store.cart.length) {
      UI.toast('السلة فارغة. أضف صنفًا واحدًا على الأقل.', 'info');
      Router.go('cart');
      return;
    }

    var config = Store.config || {};
    var types = config.order_types || [];
    if (!state.orderType || !types.some(function (t) { return t.key === state.orderType; })) {
      state.orderType = (types.filter(function (t) { return t.default; })[0] || types[0] || {}).key || '';
    }

    UI.renderOrderTypes(types, state.orderType);
    applyOrderTypeFields();

    $('checkout-total').textContent = UI.formatPrice(Store.total());
    UI.clearError('checkout-error');
    UI.setOffline(!navigator.onLine);
  }

  function currentOrderType() {
    var types = (Store.config && Store.config.order_types) || [];
    return types.filter(function (t) { return t.key === state.orderType; })[0] || null;
  }

  function applyOrderTypeFields() {
    var type = currentOrderType();
    if (!type) return;

    UI.renderDynamicFields(type.fields || []);
    UI.renderLocations(
      (Store.snapshot && Store.snapshot.locations) || [],
      Store.location ? Store.location.code : '',
      !!type.requires_location
    );

    var nameField = $('field-name');
    var label = (Store.config && Store.config.customer_name_label) || 'اسمك';
    $('label-customer-name').textContent = label;
    $('customer-name').placeholder = (Store.config && Store.config.customer_name_placeholder) || '';
    if (nameField) nameField.hidden = false;

    // إخفاء حقل الموقع إن كان محددًا من رمز QR ولا يُطلب اختياره
    if (type.requires_location && Store.location) {
      $('field-location').hidden = true;
    }
  }

  function collectPayload() {
    var fields = {};
    document.querySelectorAll('#dynamic-fields [name]').forEach(function (input) {
      fields[input.name] = input.value;
    });
    document.querySelectorAll('#dynamic-fields [data-bool][aria-pressed="true"]').forEach(function (btn) {
      fields[btn.getAttribute('data-bool')] = true;
    });

    var locationCode = Store.location ? Store.location.code : ($('location-select') ? $('location-select').value : '');
    if ($('location-select') && $('location-select').value) {
      locationCode = $('location-select').value;
      var all = (Store.snapshot && Store.snapshot.locations) || [];
      var match = all.filter(function (l) { return l.code === locationCode; })[0];
      if (match) Store.setLocation(match);
    }

    return {
      order_type: state.orderType,
      customer_name: ($('customer-name') ? $('customer-name').value : '').trim(),
      location_code: locationCode,
      fields: fields,
      items: Store.cart.map(function (item) {
        return { product_id: item.product_id, quantity: item.quantity };
      }),
      idempotency_key: Store.currentIdempotencyKey() || Store.newIdempotencyKey()
    };
  }

  function submitOrder(event) {
    event.preventDefault();
    if (state.submitting) return;

    if (!navigator.onLine) {
      UI.showError('checkout-error', 'لا يوجد اتصال. لا يمكن إرسال الطلب الآن — أعد المحاولة عند عودة الشبكة.');
      return;
    }

    UI.clearError('checkout-error');
    var payload = collectPayload();

    if (!payload.customer_name) {
      $('field-name').classList.add('invalid');
      UI.showError('checkout-error', 'اكتب اسمك أولًا من فضلك.');
      return;
    }

    state.submitting = true;
    var btn = $('submit-order');
    UI.setLoading(btn, true, 'جارٍ إرسال الطلب…');

    Api.createOrder(payload, payload.idempotency_key)
      .then(function (order) {
        // نؤكد النجاح فقط بعد الحفظ الفعلي على الخادم
        var number = order.number;
        Store.clearIdempotencyKey();
        Store.clearCart();
        Store.setLastOrder(order);
        UI.renderCart();
        UI.renderSuccess(order);
        Router.go('success');
        UI.toast('تم حفظ الطلب رقم #' + number + ' بنجاح.', 'success');
      })
      .catch(function (error) {
        UI.showError('checkout-error', error.message, error.fields);
        UI.toast('تعذّر إرسال الطلب. لم يُخصم أي شيء.', 'error');
        if (error.code === 'network_error' || error.code === 'timeout') {
          UI.setOffline(true);
        }
      })
      .finally(function () {
        state.submitting = false;
        UI.setLoading(btn, false);
        // قد يكون انقطاع الشبكة سبب الخطأ: نُبقي حالة الانصال كما هي
        if (!navigator.onLine) UI.setOffline(true);
      });
  }

  /* ============================ متابعة الطلب ============================ */

  function loadTracking() {
    var token = state.trackingToken;
    if (!token) { Router.go(''); return; }

    if (!navigator.onLine) {
      var last = Store.getLastOrder();
      if (last && last.tracking_token === token) {
        UI.renderTracking(last);
        UI.toast('لا يوجد اتصال — هذه آخر حالة محفوظة على جهازك.', 'warn');
      } else {
        UI.showError('track-error', 'لا يوجد اتصال لعرض حالة الطلب.');
      }
      return;
    }

    var btn = $('track-refresh');
    UI.setLoading(btn, true, 'جارٍ التحديث…');

    Api.orderStatus(token)
      .then(function (order) {
        UI.renderTracking(order);
        UI.clearError('track-error');
        UI.toast('الحالة الآن: ' + order.status_label, 'success');
      })
      .catch(function (error) {
        UI.showError('track-error', error.message);
        UI.toast(error.message, 'error');
      })
      .finally(function () { UI.setLoading(btn, false); });
  }

  function restoreLastSuccess() {
    var last = Store.getLastOrder();
    if (last) {
      UI.renderSuccess(last);
    } else {
      Router.go('', true);
    }
  }

  /* =============================== الأحداث =============================== */

  function bindEvents() {
    // التنقل
    $('cart-button').addEventListener('click', function () { Router.go('cart'); });
    $('cart-fab').addEventListener('click', function () { Router.go('cart'); });
    $('to-checkout').addEventListener('click', function () { Router.go('checkout'); });
    $('checkout-back').addEventListener('click', function () { Router.go('cart'); });
    $('cart-empty-action').addEventListener('click', function () { Router.go(''); });
    $('brand-link').addEventListener('click', function (event) {
      event.preventDefault();
      Router.go('');
    });
    $('success-new-order').addEventListener('click', function () {
      Store.clearLastOrder();
      UI.toast('يمكنك طلب جديد في أي وقت.', 'info');
      Router.go('');
    });
    $('track-refresh').addEventListener('click', loadTracking);

    $('loc-clear').addEventListener('click', function () {
      Store.clearLocation();
      UI.toast('تم مسح الموقع. اختر موقعًا جديدًا عند الطلب.', 'info');
      renderMenu();
    });

    $('welcome-action').addEventListener('click', function () {
      try { localStorage.setItem('lc_welcome_seen', '1'); } catch (e) { /* تجاهل */ }
      Router.go('');
    });

    // التصنيفات
    $('category-tabs').addEventListener('click', function (event) {
      var tab = event.target.closest('.tab');
      if (!tab) return;
      state.categoryId = tab.getAttribute('data-cat') || '';
      renderMenu();
    });

    // البحث
    var searchTimer;
    var searchInput = $('search-input');

    function runSearch(value) {
      state.search = value;
      UI.setHidden($('search-clear'), value === '');
      if (value === '') {
        if (Store.snapshot) renderMenu();
        return;
      }
      Api.snapshot(value).then(function (data) {
        Store.setSnapshot(data);
        renderMenu();
      }).catch(function () {
        UI.toast('تعذّر تنفيذ البحث. حاول مجددًا.', 'error');
      });
    }

    searchInput.addEventListener('input', function (event) {
      clearTimeout(searchTimer);
      var value = event.target.value.trim();
      searchTimer = setTimeout(function () {
        if (value === state.search) return;
        runSearch(value);
      }, 320);
    });

    $('search-clear').addEventListener('click', function () {
      searchInput.value = '';
      runSearch('');
      searchInput.focus();
    });

    // بطاقات المنتجات
    $('product-grid').addEventListener('click', function (event) {
      var addBtn = event.target.closest('[data-add]');
      if (addBtn) {
        event.stopPropagation();
        addToCart(parseInt(addBtn.getAttribute('data-add'), 10), 1);
        return;
      }
      var card = event.target.closest('[data-detail]');
      if (card) openProductDetails(parseInt(card.getAttribute('data-detail'), 10));
    });

    // بطاقات المنتجات: فتحها بالكيبورد أيضًا
    $('product-grid').addEventListener('keydown', function (event) {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      var card = event.target.closest('[data-detail]');
      if (!card) return;
      event.preventDefault();
      openProductDetails(parseInt(card.getAttribute('data-detail'), 10));
    });

    // ورقة المنتج
    $('sheet-close').addEventListener('click', closeSheet);
    $('sheet-backdrop').addEventListener('click', closeSheet);
    $('sheet-footer').addEventListener('click', function (event) {
      var step = event.target.closest('[data-step]');
      if (step) {
        var value = parseInt($('sheet-qty').textContent, 10) + parseInt(step.getAttribute('data-step'), 10);
        $('sheet-qty').textContent = Math.max(1, Math.min(99, value));
        return;
      }
      if (event.target.id === 'sheet-add') {
        var id = parseInt($('sheet-footer').getAttribute('data-product-id') || '0', 10);
        var qty = parseInt($('sheet-qty').textContent, 10);
        closeSheet();
        if (id) addToCart(id, qty);
      }
    });

    // السلة
    $('cart-list').addEventListener('click', function (event) {
      var step = event.target.closest('[data-cart-step]');
      if (step) {
        var id = parseInt(step.getAttribute('data-id'), 10);
        var next = Store.quantityOf(id) + parseInt(step.getAttribute('data-cart-step'), 10);
        Store.setQuantity(id, next);
        UI.renderCart();
        renderMenu();
        return;
      }

      var remove = event.target.closest('[data-cart-remove]');
      if (remove) {
        var rid = parseInt(remove.getAttribute('data-cart-remove'), 10);
        var item = Store.findItem(rid);
        var name = item ? item.name : 'الصنف';
        Store.removeItem(rid);
        UI.renderCart();
        renderMenu();
        UI.toast('أُزيل «' + name + '» من السلة.', 'info');
      }
    });

    $('clear-cart').addEventListener('click', function () {
      if (!Store.cart.length) return;
      var snapshot = Store.cart.slice();
      Store.clearCart();
      UI.renderCart();
      renderMenu();
      UI.toast('تم إفراغ السلة.', 'info', {
        action: {
          label: 'تراجع',
          onClick: function () {
            Store.cart = snapshot;
            Store.saveCart();
            UI.renderCart();
            renderMenu();
            UI.toast('أُعيدت الأصناف إلى السلة.', 'success');
          }
        }
      });
    });

    // نوع الطلب والموقع والحقول الديناميكية
    $('order-type-choices').addEventListener('click', function (event) {
      var choice = event.target.closest('[data-type]');
      if (!choice) return;
      state.orderType = choice.getAttribute('data-type');
      UI.renderOrderTypes((Store.config && Store.config.order_types) || [], state.orderType);
      applyOrderTypeFields();
    });

    $('dynamic-fields').addEventListener('click', function (event) {
      var boolBtn = event.target.closest('[data-bool]');
      if (!boolBtn) return;
      var pressed = boolBtn.getAttribute('aria-pressed') === 'true';
      boolBtn.setAttribute('aria-pressed', pressed ? 'false' : 'true');
    });

    $('location-select').addEventListener('change', function (event) {
      var all = (Store.snapshot && Store.snapshot.locations) || [];
      var match = all.filter(function (l) { return l.code === event.target.value; })[0];
      if (match) {
        Store.setLocation(match);
        UI.renderLocationChip(match, Store.config || {});
        UI.toast('تم تحديد الموقع: ' + match.name, 'success');
      }
    });

    $('checkout-form').addEventListener('submit', submitOrder);
    $('customer-name').addEventListener('input', function () {
      $('field-name').classList.remove('invalid');
    });

    // الاتصال: نعتمد على نتيجة الطلبات الحقيقية لا على navigator.onLine وحده
    window.addEventListener('online', function () {
      UI.setOffline(false);
      loadCatalog().then(function () {
        UI.setOffline(false);
        UI.toast('عاد الاتصال — تم تحديث القائمة.', 'success');
      });
    });
    window.addEventListener('offline', function () {
      UI.setOffline(true);
      UI.toast('انقطع الاتصال. الطلب متوقف حتى يعود.', 'warn');
    });

    // رجوع/تقدم في سجل المتصفح
    global.addEventListener('popstate', function () {
      closeSheet();
      render();
    });
    global.addEventListener('hashchange', function () {
      if (Router.mode === 'hash') { closeSheet(); render(); }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !$('product-sheet').hidden) closeSheet();
    });
  }

  /* ============================== الإقلاع ============================== */

  function boot() {
    Store.loadCart();
    Store.loadLocation();
    state.location = Store.location;

    $('brand-link').setAttribute('href', HOME);
    bindEvents();

    // احترام الرابط المباشر عند الفتح (رابط QR أو صفحة مشاركة)
    render();

    // هيكل تحميل مؤقت حتى وصول الكتالوج
    if (state.view === 'menu' && !Store.snapshot) UI.renderSkeleton(6);

    loadCatalog().then(function () {
      if (applyLocationFromUrl()) {
        state.location = Store.location;
        UI.toast('تم تحديد ' + (Store.config.location_singular || 'الموقع') + ': ' +
          Store.location.name, 'success');
      }

      // رمز متابعة طلب قادم من QR قديم (?t=) أو من رابط طلب جديد
      var token = new URLSearchParams(location.search).get('t');
      if (token) {
        cleanQuery(['t']);
        Router.go('order/' + encodeURIComponent(token), true);
        return;
      }

      // صفحة الترحيب: تظهر مرة واحدة فقط وعند الدخول من الجذر
      var welcome = $('view-welcome');
      var dismissed = false;
      try { dismissed = localStorage.getItem('lc_welcome_seen') === '1'; } catch (e) { dismissed = false; }
      var isRootRoute = !Router.route();

      if (isRootRoute && !dismissed && Store.settings.show_welcome && Store.settings.welcome_message) {
        $('welcome-title').textContent = 'أهلًا بك في ' + (Store.settings.app_name || 'القائمة');
        $('welcome-text').textContent = Store.settings.welcome_message;
        welcome.hidden = false;
        VIEWS.forEach(function (v) { $('view-' + v).hidden = true; });
        state.view = 'welcome';
      }
    });

    if ('serviceWorker' in navigator && CONFIG.sw) {
      // التسجيل من جذر الموقع ليشمل نطاق /api/ أيضًا (كي يعمل التخزين دون اتصال)
      navigator.serviceWorker.register(CONFIG.sw, { scope: BASE }).catch(function () { /* تجاهل */ });
    }

    // كشف دعم الروابط النظيفة بعد أول رسم (لا يعطّل الإقلاع)
    detectRouting().then(function (mode) {
      if (mode === 'hash' && Router.mode !== 'hash') {
        Router.mode = 'hash';
        // إن كنا على مسار نظيف (مثل فتح رابط مخزّن دون اتصال) ننقله إلى الهاش
        var path = location.pathname;
        if (BASE !== '/' && path.indexOf(BASE) === 0) path = path.slice(BASE.length);
        if (strip(path)) history.replaceState(null, '', BASE + '#/' + strip(path));
      }
      render();
    });
  }

  global.App.Router = Router;
  document.addEventListener('DOMContentLoaded', boot);
})(window);