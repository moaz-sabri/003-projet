/* ==========================================================================
   app.js — الإقلاع، التوجيه، والأحداث.
   ========================================================================== */
(function (global) {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };
  var Api = global.App.Api, Store = global.App.Store, UI = global.App.UI;

  var state = {
    view: 'menu',
    categoryId: '',
    search: '',
    orderType: '',
    location: null,
    submitting: false,
    trackingToken: ''
  };

  /* ============================ التوجيه ============================ */

  function parseHash() {
    var hash = (location.hash || '#/').replace(/^#\/?/, '');
    var parts = hash.split('/');
    return { name: parts[0] || '', arg: parts[1] || '' };
  }

  function navigate(path) {
    if (location.hash === '#/' + path) render();
    else location.hash = '#/' + path;
  }

  var views = ['menu', 'cart', 'checkout', 'success', 'track'];

  function render() {
    var route = parseHash();

    if (route.name === 'cart') return show('cart');
    if (route.name === 'checkout') return show('checkout');
    if (route.name === 'success') return show('success');
    if (route.name === 'order' && route.arg) {
      state.trackingToken = route.arg;
      return show('track');
    }
    if (route.name === 'product' && route.arg) {
      show('menu');
      openProductDetails(parseInt(route.arg, 10));
      return;
    }
    show('menu');
  }

  function show(name) {
    state.view = name;
    views.forEach(function (view) {
      var el = $('view-' + view);
      if (el) el.hidden = (view !== name);
    });
    $('view-welcome').hidden = true;
    $('header-nav').hidden = (name !== 'menu');

    window.scrollTo({ top: 0, behavior: 'instant' in document.documentElement.style ? 'instant' : 'auto' });

    if (name === 'menu') renderMenu();
    if (name === 'cart') UI.renderCart();
    if (name === 'checkout') prepareCheckout();
    if (name === 'success') restoreLastSuccess();
    if (name === 'track') loadTracking();
  }

  /* ============================ التحميل ============================ */

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
            notice.textContent = 'تعرض الآن نسخة محفوظة من القائمة' +
              (age > 3600000 ? ' عمرها ' + Math.round(age / 3600000) + ' ساعة.' : ' قبل دقائق.');
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
        UI.setOffline(true);
        UI.toast(error.message);
      });
  }

  function applySnapshot() {
    var settings = Store.settings || {};
    UI.renderBrand(settings, Store.config || {});
    UI.renderSearch(Store.settings.show_search !== false);
    renderMenu();
  }

  function renderMenu() {
    if (!Store.snapshot) return;
    var features = (Store.config && Store.config.features) || {};

    UI.renderTabs(Store.snapshot.categories || [], state.categoryId, features);
    UI.renderLocationChip(Store.location, Store.config || {});

    var products = (Store.snapshot.products || []).filter(function (product) {
      var matchCategory = !state.categoryId || String(product.category_id) === String(state.categoryId);
      return matchCategory;
    });

    UI.renderProducts(products, { search: state.search });
    UI.renderCart();
  }

  /* ============================ الموقع ============================ */

  function applyLocationFromUrl() {
    var params = new URLSearchParams(location.search);
    var code = params.get('loc');
    if (!code) return false;

    var locations = (Store.snapshot && Store.snapshot.locations) || [];
    var match = locations.filter(function (l) { return l.code === code; })[0];
    if (match && match.active) {
      Store.setLocation(match);
      state.location = match;
      return true;
    }
    return false;
  }

  /* ============================ المنتج ============================ */

  function openProductDetails(id) {
    var local = findProduct(id);
    if (!local) return;

    UI.renderProductSheet(local, Store.config || {});
    var footer = $('sheet-footer');
    footer.hidden = local.available === false;
    footer.setAttribute('data-product-id', String(id));
    $('product-sheet').hidden = false;
    $('sheet-backdrop').hidden = false;
    document.body.style.overflow = 'hidden';

    // إن كان للمنتج تفاصيل أعرضها من الخادم للتأكد
    if (local.has_details) {
      Api.product(id).then(function (detail) {
        UI.renderProductSheet(detail, Store.config || {});
      }).catch(function () { /* نعرض النسخة المحلية */ });
    }
  }

  function closeSheet() {
    $('product-sheet').hidden = true;
    $('sheet-backdrop').hidden = true;
    document.body.style.overflow = '';
  }

  function findProduct(id) {
    var products = (Store.snapshot && Store.snapshot.products) || [];
    for (var i = 0; i < products.length; i++) {
      if (products[i].id === id) return products[i];
    }
    return null;
  }

  /* ============================ السلة ============================ */

  function addToCart(id, quantity) {
    var product = findProduct(id);
    if (!product) return;
    if (product.available === false) {
      UI.toast('هذا المنتج لم يعد متاحًا.');
      return;
    }
    Store.addItem(product, quantity || 1);
    UI.renderCart();
    renderMenu();
    UI.toast('أُضيف «' + product.name + '» إلى السلة');
  }

  /* ============================ الدفع ============================ */

  function prepareCheckout() {
    if (!Store.cart.length) {
      navigate('cart');
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

    var nameInput = $('customer-name');
    if (nameInput && !nameInput.value && Store.getLastOrder()) {
      // لا نملأ الاسم تلقائيًا إلا إذا اختاره الزبون سابقًا في هذه الجلسة
    }
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

    if (type.requires_location && Store.location) {
      $('field-location').hidden = true;
    }

    var nameField = $('field-name');
    var label = (Store.config && Store.config.customer_name_label) || 'اسمك';
    $('label-customer-name').textContent = label;
    $('customer-name').placeholder = (Store.config && Store.config.customer_name_placeholder) || '';
    if (nameField) nameField.hidden = false;
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
      $('customer-name').focus();
      return;
    }

    state.submitting = true;
    var btn = $('submit-order');
    btn.disabled = true;
    btn.textContent = 'جارٍ الإرسال…';

    Api.createOrder(payload, payload.idempotency_key)
      .then(function (order) {
        // نؤكد النجاح فقط بعد الحفظ الفعلي على الخادم
        Store.clearIdempotencyKey();
        Store.clearCart();
        Store.setLastOrder(order);
        UI.renderCart();
        UI.renderSuccess(order);
        navigate('success');
      })
      .catch(function (error) {
        UI.showError('checkout-error', error.message, error.fields);
        if (error.code === 'network_error' || error.code === 'timeout') {
          UI.setOffline(true);
        }
      })
      .finally(function () {
        state.submitting = false;
        btn.disabled = false;
        btn.textContent = 'تأكيد الطلب';
      });
  }

  /* ============================ متابعة الطلب ============================ */

  function loadTracking() {
    var token = state.trackingToken;
    if (!token) { navigate('menu'); return; }

    if (!navigator.onLine) {
      var last = Store.getLastOrder();
      if (last && last.tracking_token === token) {
        UI.renderTracking(last);
      } else {
        UI.showError('track-error', 'لا يوجد اتصال لعرض حالة الطلب.');
      }
      return;
    }

    Api.orderStatus(token)
      .then(function (order) { UI.renderTracking(order); })
      .catch(function (error) { UI.showError('track-error', error.message); });
  }

  function restoreLastSuccess() {
    var last = Store.getLastOrder();
    if (last) UI.renderSuccess(last);
  }

  /* ============================ الأحداث ============================ */

  function bindEvents() {
    // التنقل
    $('cart-button').addEventListener('click', function () { navigate('cart'); });
    $('to-checkout').addEventListener('click', function () { navigate('checkout'); });
    $('checkout-back').addEventListener('click', function () { navigate('cart'); });
    $('success-new-order').addEventListener('click', function () {
      Store.clearLastOrder();
      navigate('menu');
    });
    $('track-refresh').addEventListener('click', loadTracking);
    $('loc-clear').addEventListener('click', function () {
      Store.clearLocation();
      UI.toast('تم مسح الموقع. اختر موقعًا جديدًا عند الطلب.');
      renderMenu();
    });
    $('welcome-action').addEventListener('click', function () {
      try { localStorage.setItem('lc_welcome_seen', '1'); } catch (e) { /* تجاهل */ }
      navigate('menu');
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
    $('search-input').addEventListener('input', function (event) {
      clearTimeout(searchTimer);
      var value = event.target.value;
      searchTimer = setTimeout(function () {
        if (value.length >= 2 && !Store.snapshot) return;
        if (value === state.search) return;
        state.search = value;
        if (value === '') {
          if (Store.snapshot) renderMenu();
          return;
        }
        Api.snapshot(value).then(function (data) {
          Store.setSnapshot(data);
          renderMenu();
        }).catch(function () { /* نُبقي النتائج الحالية */ });
      }, 350);
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

    // ورقة المنتج
    $('sheet-close').addEventListener('click', closeSheet);
    $('sheet-backdrop').addEventListener('click', closeSheet);
    $('sheet-footer').addEventListener('click', function (event) {
      var step = event.target.closest('[data-step]');
      if (step) {
        var value = parseInt($('sheet-qty').textContent, 10) + parseInt(step.getAttribute('data-step'), 10);
        $('sheet-qty').textContent = Math.max(1, Math.min(99, value));
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
        Store.setQuantity(id, Store.quantityOf(id) + parseInt(step.getAttribute('data-cart-step'), 10));
        UI.renderCart();
        return;
      }
      var remove = event.target.closest('[data-cart-remove]');
      if (remove) {
        Store.removeItem(parseInt(remove.getAttribute('data-cart-remove'), 10));
        UI.renderCart();
      }
    });

    $('clear-cart').addEventListener('click', function () {
      Store.clearCart();
      UI.renderCart();
      UI.toast('تم إفراغ السلة.');
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
      }
    });

    $('checkout-form').addEventListener('submit', submitOrder);
    $('customer-name').addEventListener('input', function () {
      $('field-name').classList.remove('invalid');
    });

    // الاتصال: نعتمد على نتيجة الطلبات الحقيقية لا على navigator.onLine وحده
    // (بعض الأجهزة تشير إلى "متصل" بينما الخادم غير متاح، وبعضها العكس)
    window.addEventListener('online', function () {
      loadCatalog().then(function () {
        UI.setOffline(false);
        UI.toast('عاد الاتصال — تم تحديث القائمة.');
      });
    });
    window.addEventListener('offline', function () { UI.setOffline(true); });

    window.addEventListener('hashchange', render);
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !$('product-sheet').hidden) closeSheet();
    });
  }

  /* ============================ الإقلاع ============================ */

  function boot() {
    Store.loadCart();
    Store.loadLocation();
    state.location = Store.location;

    bindEvents();

    // احترام الرابط المباشر عند الفتح (مثل رابط QR الذي يفتح صفحة متابعة)
    render();

    loadCatalog().then(function () {
      var applied = applyLocationFromUrl();
      if (applied) {
        state.location = Store.location;
        UI.toast('تم تحديد ' + (Store.config.location_singular || 'الموقع') + ': ' + Store.location.name);
      }

      // رمز متابعة طلب قادم من QR
      var token = new URLSearchParams(location.search).get('t');
      if (token) {
        state.trackingToken = token;
        navigate('order/' + token);
        return;
      }

      // صفحة الترحيب: تظهر مرة واحدة فقط وعند الدخول من الجذر
      var welcome = $('view-welcome');
      var dismissed = false;
      try { dismissed = localStorage.getItem('lc_welcome_seen') === '1'; } catch (e) { dismissed = false; }
      var isRootRoute = !location.hash || location.hash === '#/' || location.hash === '#/menu';

      if (isRootRoute && !dismissed && Store.settings.show_welcome && Store.settings.welcome_message) {
        $('welcome-title').textContent = 'أهلًا بك في ' + (Store.settings.app_name || 'القائمة');
        $('welcome-text').textContent = Store.settings.welcome_message;
        welcome.hidden = false;
        ['menu', 'cart', 'checkout', 'success', 'track'].forEach(function (v) {
          $('view-' + v).hidden = true;
        });
      }
    });

    if ('serviceWorker' in navigator) {
      // التسجيل من جذر الموقع ليشمل نطاق /api/ أيضًا (كي يعمل التخزين دون اتصال)
      navigator.serviceWorker.register('../sw.js', { scope: '../' }).catch(function () { /* تجاهل */ });
    }
  }

  document.addEventListener('DOMContentLoaded', boot);
})(window);