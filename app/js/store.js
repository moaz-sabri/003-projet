/* ==========================================================================
   store.js — الحالة المحلية: الكتالوج، السلة، الموقع، وإعدادات الجلسة.
   تُحفظ السلة في localStorage وتبقى بعد إغلاق التطبيق.
   ========================================================================== */
(function (global) {
  'use strict';

  var CART_KEY = 'lc_cart_v1';
  var LOCATION_KEY = 'lc_location_v1';
  var IDEMPOTENCY_KEY = 'lc_idem_v1';
  var CACHE_KEY = 'lc_catalog_cache_v1';
  var LAST_ORDER_KEY = 'lc_last_order_v1';
  var LAYOUT_KEY = 'lc_layout_v1';

  var Store = {
    snapshot: null,
    cart: [],
    location: null,
    config: {},
    settings: {},
    company: {},
    loadedFromCache: false,
    layout: 'grid',

    /* ---------------------------- الوضع ---------------------------- */

    /** 'orders' = فيه سلة وطلبات | 'catalog' = عرض فقط */
    mode: function () {
      return (this.config && this.config.mode) === 'catalog' ? 'catalog' : 'orders';
    },

    /** هل يعرض المشروع نظام طلبات؟ */
    hasOrdering: function () {
      return (this.config && this.config.has_ordering === false) ? false : true;
    },

    /**
     * هل يقبل الطلب أكثر من صنف واحد؟
     * المطعم/المقهى والمتجر فقط؛ بقية الأنشطة: صنف واحد بلا أصناف أخرى.
     * القيمة الافتراضية true حتى لا تتعطّل النسخ المحفوظة القديمة.
     */
    allowsMultiItems: function () {
      return !(this.config && this.config.multi_item_orders === false);
    },

    /** قدرات النمط الفعّالة (ordering · places · details · media …). */
    pattern: function () {
      return (this.config && this.config.pattern) || {};
    },

    orderingMode: function () {
      return this.pattern().ordering || (this.hasOrdering() ? 'cart' : 'none');
    },

    hasReservations: function () {
      return this.orderingMode() === 'reservation';
    },

    placesMode: function () {
      return this.pattern().places || 'none';
    },

    detailsMode: function () {
      return this.pattern().details || 'none';
    },

    mediaMode: function () {
      return this.pattern().media || 'single';
    },

    /* ------------------------- تفضيلات العرض ------------------------- */

    /** تفضيل الزبون لعرض المنتجات: 'grid' مربعات | 'list' صفوف منفردة. */
    loadLayout: function () {
      try {
        this.layout = localStorage.getItem(LAYOUT_KEY) === 'list' ? 'list' : 'grid';
      } catch (e) { this.layout = 'grid'; }
      return this.layout;
    },

    setLayout: function (layout) {
      this.layout = layout === 'list' ? 'list' : 'grid';
      try { localStorage.setItem(LAYOUT_KEY, this.layout); } catch (e) { /* تجاهل */ }
      return this.layout;
    },

    /* -------------------- تسميات حسب نوع النشاط -------------------- */

    productSingular: function () { return (this.config && this.config.product_singular) || 'منتج'; },
    productPlural: function () { return (this.config && this.config.product_plural) || 'المنتجات'; },
    catalogTitle: function () { return (this.config && this.config.catalog_title) || 'المنتجات'; },
    catalogCta: function () { return (this.config && this.config.catalog_cta) || 'تصفّح المنتجات'; },
    orderCta: function () { return (this.config && this.config.order_cta) || 'اطلب الآن'; },

    /* ----------------------------- السلة ----------------------------- */

    loadCart: function () {
      try {
        var raw = localStorage.getItem(CART_KEY);
        this.cart = raw ? JSON.parse(raw) : [];
      } catch (e) { this.cart = []; }
      return this.cart;
    },

    saveCart: function () {
      try { localStorage.setItem(CART_KEY, JSON.stringify(this.cart)); } catch (e) { /* ذاكرة ممتلئة */ }
    },

    addItem: function (product, quantity) {
      quantity = Math.max(1, parseInt(quantity || 1, 10));
      var existing = this.findItem(product.id);
      if (existing) {
        existing.quantity = Math.min(99, existing.quantity + quantity);
      } else {
        this.cart.push({
          product_id: product.id,
          name: product.name,
          price_minor: product.price_minor,
          image: product.image,
          available: product.available !== false,
          quantity: Math.min(99, quantity)
        });
      }
      this.saveCart();
    },

    setQuantity: function (productId, quantity) {
      var item = this.findItem(productId);
      if (!item) return;
      quantity = parseInt(quantity, 10);
      if (quantity <= 0) {
        this.removeItem(productId);
        return;
      }
      item.quantity = Math.min(99, quantity);
      this.saveCart();
    },

    removeItem: function (productId) {
      this.cart = this.cart.filter(function (item) { return item.product_id !== productId; });
      this.saveCart();
    },

    clearCart: function () {
      this.cart = [];
      this.saveCart();
    },

    findItem: function (productId) {
      for (var i = 0; i < this.cart.length; i++) {
        if (this.cart[i].product_id === productId) return this.cart[i];
      }
      return null;
    },

    quantityOf: function (productId) {
      var item = this.findItem(productId);
      return item ? item.quantity : 0;
    },

    count: function () {
      return this.cart.reduce(function (sum, item) { return sum + item.quantity; }, 0);
    },

    /** الإجمالي المحسوب محليًا — للإرشاد فقط. الخادم هو المرجع النهائي. */
    total: function () {
      return this.cart.reduce(function (sum, item) {
        return sum + (Number(item.price_minor) || 0) * item.quantity;
      }, 0);
    },

    /* ---------------------------- الموقع ---------------------------- */

    loadLocation: function () {
      try {
        var raw = localStorage.getItem(LOCATION_KEY);
        this.location = raw ? JSON.parse(raw) : null;
      } catch (e) { this.location = null; }
      return this.location;
    },

    setLocation: function (location) {
      this.location = location;
      try {
        if (location) localStorage.setItem(LOCATION_KEY, JSON.stringify(location));
        else localStorage.removeItem(LOCATION_KEY);
      } catch (e) { /* تجاهل */ }
    },

    clearLocation: function () {
      this.setLocation(null);
    },

    /* -------------------------- الكتالوج -------------------------- */

    setSnapshot: function (data) {
      this.snapshot = data;
      this.settings = data.settings || {};
      this.config = data.config || {};
      this.company = data.company || {};
      try {
        localStorage.setItem(CACHE_KEY, JSON.stringify({ at: Date.now(), data: data }));
      } catch (e) { /* تجاهل */ }
      this.loadedFromCache = false;
    },

    getCachedSnapshot: function () {
      try {
        var raw = localStorage.getItem(CACHE_KEY);
        if (!raw) return null;
        var parsed = JSON.parse(raw);
        this.settings = parsed.data.settings || {};
        this.config = parsed.data.config || {};
        this.company = parsed.data.company || {};
        this.loadedFromCache = true;
        return parsed.data;
      } catch (e) { return null; }
    },

    cacheAge: function () {
      try {
        var raw = localStorage.getItem(CACHE_KEY);
        if (!raw) return null;
        return Date.now() - JSON.parse(raw).at;
      } catch (e) { return null; }
    },

    /* ------------------------ idempotency ------------------------ */

    /** مفتاح ثابت لكل محاولة إرسال — يمنع تكرار الطلب عند إعادة المحاولة. */
    newIdempotencyKey: function () {
      var key;
      if (window.crypto && crypto.randomUUID) {
        key = crypto.randomUUID();
      } else {
        key = 'k-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
      }
      try { sessionStorage.setItem(IDEMPOTENCY_KEY, key); } catch (e) { /* تجاهل */ }
      return key;
    },

    currentIdempotencyKey: function () {
      try { return sessionStorage.getItem(IDEMPOTENCY_KEY) || ''; } catch (e) { return ''; }
    },

    clearIdempotencyKey: function () {
      try { sessionStorage.removeItem(IDEMPOTENCY_KEY); } catch (e) { /* تجاهل */ }
    },

    /* ------------------------- آخر طلب ------------------------- */

    setLastOrder: function (order) {
      try { localStorage.setItem(LAST_ORDER_KEY, JSON.stringify(order)); } catch (e) { /* تجاهل */ }
    },

    getLastOrder: function () {
      try {
        var raw = localStorage.getItem(LAST_ORDER_KEY);
        return raw ? JSON.parse(raw) : null;
      } catch (e) { return null; }
    },

    clearLastOrder: function () {
      try { localStorage.removeItem(LAST_ORDER_KEY); } catch (e) { /* تجاهل */ }
    }
  };

  global.App = global.App || {};
  global.App.Store = Store;
})(window);