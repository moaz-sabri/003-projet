/* ==========================================================================
   ui.js — دوال العرض. تبقى بلا حالة: تستقبل البيانات وترسم الشاشة.
   ========================================================================== */
(function (global) {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };

  /* أيقونات الرسائل — SVG مضمّن لتجنّب أي طلب شبكة إضافي */
  var TOAST_ICONS = {
    success: '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" d="m5 12.5 4.5 4.5L19 7.5"/></svg>',
    error:   '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M12 7.5v5m0 3h.01M12 3.5 2.6 19a1.4 1.4 0 0 0 1.2 2.1h16.4a1.4 1.4 0 0 0 1.2-2.1L12 3.5Z"/></svg>',
    warn:    '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 8v4.5M12 15.5h.01"/></svg>',
    info:    '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 11v5.5M12 7.8h.01"/></svg>'
  };

  var TOAST_DURATION = { success: 2600, info: 3000, warn: 3800, error: 4600 };

  var UI = {
    formatPrice: function (minor) {
      var cfg = (global.App && global.App.Store.config) || {};
      var decimals = 0;
      var text = Number(minor || 0).toFixed(decimals);
      var parts = text.split('.');
      parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      var joined = parts.join('.');
      if (cfg.currency_position === 'before') return (cfg.currency || '') + ' ' + joined;
      return joined + ' ' + (cfg.currency || '');
    },

    escape: function (value) {
      return String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    },

    /* ====================================================================
       الرسائل — بعد كل حدث يظهر توضيح ما الذي حدث بالضبط:
       نجاح (أخضر) · خطأ (أحمر) · تنبيه (كهرماني) · معلومة (محايد).
       ويمكن إرفاق إجراء مع الرسالة (مثل «تراجع» بعد إفراغ السلة).
       الحاوية نفسها معلنة aria-live فيقرأها قارئ الشاشة تلقائيًا.
       ==================================================================== */
    toast: function (message, type, options) {
      var wrap = $('toast-wrap');
      if (!wrap) return;

      // نداء مبسّط: toast(رسالة) أو toast(رسالة, 'success', {action: …})
      if (type && typeof type === 'object') {
        options = type;
        type = options.type || 'info';
      }
      options = options || {};
      type = TOAST_ICONS[type] ? type : 'info';

      // ثلاث رسائل كحد أقصى: الأقدم يختفي فورًا عند ازدحام الشاشة
      var live = wrap.querySelectorAll('.toast');
      while (live.length >= 3) {
        UI.dismiss(live[0]);
        live = wrap.querySelectorAll('.toast');
      }

      var duration = options.duration || (options.action ? 6500 : TOAST_DURATION[type]);

      var el = document.createElement('div');
      el.className = 'toast ' + type;
      el.setAttribute('role', type === 'error' ? 'alert' : 'status');
      el.innerHTML =
        '<span class="toast-icon">' + TOAST_ICONS[type] + '</span>' +
        '<span class="toast-text"></span>' +
        '<span class="toast-bar" style="animation-duration:' + duration + 'ms"></span>';
      el.querySelector('.toast-text').textContent = message;

      if (options.action && options.action.label) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'toast-action';
        button.textContent = options.action.label;
        button.addEventListener('click', function () {
          clearTimeout(timer);
          UI.dismiss(el);
          options.action.onClick();
        });
        el.insertBefore(button, el.querySelector('.toast-bar'));
      }

      var timer = setTimeout(function () { UI.dismiss(el); }, duration);
      el.addEventListener('click', function (event) {
        if (event.target.closest('.toast-action')) return;
        clearTimeout(timer);
        UI.dismiss(el);
      });

      wrap.appendChild(el);
    },

    dismiss: function (el) {
      if (!el || el.dataset.leaving) return;
      el.dataset.leaving = '1';
      el.classList.add('leaving');
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 220);
    },

    /**
     * حالة الانقطاع: تظهر رسالة واضحة عند تعذّر الطلب، وتُرجِع زر التأكيد
     * إلى حالته الأصلية عند عودة الاتصال (خطأ سابق: كان الزر يبقى معطّلًا).
     */
    setOffline: function (offline) {
      var banner = $('offline-banner');
      if (banner) UI.setHidden(banner, !offline);
      document.body.classList.toggle('offline', !!offline);

      var submit = $('submit-order');
      if (!submit) return;

      if (!submit.dataset.idleText) submit.dataset.idleText = submit.textContent;

      if (offline) {
        submit.disabled = true;
        submit.textContent = 'الطلب متوقف — لا يوجد اتصال';
        submit.classList.remove('is-loading');
      } else {
        submit.disabled = false;
        submit.textContent = submit.dataset.idleText;
      }
    },

    /** زر أثناء تنفيذ عملية: يمنع التكرار ويعرض مؤشرًا بدل النص. */
    setLoading: function (button, loading, label) {
      if (!button) return;
      if (loading) {
        if (!button.dataset.idleLabel) button.dataset.idleLabel = button.textContent;
        button.disabled = true;
        button.classList.add('is-loading');
        button.textContent = label || 'جارٍ التنفيذ…';
      } else {
        button.disabled = false;
        button.classList.remove('is-loading');
        button.textContent = button.dataset.idleLabel || button.textContent;
        delete button.dataset.idleLabel;
      }
    },

    renderBrand: function (settings, config) {
      var name = $('brand-name');
      var tagline = $('brand-tagline');
      var logo = $('brand-logo');
      if (!name) return;

      name.textContent = settings.app_name || 'القائمة';
      document.title = (settings.app_name || 'القائمة');

      if (settings.app_tagline) {
        tagline.textContent = settings.app_tagline;
        tagline.hidden = false;
      } else {
        tagline.hidden = true;
      }

      if (settings.logo_path) {
        logo.src = settings.logo_path;
        logo.hidden = false;
      } else {
        logo.hidden = true;
      }

      var footer = $('app-footer');
      var contact = $('footer-contact');
      if (settings.show_contact && contact) {
        var parts = [];
        if (settings.contact_phone) {
          parts.push('<a href="tel:' + UI.escape(settings.contact_phone) + '" dir="ltr">' +
            UI.escape(settings.contact_phone) + '</a>');
        }
        if (settings.contact_instagram) {
          parts.push('<a href="https://instagram.com/' + UI.escape(settings.contact_instagram).replace(/^@/, '') +
            '" target="_blank" rel="noopener noreferrer" dir="ltr">@' +
            UI.escape(settings.contact_instagram) + '</a>');
        }
        contact.innerHTML = parts.join('<span class="footer-sep"> · </span>');
        UI.setHidden(footer, parts.length === 0);
      } else if (footer) {
        UI.setHidden(footer, true);
      }
    },

    renderLocationChip: function (location, config) {
      var chip = $('loc-chip');
      var text = $('loc-chip-text');
      var clear = $('loc-clear');
      if (!chip) return;

      if (location) {
        text.textContent = (config.location_singular || 'الموقع') + ': ' + location.name;
        chip.hidden = false;
        clear.hidden = config.location_source === 'qr';
      } else {
        chip.hidden = true;
      }
    },

    renderTabs: function (categories, activeId) {
      var tabs = $('category-tabs');
      if (!tabs) return;

      var html = '<button class="tab" role="tab" data-cat="" aria-selected="' +
        (!activeId) + '">الكل</button>';

      categories.forEach(function (category) {
        html += '<button class="tab" role="tab" data-cat="' + category.id + '" aria-selected="' +
          (String(activeId) === String(category.id)) + '">' + UI.escape(category.name) +
          ' <span class="tab-count">' + category.count + '</span></button>';
      });

      tabs.innerHTML = html;

      // إبقاء التصنيف النشط ظاهرًا عند التمرير الأفقي
      var active = tabs.querySelector('.tab[aria-selected="true"]');
      if (active && active.scrollIntoView) {
        active.scrollIntoView({ block: 'nearest', inline: 'center' });
      }
    },

    renderSearch: function (visible) {
      var wrap = $('search-wrap');
      if (wrap) wrap.hidden = !visible;
    },

    /** هيكل تحميل مؤقت: يمنع الوميض الأبيض أثناء جلب الكتالوج. */
    renderSkeleton: function (count) {
      var box = $('menu-skeleton');
      if (!box) return;
      var html = '';
      for (var i = 0; i < (count || 6); i++) {
        html += '<div class="skeleton">' +
          '<div class="skeleton-media"></div>' +
          '<div class="skeleton-line"></div>' +
          '<div class="skeleton-line short"></div>' +
        '</div>';
      }
      box.innerHTML = html;
      UI.setHidden(box, false);
    },

    hideSkeleton: function () {
      var box = $('menu-skeleton');
      if (box) UI.setHidden(box, true);
    },

    renderProducts: function (products, filters) {
      var grid = $('product-grid');
      var empty = $('menu-empty');
      if (!grid) return;

      if (!products.length) {
        grid.innerHTML = '';
        UI.setHidden(empty, false);
        if (filters && filters.search) {
          $('menu-empty-title').textContent = 'لا توجد نتائج';
          $('menu-empty-text').textContent = 'لم نجد منتجًا يطابق «' + filters.search +
            '». جرّب كلمة أخرى أو تصفّح التصنيفات.';
        } else {
          $('menu-empty-title').textContent = 'القائمة قيد التحضير';
          $('menu-empty-text').textContent = 'لم يُضف أي منتج بعد. عد بعد قليل أو اطلب من المزود.';
        }
        return;
      }

      UI.setHidden(empty, true);

      grid.innerHTML = products.map(function (product) {
        var tags = (product.tags || []).map(function (tag) {
          return '<span class="badge ' + (tag.kind === 'flag' ? 'flag' : '') + '">' + UI.escape(tag.label) + '</span>';
        }).join('');

        var soldBadge = product.available === false ? '<span class="badge sold">غير متوفر</span>' : '';
        var quantity = global.App.Store.quantityOf(product.id);

        var media = product.image
          ? '<img src="' + UI.escape(product.image) + '" alt="' + UI.escape(product.name) + '" loading="lazy" decoding="async">'
          : '<div class="placeholder" aria-hidden="true">🍽️</div>';

        var desc = product.description
          ? '<p class="card-desc">' + UI.escape(product.description) + '</p>'
          : '';

        var priceLabel = global.App.Store.settings.show_prices === false
          ? ''
          : '<span class="card-price">' + UI.escape(product.price) + '</span>';

        // المنتج الذي له تفاصيل يستحق الضغط على البطاقة؛ وإلا فالزر يضيف مباشرة
        var addButton = product.available === false ? '' :
          '<button class="card-add' + (quantity ? ' added' : '') + '" data-add="' + product.id +
          '" aria-label="' + (quantity ? 'في السلة: ' + quantity + ' — إضافة ' : 'إضافة ') +
          UI.escape(product.name) + '">' + (quantity ? quantity : '+') + '</button>';

        return '<article class="product-card' + (product.available === false ? ' is-out' : '') + '"' +
          (product.has_details ? ' data-detail="' + product.id + '" role="button" tabindex="0"' : '') + '>' +
          '<div class="card-media">' + media +
            '<div class="card-badges">' + tags + soldBadge + '</div>' +
          '</div>' +
          '<div class="card-body">' +
            '<h3 class="card-name">' + UI.escape(product.name) + '</h3>' + desc +
            '<div class="card-foot">' + priceLabel + addButton + '</div>' +
          '</div>' +
        '</article>';
      }).join('');
    },

    renderCart: function () {
      var list = $('cart-list');
      var empty = $('cart-empty');
      var summary = $('cart-summary');
      var subtotal = $('cart-subtotal');
      var checkoutBtn = $('to-checkout');
      var count = $('cart-count');
      if (!list) return;

      var store = global.App.Store;
      var items = store.cart;
      var total = items.reduce(function (sum, i) { return sum + i.quantity; }, 0);

      var previous = count.textContent;
      count.textContent = total;
      count.hidden = total === 0;
      if (String(total) !== String(previous) && total > 0) {
        count.classList.remove('bump');
        void count.offsetWidth;
        count.classList.add('bump');
      }

      // الزر العائم: يظهر فقط خارج شاشتَي السلة وإتمام الطلب
      var fab = $('cart-fab');
      var fabCount = $('fab-count');
      if (fab && fabCount) {
        var showFab = total > 0 && !['cart', 'checkout'].includes(global.App.Router && global.App.Router.current());
        fabCount.textContent = total;
        UI.setHidden(fab, !showFab);
      }

      UI.setHidden($('clear-cart'), items.length === 0);

      if (!items.length) {
        list.innerHTML = '';
        UI.setHidden(empty, false);
        UI.setHidden(summary, true);
        if (checkoutBtn) checkoutBtn.disabled = true;
        return;
      }

      UI.setHidden(empty, true);
      UI.setHidden(summary, false);
      if (checkoutBtn) checkoutBtn.disabled = false;

      list.innerHTML = items.map(function (item) {
        var thumb = item.image
          ? '<img class="cart-thumb" src="' + UI.escape(item.image) + '" alt="" loading="lazy">'
          : '<div class="cart-thumb" aria-hidden="true"></div>';

        return '<div class="cart-item" data-id="' + item.product_id + '">' + thumb +
          '<div class="cart-info">' +
            '<h4>' + UI.escape(item.name) + '</h4>' +
            '<p>' + UI.escape(UI.formatPrice(item.price_minor)) + ' للقطعة</p>' +
          '</div>' +
          '<div class="cart-side">' +
            '<span class="cart-line">' + UI.escape(UI.formatPrice(item.price_minor * item.quantity)) + '</span>' +
            '<div class="stepper">' +
              '<button class="step-btn" data-cart-step="-1" data-id="' + item.product_id + '" aria-label="إنقاص الكمية">−</button>' +
              '<span class="step-value">' + item.quantity + '</span>' +
              '<button class="step-btn" data-cart-step="1" data-id="' + item.product_id + '" aria-label="زيادة الكمية">+</button>' +
            '</div>' +
            '<button class="cart-remove" data-cart-remove="' + item.product_id + '">حذف</button>' +
          '</div>' +
        '</div>';
      }).join('');

      subtotal.textContent = UI.formatPrice(store.total());
    },

    renderOrderTypes: function (types, selected) {
      var wrap = $('order-type-choices');
      var field = $('field-type');
      if (!wrap || !field) return;

      if (!types || types.length < 2) { UI.setHidden(field, true); return; }
      UI.setHidden(field, false);

      wrap.innerHTML = types.map(function (type) {
        return '<button class="choice" type="button" data-type="' + UI.escape(type.key) + '" aria-pressed="' +
          (type.key === selected) + '">' + UI.escape(type.label) + '</button>';
      }).join('');
    },

    renderLocations: function (locations, selectedCode, required) {
      var field = $('field-location');
      var select = $('location-select');
      var label = $('label-location');
      if (!field || !select) return;

      var config = global.App.Store.config || {};
      var singular = config.location_singular || 'الموقع';

      if (!required && selectedCode) {
        UI.setHidden(field, true);
        return;
      }
      UI.setHidden(field, false);
      label.textContent = 'اختر ' + singular;

      select.innerHTML = '<option value="">— ' + singular + ' —</option>' +
        locations.map(function (location) {
          return '<option value="' + UI.escape(location.code) + '"' +
            (location.code === selectedCode ? ' selected' : '') + '>' + UI.escape(location.name) + '</option>';
        }).join('');
    },

    renderDynamicFields: function (fields) {
      var wrap = $('dynamic-fields');
      if (!wrap) return;

      if (!fields || !fields.length) { wrap.innerHTML = ''; return; }

      wrap.innerHTML = fields.map(function (field) {
        var inputId = 'field-' + field.key;
        var input;

        if (field.type === 'textarea') {
          input = '<textarea id="' + inputId + '" name="' + UI.escape(field.key) + '" placeholder="' +
            UI.escape(field.placeholder || '') + '"></textarea>';
        } else if (field.type === 'select') {
          input = '<select id="' + inputId + '" name="' + UI.escape(field.key) + '">' +
            (field.options || []).map(function (opt) {
              return '<option value="' + UI.escape(opt.value) + '">' + UI.escape(opt.label) + '</option>';
            }).join('') + '</select>';
        } else if (field.type === 'boolean') {
          return '<div class="field" data-field="' + UI.escape(field.key) + '">' +
            '<button class="choice" type="button" data-bool="' + UI.escape(field.key) + '" aria-pressed="false">' +
            UI.escape(field.label) + '</button></div>';
        } else {
          var type = field.type === 'tel' ? 'tel' : (field.type === 'number' ? 'number' : 'text');
          input = '<input type="' + type + '" id="' + inputId + '" name="' + UI.escape(field.key) +
            '" placeholder="' + UI.escape(field.placeholder || '') + '"' +
            (field.type === 'number' ? ' inputmode="numeric"' : '') + '>';
        }

        return '<div class="field" data-field="' + UI.escape(field.key) + '">' +
          '<label for="' + inputId + '">' + UI.escape(field.label) +
          (field.required ? ' *' : '') + '</label>' + input +
          (field.hint ? '<small class="field-hint">' + UI.escape(field.hint) + '</small>' : '') +
          '<small class="field-error" data-error-for="' + UI.escape(field.key) + '"></small>' +
        '</div>';
      }).join('');
    },

    renderProductSheet: function (product, config) {
      var body = $('sheet-body');
      var footer = $('sheet-footer');
      var qty = $('sheet-qty');
      if (!body || !product) return;

      var media = product.image
        ? '<div class="sheet-media"><img src="' + UI.escape(product.image) + '" alt="' + UI.escape(product.name) + '"></div>'
        : '';

      var tags = (product.extra || {});
      var tagHtml = '';
      Object.keys(tags).forEach(function (key) {
        if (tags[key] === true || (typeof tags[key] === 'string' && tags[key] !== '')) {
          tagHtml += '<span class="sheet-tag">' + UI.escape(key) + '</span>';
        }
      });

      var details = (product.details || []).filter(function (d) {
        return d.value !== '' && d.value !== null && d.value !== undefined && d.value !== false;
      }).map(function (d) {
        var value = d.value === true ? 'نعم' : (d.value === false ? 'لا' : d.value);
        return '<div class="detail-row"><dt>' + UI.escape(d.label) + '</dt><dd>' + UI.escape(value) + '</dd></div>';
      }).join('');

      body.innerHTML = media +
        '<h3 class="sheet-title">' + UI.escape(product.name) + '</h3>' +
        (global.App.Store.settings.show_prices === false ? '' :
          '<p class="sheet-price">' + UI.escape(product.price) + '</p>') +
        (tagHtml ? '<div class="sheet-tags">' + tagHtml + '</div>' : '') +
        (product.description ? '<p class="sheet-desc">' + UI.escape(product.description) + '</p>' : '') +
        (details ? '<dl class="detail-list">' + details + '</dl>' : '');

      UI.setHidden(footer, product.available === false);
      if (qty) qty.textContent = '1';
    },

    renderSuccess: function (order) {
      var rows = [];
      if (order.location) rows.push(cartRow('الموقع', order.location));
      rows.push(cartRow('عدد الأصناف', String(order.items_count || (order.items || []).length)));

      (order.items || []).forEach(function (item) {
        rows.push(cartRow(item.quantity + ' × ' + item.name, item.line_total));
      });

      $('success-number').textContent = '#' + order.number;
      $('success-details').innerHTML = rows.join('');

      var track = $('success-track');
      if (track && order.tracking_url) {
        track.href = order.tracking_url;
        UI.setHidden(track, false);
      }
    },

    renderTracking: function (order) {
      $('track-number').textContent = '#' + order.number;

      var pill = $('track-status');
      pill.textContent = order.status_label;
      pill.style.background = order.status_color || 'var(--ink-2)';

      var meta = [];
      if (order.location) meta.push('<span>' + UI.escape(order.location) + '</span>');
      if (order.order_type) meta.push('<span>' + UI.escape(order.order_type) + '</span>');
      if (order.time_label) meta.push('<span>' + UI.escape(order.time_label) + '</span>');
      if (order.customer) meta.push('<span>' + UI.escape(order.customer) + '</span>');
      $('track-meta').innerHTML = meta.join('');

      $('track-items').innerHTML = (order.items || []).map(function (item) {
        return '<li><span>' + UI.escape(item.quantity + ' × ' + item.name) + '</span><strong>' +
          UI.escape(item.line_total) + '</strong></li>';
      }).join('');

      $('track-total').textContent = order.total;
    },

    /**
     * إظهار/إخفاء عنصر بأمان.
     * ملاحظة مهمة: بعض العناصر تحمل الصنف hidden في HTML، بينما أخرى
     * تستخدم الخاصية hidden فقط. هنا نضبط الاثنين معًا حتى لا تبقى
     * رسالة خطأ مخفية بسبب تعارض القواعد.
     */
    setHidden: function (el, hidden) {
      if (!el) return;
      el.hidden = hidden;
      el.classList.toggle('hidden', hidden);
    },

    showError: function (alertId, message, fields) {
      var alert = $(alertId);
      if (!alert) return;
      alert.textContent = message;
      UI.setHidden(alert, false);

      UI.clearFieldErrors();
      if (fields) {
        Object.keys(fields).forEach(function (key) {
          var error = document.querySelector('[data-error-for="' + key + '"]');
          var input = document.querySelector('[name="' + key + '"]');
          if (error) error.textContent = fields[key];
          if (input && input.closest) input.closest('.field').classList.add('invalid');
        });
      }

      // التركيز على أول حقل خاطئ يجعل التصحيح فوريًا
      var firstInvalid = document.querySelector('.field.invalid input, .field.invalid select');
      if (firstInvalid) firstInvalid.focus({ preventScroll: true });
      else alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
    },

    clearError: function (alertId) {
      var alert = $(alertId);
      if (alert) UI.setHidden(alert, true);
      UI.clearFieldErrors();
    },

    clearFieldErrors: function () {
      document.querySelectorAll('.field.invalid').forEach(function (el) { el.classList.remove('invalid'); });
      document.querySelectorAll('.field-error').forEach(function (el) { el.textContent = ''; });
    }
  };

  function cartRow(label, value) {
    return '<div class="success-row"><span>' + UI.escape(label) + '</span><strong>' + UI.escape(value) + '</strong></div>';
  }

  global.App = global.App || {};
  global.App.UI = UI;
})(window);