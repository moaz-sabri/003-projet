/* ==========================================================================
   theme.js — الوضع الفاتح / الغامق.
   يُحمَّل في <head> قبل جسم الصفحة لمنع وميض اللون الخاطئ، ولا يمسّ أي
   منطق آخر في التطبيق. المصدر بالترتيب: اختيار الزبون المحفوظ، ثم النظام.
   ========================================================================== */
(function (global) {
  'use strict';

  var KEY = 'lc_theme_v1';
  var DARK = 'dark';
  var LIGHT = 'light';
  var COLOR = { dark: '#131417', light: '#faf9f7' };

  var root = document.documentElement;

  /** اختيار الزبون المحفوظ، أو '' إن لم يختر بعد. */
  function saved() {
    try {
      var value = localStorage.getItem(KEY);
      return (value === DARK || value === LIGHT) ? value : '';
    } catch (e) {
      return '';
    }
  }

  /** تفضيل النظام الحالي. */
  function system() {
    return (global.matchMedia && global.matchMedia('(prefers-color-scheme: dark)').matches) ? DARK : LIGHT;
  }

  /** تفضيل فعّال: اختيار الزبون أولًا، وإلا النظام. */
  function resolved() {
    return saved() || system();
  }

  /** يطبّق الوضع على الصفحة: السمة، لون شريط الحالة، وحالة المفتاح. */
  function apply(theme) {
    root.setAttribute('data-theme', theme);

    // لون شريط الحالة: نحدّث كل الوسوم حتى يبقى صحيحًا مع اختيار الزبون
    var metas = document.querySelectorAll('meta[name="theme-color"]');
    for (var i = 0; i < metas.length; i++) {
      metas[i].setAttribute('content', COLOR[theme]);
    }

    var toggle = document.getElementById('theme-toggle');
    if (toggle) {
      var next = theme === DARK ? 'الوضع الفاتح' : 'الوضع الغامق';
      toggle.setAttribute('aria-pressed', theme === DARK ? 'true' : 'false');
      toggle.setAttribute('aria-label', 'بدّل إلى ' + next);
      toggle.setAttribute('title', 'بدّل إلى ' + next);
    }
  }

  apply(resolved());

  // يتبع النظام ما دام الزبون لم يختر بنفسه
  if (global.matchMedia) {
    var query = global.matchMedia('(prefers-color-scheme: dark)');
    var onSystemChange = function () {
      if (!saved()) apply(system());
    };
    if (query.addEventListener) query.addEventListener('change', onSystemChange);
    else if (query.addListener) query.addListener(onSystemChange);
  }

  global.App = global.App || {};
  global.App.Theme = {
    /** الوضع الظاهر الآن: 'light' أو 'dark'. */
    current: function () {
      return root.getAttribute('data-theme') === DARK ? DARK : LIGHT;
    },

    /** يثبّت وضعًا اختاره الزبون ويحفظه. */
    set: function (theme) {
      var value = theme === DARK ? DARK : LIGHT;
      try { localStorage.setItem(KEY, value); } catch (e) { /* تجاهل */ }
      apply(value);
      return value;
    },

    /** يبدّل بين الوضعين ويعيد الوضع الجديد. */
    toggle: function () {
      return global.App.Theme.set(global.App.Theme.current() === DARK ? LIGHT : DARK);
    },

    /** يمحو اختيار الزبون ويعود إلى تفضيل النظام. */
    reset: function () {
      try { localStorage.removeItem(KEY); } catch (e) { /* تجاهل */ }
      apply(system());
    },

    /** يعيد مزامنة المفتاح في الصفحة (يُستدعى بعد بناء الواجهة). */
    sync: function () {
      apply(resolved());
    }
  };
})(window);
