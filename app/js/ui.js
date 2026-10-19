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

  /**
   * أيقونات الأقسام — مضمّنة كمسارات SVG فقط.
   * القائمة مغلقة على الخادم (CompanyProfile::ICONS)، وما لا يقابله مفتاح
   * هنا يُرسم كنقطة بدل أن ينتج أيقونة مكسورة.
   */
  var P = '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>';
  var ICONS = {
    star:    P + '<path d="m12 3.8 2.5 5.1 5.7.8-4.1 4 1 5.6L12 16.6 6.9 19.3l1-5.6-4.1-4 5.7-.8Z"/>',
    shield:  P + '<path d="M12 3.5 19 6v6c0 4-2.9 7-7 8.5-4.1-1.5-7-4.5-7-8.5V6Z"/><path d="m9 12 2 2 4-4"/>',
    clock:   P + '<circle cx="12" cy="12" r="8"/><path d="M12 7.5V12l3 2"/>',
    truck:   P + '<path d="M3 7h10v8H3zM13 10h4l3 3v2h-7z"/><circle cx="7" cy="17" r="1.6"/><circle cx="17" cy="17" r="1.6"/>',
    check:   P + '<circle cx="12" cy="12" r="8"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
    heart:   P + '<path d="M12 19s-6.5-4.2-6.5-8.3A3.7 3.7 0 0 1 12 8.4a3.7 3.7 0 0 1 6.5 2.3C18.5 14.8 12 19 12 19Z"/>',
    phone:   P + '<path d="M6.6 3.5h3l1.5 3.8-2 1.4a12 12 0 0 0 5.2 5.2l1.4-2 3.8 1.5v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.6 5.7 2 2 0 0 1 6.6 3.5Z"/>',
    mail:    P + '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="m4 7 8 5.5L20 7"/>',
    pin:     P + '<path d="M12 21s6.5-6 6.5-10.5a6.5 6.5 0 1 0-13 0C5.5 15 12 21 12 21Z"/><circle cx="12" cy="10.5" r="2.3"/>',
    calendar: P + '<rect x="3.5" y="5.5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3.5v4M16 3.5v4"/>',
    award:   P + '<circle cx="12" cy="9" r="5.5"/><path d="m8.5 14 -1.5 6.5 5-2.5 5 2.5L15.5 14"/>',
    sparkle: P + '<path d="m12 3.5 1.9 4.6 4.6 1.9-4.6 1.9L12 16.5l-1.9-4.6L5.5 10l4.6-1.9Z"/><path d="M18.5 16.5v3M20 18h-3"/>',
    leaf:    P + '<path d="M5 19c0-7 4.5-11 14-11 0 8-4.5 11-10 11-1.6 0-3-.3-4-.8Z"/><path d="M5.5 18.5C8 15 11 12.8 15 11.5"/>',
    tool:    P + '<path d="M14.5 4.5a4 4 0 0 0 5 5l-9 9-4-4Z"/><path d="m6 14-2 6 6-2"/>',
    tag:     P + '<path d="M11 3.5H4.5V10l9.5 9.5L20.5 13Z"/><circle cx="7.8" cy="7.8" r="1.2"/>',
    gift:    P + '<rect x="3.5" y="8.5" width="17" height="4" rx="1"/><path d="M5 12.5v6.5a1.5 1.5 0 0 0 1.5 1.5h11a1.5 1.5 0 0 0 1.5-1.5v-6.5M12 8.5v12"/><path d="M12 8.5S10.5 3.5 8 3.5a2.2 2.2 0 0 0 0 5Zm0 0s1.5-5 4-5a2.2 2.2 0 0 1 0 5Z"/>',
    users:   P + '<circle cx="9" cy="8" r="3.5"/><path d="M2.8 19.5a6.2 6.2 0 0 1 12.4 0"/><path d="M16 5.2a3.5 3.5 0 0 1 0 6.6M17.5 19.5a6 6 0 0 0-2-4.4"/>',
    chart:   P + '<path d="M4 20V5M4 20h16"/><path d="M8 20v-6M12.5 20v-9M17 20v-4"/>',
    box:     P + '<path d="m12 3.5 8 4v9l-8 4-8-4v-9Z"/><path d="m4 7.5 8 4 8-4M12 11.5V20"/>',
    key:     P + '<circle cx="8" cy="12" r="4"/><path d="M12 12h9M18 12v3.5M15.5 12v2.5"/>'
  };

  function icon(name, cls) {
    var body = ICONS[name] || ICONS.check;
    return '<svg class="ico' + (cls ? ' ' + cls : '') + '" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">' + body + '</svg>';
  }

  /** وسائل التواصل: [{key,label,icon,value,href,external}] */
  function contactWays(contact) {
    var ways = [];
    if (!contact) return ways;

    function add(key, label, ico, value, href, external) {
      if (!value) return;
      ways.push({ key: key, label: label, icon: ico, value: value, href: href, external: !!external });
    }

    add('phone', 'الهاتف', 'phone', contact.phone,
      contact.phone ? 'tel:' + contact.phone.replace(/[^\d+]/g, '') : '');
    add('whatsapp', 'واتساب', 'phone', contact.whatsapp,
      contact.whatsapp ? 'https://wa.me/' + contact.whatsapp.replace(/[^\d]/g, '') : '', true);
    add('email', 'البريد الإلكتروني', 'mail', contact.email,
      contact.email ? 'mailto:' + contact.email : '');
    add('instagram', 'إنستغرام', 'tag', contact.instagram,
      contact.instagram ? 'https://instagram.com/' + contact.instagram : '', true);
    add('facebook', 'فيسبوك', 'users', contact.facebook, contact.facebook, true);
    add('telegram', 'تليجرام', 'mail', contact.telegram,
      contact.telegram ? 'https://t.me/' + contact.telegram : '', true);
    add('tiktok', 'تيك توك', 'tag', contact.tiktok,
      contact.tiktok ? 'https://tiktok.com/@' + contact.tiktok : '', true);
    add('youtube', 'يوتيوب', 'box', contact.youtube, contact.youtube, true);
    add('address', 'العنوان', 'pin', contact.address, contact.map_url || '', !!contact.map_url);
    return ways;
  }

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
        '<span class="toast-bar"></span>';

      // مدة الشريط عبر CSSOM (لا سمة style مضمّنة) — سلوك واحد في الصفحتين
      el.querySelector('.toast-bar').style.animationDuration = duration + 'ms';
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

      // اسم الشركة في company.json يتقدّم على إعدادات لوحة الإدارة
      var company = (global.App && global.App.Store.company) || {};
      var appName    = (company.brand && company.brand.name)    || settings.app_name    || '';
      var appTagline = (company.brand && company.brand.tagline) || settings.app_tagline || '';

      name.textContent = appName || 'القائمة';
      document.title = appName || 'القائمة';

      if (appTagline) {
        tagline.textContent = appTagline;
        tagline.hidden = false;
      } else {
        tagline.hidden = true;
      }

      var logoSrc = (company.brand && company.brand.logo) || settings.logo_path;
      if (logoSrc) {
        logo.src = logoSrc;
        logo.hidden = false;
      } else {
        logo.hidden = true;
      }

      var ways = contactWays(company.contact);
      var footer = $('app-footer');
      var contact = $('footer-contact');

      if (settings.show_contact !== false && contact && ways.length) {
        contact.innerHTML = ways.slice(0, 3).map(function (way) {
          return '<a href="' + UI.escape(way.href || '#') + '"' +
            (way.external ? ' target="_blank" rel="noopener noreferrer"' : '') + '>' +
            UI.escape(way.value) + '</a>';
        }).join('<span class="footer-sep"> · </span>');
      } else if (contact) {
        contact.innerHTML = '';
      }

      var note = $('footer-note');
      if (note) note.textContent = (company.footer && company.footer.note) || '';

      var links = $('footer-links');
      var items = (company.footer && company.footer.links) || [];
      if (links) {
        links.innerHTML = items.map(function (link) {
          return '<a href="' + UI.escape(link.url) + '" target="_blank" rel="noopener noreferrer">' +
            UI.escape(link.label) + '</a>';
        }).join('<span class="footer-sep"> · </span>');
        links.hidden = items.length === 0;
      }

      if (footer) UI.setHidden(footer, !(ways.length || (note && note.textContent) || items.length));
    },

    /* ==================================================================
       الصفحة الرئيسية — تُبنى بالكامل من app/data/company.json
       كل حقل فارغ في الملف يعني: لا تُرسم هذه القطعة أصلًا.
       ================================================================== */

    renderHome: function () {
      var store = global.App.Store;
      var company = store.company || {};
      var brand = company.brand || {};
      var about = company.about || {};
      var cta = company.cta || {};

      /* --- الغلاف: شعار + صورة + اسم + وصف + زر --- */
      var heroImage = brand.hero_image || brand.cover_image;
      if (heroImage) {
        $('hero-image').src = heroImage;
        $('hero-image').alt = brand.name || '';
        UI.setHidden($('hero-media'), false);
      } else {
        UI.setHidden($('hero-media'), true);
      }

      /*
       * الشعار في منتصف الصفحة الرئيسية: كبير/متوسط وتحته اسم المشروع.
       * مصدره company.json ثم إعدادات لوحة الإدارة (نفس ترتيب الرأس).
       */
      var heroLogo = $('hero-logo');
      var logoSrc = brand.logo || store.settings.logo_path || '';
      if (heroLogo) {
        if (logoSrc) {
          var logoImg = $('hero-logo-img');
          if (logoImg.getAttribute('src') !== logoSrc) logoImg.src = logoSrc;
          logoImg.alt = brand.name || store.settings.app_name || '';
          UI.setHidden(heroLogo, false);
        } else {
          UI.setHidden(heroLogo, true);
        }
      }

      $('hero-title').textContent = brand.name || store.settings.app_name || '';

      var eyebrow = $('hero-eyebrow');
      if (brand.tagline) {
        eyebrow.textContent = brand.tagline;
        UI.setHidden(eyebrow, false);
      } else {
        UI.setHidden(eyebrow, true);
      }

      var heroText = $('hero-text');
      if (about.summary) {
        heroText.textContent = about.summary;
        UI.setHidden(heroText, false);
      } else {
        UI.setHidden(heroText, true);
      }

      // الزر الأول دومًا إلى المنتجات، والثاني إلى التواصل في وضع العرض فقط
      var mainCta = $('hero-cta');
      mainCta.textContent = store.catalogCta();
      mainCta.setAttribute('data-go', 'products');

      var second = $('hero-cta-2');
      if (store.hasOrdering()) {
        second.textContent = store.orderCta();
        second.setAttribute('data-go', 'products');
        UI.setHidden(second, false);
      } else {
        second.setAttribute('data-go', 'contact');
        second.textContent = 'تواصل معنا';
        UI.setHidden(second, false);
      }

      /* --- رسالة الترحيب (نفس الحقل القديم، بمكان أفضل) --- */
      var welcome = $('welcome-notice');
      if (store.settings.show_welcome && store.settings.welcome_message) {
        welcome.textContent = store.settings.welcome_message;
        UI.setHidden(welcome, false);
      } else {
        UI.setHidden(welcome, true);
      }

      /* --- نقاط التميّز --- */
      var highlights = about.highlights || [];
      var hlBox = $('highlights');
      if (highlights.length) {
        hlBox.innerHTML = highlights.map(function (item) {
          return '<div class="highlight">' +
            '<span class="highlight-ico">' + icon(item.icon, 'ico-fill') + '</span>' +
            '<h3 class="highlight-title">' + UI.escape(item.title) + '</h3>' +
            (item.text ? '<p class="highlight-text">' + UI.escape(item.text) + '</p>' : '') +
          '</div>';
        }).join('');
        UI.setHidden(hlBox, false);
      } else {
        UI.setHidden(hlBox, true);
      }

      /* --- الأقسام: كتل مرنة بصور ونقاط --- */
      var sections = company.sections || [];
      $('home-sections').innerHTML = sections.map(function (section) {
        var bullets = (section.bullets || []).length
          ? '<ul class="bullets">' + section.bullets.map(function (line) {
              return '<li>' + UI.escape(line) + '</li>';
            }).join('') + '</ul>'
          : '';

        var media = section.image
          ? '<div class="section-media"><img src="' + UI.escape(section.image) +
            '" alt="" loading="lazy" decoding="async"></div>'
          : '';

        return '<section class="section' + (section.flip ? ' flip' : '') +
          '" id="' + UI.escape(section.id) + '">' +
          '<div class="section-body">' +
            (section.icon ? '<span class="section-ico">' + icon(section.icon, 'ico-fill') + '</span>' : '') +
            (section.title ? '<h2 class="section-heading">' + UI.escape(section.title) + '</h2>' : '') +
            (section.body ? '<p class="section-text">' + UI.escape(section.body) + '</p>' : '') +
            bullets +
          '</div>' + media +
        '</section>';
      }).join('');

      /* --- معرض الصور --- */
      var gallery = about.gallery || [];
      var galBox = $('gallery');
      if (gallery.length) {
        galBox.innerHTML = gallery.map(function (src, index) {
          return '<figure class="gallery-item"><img src="' + UI.escape(src) + '" alt="' +
            UI.escape(brand.name || '') + ' ' + (index + 1) +
            '" loading="lazy" decoding="async"></figure>';
        }).join('');
        UI.setHidden(galBox, false);
      } else {
        UI.setHidden(galBox, true);
      }

      /* --- شريط الدعوة للإجراء --- */
      var band = $('cta-band');
      var ctaButton = $('cta-button');
      if (cta.title || cta.text || cta.button_label) {
        $('cta-title').textContent = cta.title || '';
        UI.setHidden($('cta-title'), !cta.title);

        $('cta-text').textContent = cta.text || '';
        UI.setHidden($('cta-text'), !cta.text);

        ctaButton.textContent = cta.button_label || 'تواصل معنا';
        if (cta.button_url) {
          ctaButton.setAttribute('href', cta.button_url);
          ctaButton.setAttribute('target', '_blank');
          ctaButton.setAttribute('rel', 'noopener noreferrer');
          ctaButton.removeAttribute('data-go');
        } else {
          ctaButton.removeAttribute('target');
          ctaButton.removeAttribute('rel');
          ctaButton.setAttribute('data-go', 'contact');
        }
        UI.setHidden(band, false);
      } else {
        UI.setHidden(band, true);
      }

      /* --- نظرة سريعة على التواصل --- */
      var ways = contactWays(company.contact);
      var glance = $('contact-glance');
      if (ways.length) {
        $('glance-grid').innerHTML = ways.slice(0, 4).map(function (way) {
          return '<a class="glance-item" href="' + UI.escape(way.href || '#') + '"' +
            (way.external ? ' target="_blank" rel="noopener noreferrer"' : '') + '>' +
            '<span class="glance-ico">' + icon(way.icon) + '</span>' +
            '<span class="glance-text"><span class="glance-label">' + UI.escape(way.label) + '</span>' +
            '<span class="glance-value">' + UI.escape(way.value) + '</span></span>' +
          '</a>';
        }).join('');
        UI.setHidden(glance, false);
      } else {
        UI.setHidden(glance, true);
      }
    },

    /* ==================================================================
       صفحة التواصل — نفس المصدر، لكن بعرض أعمق
       ================================================================== */

    renderContact: function () {
      var company = (global.App.Store.company) || {};
      var contact = company.contact || {};
      var brand = company.brand || {};

      var intro = (company.about && company.about.summary) || brand.tagline || '';
      $('contact-intro').textContent = intro;

      var ways = contactWays(contact);
      var grid = $('contact-grid');

      if (ways.length) {
        grid.innerHTML = ways.map(function (way) {
          var body = '<span class="glance-ico">' + icon(way.icon) + '</span>' +
            '<span class="glance-text"><span class="glance-label">' + UI.escape(way.label) + '</span>' +
            '<span class="glance-value">' + UI.escape(way.value) + '</span></span>';

          // العنوان المكتوب بلا رابط لا يقبل الضغط
          return way.href
            ? '<a class="glance-item" href="' + UI.escape(way.href) + '"' +
              (way.external ? ' target="_blank" rel="noopener noreferrer"' : '') + '>' + body + '</a>'
            : '<div class="glance-item is-static">' + body + '</div>';
        }).join('');
        UI.setHidden(grid, false);
        UI.setHidden($('contact-empty'), true);
      } else {
        grid.innerHTML = '';
        UI.setHidden(grid, true);
        UI.setHidden($('contact-empty'), false);
      }

      /* --- أوقات العمل --- */
      var hours = contact.working_hours || [];
      if (hours.length) {
        $('hours-list').innerHTML = hours.map(function (row) {
          return '<div class="hours-row"><dt>' + UI.escape(row.day) + '</dt><dd>' +
            (row.label ? UI.escape(row.label) : 'مغلق') + '</dd></div>';
        }).join('');
        UI.setHidden($('contact-hours'), false);
      } else {
        UI.setHidden($('contact-hours'), true);
      }

      /* --- العنوان والخريطة --- */
      if (contact.address) {
        $('address-text').textContent = contact.address;
        if (contact.map_url) {
          $('map-link').href = contact.map_url;
          UI.setHidden($('map-link'), false);
        } else {
          UI.setHidden($('map-link'), true);
        }
        UI.setHidden($('contact-address'), false);
      } else {
        UI.setHidden($('contact-address'), true);
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

    /**
     * يطبّق تفضيل العرض المحفوظ (مربعات / صفوف منفردة) على شبكة المنتجات
     * وعلى مفتاح التبديل. التفضيل محلي في المتصفح ولا يُخزَّن على الخادم.
     */
    applyLayout: function () {
      var layout = global.App.Store.layout === 'list' ? 'list' : 'grid';
      var grid = $('product-grid');
      if (grid) grid.classList.toggle('is-list', layout === 'list');

      ['grid', 'list'].forEach(function (name) {
        var btn = $('layout-' + name);
        if (btn) btn.setAttribute('aria-pressed', name === layout ? 'true' : 'false');
      });
    },

renderProducts: function (products, filters) {
      var grid = $('product-grid');
      var empty = $('menu-empty');
      if (!grid) return;

      UI.applyLayout();

      var store = global.App.Store;
      var ordering = store.hasOrdering();
      var plural = store.productPlural();

      if (!products.length) {
        grid.innerHTML = '';
        UI.setHidden(empty, false);
        if (filters && filters.search) {
          $('menu-empty-title').textContent = 'لا توجد نتائج';
          $('menu-empty-text').textContent = 'لم نجد ' + store.productSingular() + ' يطابق «' +
            filters.search + '». جرّب كلمة أخرى أو تصفّح التصنيفات.';
        } else {
          $('menu-empty-title').textContent = plural + ' قيد التحضير';
          $('menu-empty-text').textContent = 'لم تُضف أي ' + plural + ' بعد. عد بعد قليل.';
        }
        return;
      }

      UI.setHidden(empty, true);

      grid.innerHTML = products.map(function (product) {
        var tags = (product.tags || []).map(function (tag) {
          return '<span class="badge ' + (tag.kind === 'flag' ? 'flag' : '') + '">' + UI.escape(tag.label) + '</span>';
        }).join('');

        var soldBadge = product.available === false ? '<span class="badge sold">غير متوفر</span>' : '';
        var quantity = store.quantityOf(product.id);

        var media = product.image
          ? '<img src="' + UI.escape(product.image) + '" alt="' + UI.escape(product.name) + '" loading="lazy" decoding="async">'
          : '<div class="placeholder" aria-hidden="true">&#10022;</div>';

        var desc = product.description
          ? '<p class="card-desc">' + UI.escape(product.description) + '</p>'
          : '';

        var priceLabel = store.settings.show_prices === false
          ? ''
          : '<span class="card-price">' + UI.escape(product.price) + '</span>';

        /*
         * وضع العرض فقط: لا زر إضافة. البطاقة معرض يُستعرض، والسعر يبقى
         * معروضًا لأنه جزء من تعريف المنتج لا من مسار الشراء.
         */
        var addButton = '';
        if (ordering && product.available !== false) {
          addButton = '<button class="card-add' + (quantity ? ' added' : '') + '" data-add="' + product.id +
            '" aria-label="' + (quantity ? 'في السلة: ' + quantity + ' — إضافة ' : 'إضافة ') +
            UI.escape(product.name) + '">' + (quantity ? quantity : '+') + '</button>';
        }

        /*
         * كل بطاقة تُفتح: الضغط عليها يعرض نافذة منبثقة متوسطة بالتفاصيل،
         * وإن كان للمنتج نص طويل يُفتح من داخلها رابط إلى صفحة منفردة أكبر.
         */
        return '<article class="product-card' + (product.available === false ? ' is-out' : '') + ' is-openable"' +
          ' data-detail="' + product.id + '" role="button" tabindex="0">' +
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

      // وضع العرض فقط: لا سلة أصلًا. نُخفي ما تبقّى من أي سلة قديمة محفوظة
      // من قبل تغيير الوضع، بدل ترك أزرار معلّقة بلا عمل.
      if (!store.hasOrdering()) {
        if (store.cart.length) store.clearCart();
        if (count) { count.textContent = '0'; count.hidden = true; }
        UI.setHidden($('cart-fab'), true);
        UI.setHidden($('cart-button'), true);
        return;
      }

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

      /*
       * النافذة متوسطة الحجم عمداً؛ فالنص الطويل يُقرأ فيها ملتفًا.
       * عند وجود تفاصيل نقترح الصفحة المنفردة الأكبر التي تُظهر النص كاملًا.
       */
      var fullPage = '';
      if (product.has_details && global.App.Router && global.App.Router.href) {
        fullPage = '<a class="sheet-more" href="' +
          UI.escape(global.App.Router.href('product/' + product.id)) +
          '" data-go="product/' + product.id + '">عرض التفاصيل الكاملة في صفحة أكبر ↗</a>';
      }

      body.innerHTML = media +
        '<h3 class="sheet-title">' + UI.escape(product.name) + '</h3>' +
        (global.App.Store.settings.show_prices === false ? '' :
          '<p class="sheet-price">' + UI.escape(product.price) + '</p>') +
        (tagHtml ? '<div class="sheet-tags">' + tagHtml + '</div>' : '') +
        (product.description ? '<p class="sheet-desc">' + UI.escape(product.description) + '</p>' : '') +
        (details ? '<dl class="detail-list">' + details + '</dl>' : '') +
        fullPage;

      UI.setHidden(footer, !global.App.Store.hasOrdering() || product.available === false);
      if (qty) qty.textContent = '1';
    },

    /**
     * صفحة المنتج المنفردة: نفس محتوى النافذة لكن بمساحة أكبر للنص الطويل.
     * تُفتح من رابط product/{id} ومن رابط «عرض التفاصيل الكاملة» داخل النافذة.
     */
    renderProductPage: function (product) {
      var page = $('product-page');
      if (!page) return;

      if (!product) {
        page.innerHTML = '<div class="notice">جارٍ تحميل ' +
          UI.escape(global.App.Store.productSingular() || 'المنتج') + '…</div>';
        return;
      }

      var store = global.App.Store;

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

      var ordering = store.hasOrdering() && product.available !== false;
      var actions = '<div class="product-page-actions">' +
        (ordering
          ? '<div class="stepper">' +
              '<button type="button" class="step-btn" data-page-step="-1" aria-label="إنقاص الكمية">−</button>' +
              '<span class="step-value" id="page-qty">1</span>' +
              '<button type="button" class="step-btn" data-page-step="1" aria-label="زيادة الكمية">+</button>' +
            '</div>' +
            '<button class="btn btn-primary" id="page-add" type="button">أضف إلى السلة</button>'
          : '') +
        '<a class="btn btn-ghost" href="' +
          UI.escape(global.App.Router && global.App.Router.href ? global.App.Router.href('products') : '#') +
          '" data-go="products">عودة إلى ' + UI.escape(store.productPlural()) + '</a>' +
      '</div>';

      page.innerHTML = media +
        '<div class="product-page-panel">' +
          '<h2 class="product-page-title">' + UI.escape(product.name) + '</h2>' +
          (store.settings.show_prices === false ? '' :
            '<p class="product-page-price">' + UI.escape(product.price) + '</p>') +
          (tagHtml ? '<div class="sheet-tags">' + tagHtml + '</div>' : '') +
          (product.description ? '<p class="product-page-desc">' + UI.escape(product.description) + '</p>' : '') +
          (details ? '<dl class="detail-list">' + details + '</dl>' : '') +
        '</div>' +
        actions;
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