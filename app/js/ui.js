/* ==========================================================================
   ui.js — دوال العرض. تبقى بلا حالة: تستقبل البيانات وترسم الشاشة.
   ========================================================================== */
(function (global) {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };

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

    toast: function (message, duration) {
      var el = $('toast');
      if (!el) return;
      el.textContent = message;
      el.hidden = false;
      clearTimeout(UI._toastTimer);
      UI._toastTimer = setTimeout(function () { el.hidden = true; }, duration || 2600);
    },

    setOffline: function (offline) {
      var banner = $('offline-banner');
      if (!banner) return;
      banner.hidden = !offline;
      document.body.classList.toggle('offline', offline);

      if (offline) {
        var submit = $('submit-order');
        if (submit) {
          submit.disabled = true;
          submit.textContent = 'الطلب متوقف — لا يوجد اتصال';
        }
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
          parts.push('<a href="tel:' + UI.escape(settings.contact_phone) + '">' + UI.escape(settings.contact_phone) + '</a>');
        }
        if (settings.contact_instagram) {
          parts.push('<span dir="ltr">@' + UI.escape(settings.contact_instagram) + '</span>');
        }
        contact.innerHTML = parts.join(' · ');
        footer.hidden = parts.length === 0;
      } else if (footer) {
        footer.hidden = true;
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

    renderTabs: function (categories, activeId, features) {
      var tabs = $('category-tabs');
      if (!tabs) return;

      var html = '<button class="tab" role="tab" data-cat="" aria-selected="' +
        (!activeId) + '">الكل</button>';

      categories.forEach(function (category) {
        html += '<button class="tab" role="tab" data-cat="' + category.id + '" aria-selected="' +
          (String(activeId) === String(category.id)) + '">' + UI.escape(category.name) +
          (features.search ? '' : '') +
          ' <span class="tab-count">' + category.count + '</span></button>';
      });

      tabs.innerHTML = html;
    },

    renderSearch: function (visible) {
      var wrap = $('search-wrap');
      if (wrap) wrap.hidden = !visible;
    },

    renderProducts: function (products, filters) {
      var grid = $('product-grid');
      var empty = $('menu-empty');
      if (!grid) return;

      if (!products.length) {
        grid.innerHTML = '';
        empty.hidden = false;
        empty.textContent = filters && filters.search
          ? 'لا توجد نتائج مطابقة لبحثك.'
          : 'لا توجد منتجات في هذا التصنيف حاليًا.';
        return;
      }

      empty.hidden = true;

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
          '" aria-label="إضافة ' + UI.escape(product.name) + '">' + (quantity ? quantity : '+') + '</button>';

        return '<article class="product-card' + (product.available === false ? ' is-out' : '') + '"' +
          (product.has_details ? ' data-detail="' + product.id + '"' : '') + '>' +
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

      count.textContent = items.reduce(function (sum, i) { return sum + i.quantity; }, 0);
      count.hidden = items.length === 0;

      if (!items.length) {
        list.innerHTML = '';
        empty.hidden = false;
        summary.hidden = true;
        if (checkoutBtn) checkoutBtn.disabled = true;
        return;
      }

      empty.hidden = true;
      summary.hidden = false;
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
              '<button class="step-btn" data-cart-step="-1" data-id="' + item.product_id + '" aria-label="إنقاص">−</button>' +
              '<span class="step-value">' + item.quantity + '</span>' +
              '<button class="step-btn" data-cart-step="1" data-id="' + item.product_id + '" aria-label="زيادة">+</button>' +
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

      if (!types || types.length < 2) { field.hidden = true; return; }
      field.hidden = false;

      wrap.innerHTML = types.map(function (type) {
        return '<button class="choice" type="button" data-type="' + type.key + '" aria-pressed="' +
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
        field.hidden = true;
        return;
      }
      field.hidden = false;
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
          (field.hint ? '<small class="field-hint" style="color:var(--ink-3);font-size:.76rem">' + UI.escape(field.hint) + '</small>' : '') +
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

      footer.hidden = product.available === false;
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
        track.hidden = false;
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
      meta.push('<span>' + UI.escape(order.time_label || '') + '</span>');
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
      alert.scrollIntoView({ behavior: 'smooth', block: 'center' });

      UI.clearFieldErrors();
      if (fields) {
        Object.keys(fields).forEach(function (key) {
          var error = document.querySelector('[data-error-for="' + key + '"]');
          var input = document.querySelector('[name="' + key + '"]');
          if (error) error.textContent = fields[key];
          if (input && input.closest) input.closest('.field').classList.add('invalid');
        });
      }
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