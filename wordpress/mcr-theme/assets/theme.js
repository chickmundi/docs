/* Marché Chemin-du-Roy theme script (no jQuery needed). */
(function () {
  'use strict';
  var C = window.MCR || { ajax: '/?wc-ajax=%%endpoint%%', lang: 'fr', i18n: {} };
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  // ---------- Toast ----------
  function toast(msg) {
    var el = $('#toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'toast';
      el.setAttribute('role', 'status');
      document.body.appendChild(el);
    }
    el.textContent = msg;
    el.classList.add('is-visible');
    clearTimeout(el._t);
    el._t = setTimeout(function () { el.classList.remove('is-visible'); }, 2600);
  }
  function bump(sel) {
    $$(sel).forEach(function (el) { el.classList.remove('bump'); void el.offsetWidth; el.classList.add('bump'); });
  }

  // ---------- Category drawer ----------
  var drawer = $('#drawer');
  var openers = $$('[data-drawer-open]');
  var lastFocus = null;
  function setDrawer(open) {
    if (!drawer) return;
    if (open) {
      lastFocus = document.activeElement;
      drawer.hidden = false;
      requestAnimationFrame(function () { drawer.classList.add('is-open'); });
      var f = $('summary, a', drawer);
      if (f) f.focus();
    } else {
      drawer.classList.remove('is-open');
      setTimeout(function () { drawer.hidden = true; }, 250);
      if (lastFocus) lastFocus.focus();
    }
    document.body.classList.toggle('no-scroll', open);
    openers.forEach(function (b) { b.setAttribute('aria-expanded', String(open)); });
  }
  openers.forEach(function (b) { b.addEventListener('click', function () { setDrawer(true); }); });
  $$('[data-drawer-close]').forEach(function (b) { b.addEventListener('click', function () { setDrawer(false); }); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && drawer && !drawer.hidden) setDrawer(false); });

  // Mobile "Recherche": focus the search field.
  var jump = $('[data-search-jump]');
  if (jump) jump.addEventListener('click', function (e) {
    var q = $('#q');
    if (!q) return;
    e.preventDefault();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    q.focus();
  });

  // ---------- Reveal on scroll ----------
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    $$('[data-reveal]').forEach(function (el) { io.observe(el); });
  } else {
    $$('[data-reveal]').forEach(function (el) { el.classList.add('is-in'); });
  }

  // ---------- Shop sidebar ----------
  var side = $('[data-side]');
  if (side) {
    var mq = window.matchMedia('(min-width: 1000px)');
    var sync = function () { side.open = mq.matches; };
    sync();
    if (mq.addEventListener) mq.addEventListener('change', sync);
  }
  $$('[data-side-exp]').forEach(function (b) {
    b.addEventListener('click', function () {
      var li = b.closest('li');
      var open = b.getAttribute('aria-expanded') !== 'true';
      b.setAttribute('aria-expanded', String(open));
      li.classList.toggle('is-open', open);
      var kids = $('.side__kids', li);
      if (kids) kids.hidden = !open;
    });
  });

  // ---------- Wishlist (saved in this browser) ----------
  var WKEY = 'mcr-wishlist-v1';
  var wishMem = [];
  function getWish() { try { return JSON.parse(localStorage.getItem(WKEY) || '[]'); } catch (e) { return wishMem; } }
  function saveWish(items) {
    wishMem = items;
    try { localStorage.setItem(WKEY, JSON.stringify(items)); } catch (e) {}
    renderWish();
    bump('[data-wish-count]');
  }
  function renderWish() {
    var items = getWish();
    var ids = items.map(function (i) { return i.id; });
    $$('[data-wish-count]').forEach(function (el) { el.textContent = items.length; el.hidden = !items.length; });
    $$('[data-wish]').forEach(function (b) {
      var on = ids.indexOf(JSON.parse(b.getAttribute('data-wish')).id) !== -1;
      b.setAttribute('aria-pressed', String(on));
      b.setAttribute('aria-label', on ? C.i18n.remove : C.i18n.add);
    });
    var list = $('[data-wish-list]');
    if (list) {
      var empty = $('[data-wish-empty]');
      if (empty) empty.hidden = items.length > 0;
      list.innerHTML = items.map(function (i) {
        var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
        return '<li class="grid__item"><article class="card"><button class="card__wish" type="button" aria-pressed="true" data-wish=\'' + esc(JSON.stringify(i)).replace(/'/g, '&#39;') + '\'>♥</button>' +
          '<a class="card__media" href="' + esc(i.url) + '">' + (i.image ? '<img src="' + esc(i.image) + '" alt="" loading="lazy">' : '') + '</a>' +
          '<div class="card__body"><h3 class="card__title"><a href="' + esc(i.url) + '">' + esc(i.name) + '</a></h3><p class="price">' + esc(i.price) + '</p>' +
          '<a class="btn card__btn" href="' + esc(i.url) + '">' + (C.lang === 'en' ? 'View' : 'Voir') + '</a></div></article></li>';
      }).join('');
    }
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-wish]');
    if (!b) return;
    e.preventDefault();
    var item = JSON.parse(b.getAttribute('data-wish'));
    // Fill in name, link, price and image from the card or the product page.
    if (!item.name) {
      var card = b.closest('.card');
      var scope = card || document.querySelector('.product.type-product') || document;
      var link = card ? $('.card__title a', card) : null;
      var img = card ? $('.card__media img', card) : $('.woocommerce-product-gallery img', scope);
      var price = card ? $('.price', card) : $('.summary .price', scope);
      item.name = link ? link.textContent.trim() : (($('.product_title', scope) || {}).textContent || '').trim();
      item.url = link ? link.href : location.href.split('?')[0];
      item.price = price ? price.textContent.replace(/\s+/g, ' ').trim() : '';
      item.image = img ? (img.currentSrc || img.src) : '';
    }
    var items = getWish();
    var exists = items.some(function (i) { return i.id === item.id; });
    saveWish(exists ? items.filter(function (i) { return i.id !== item.id; }) : [item].concat(items));
    b.classList.remove('bump'); void b.offsetWidth; b.classList.add('bump');
  });
  window.addEventListener('storage', function (e) { if (e.key === WKEY) renderWish(); });
  renderWish();

  // ---------- Add to cart from product cards (no page reload) ----------
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-add-to-cart]');
    if (!a) return;
    e.preventDefault();
    a.classList.add('loading');
    var body = new URLSearchParams({ product_id: a.getAttribute('data-add-to-cart'), quantity: '1' });
    fetch(C.ajax.replace('%%endpoint%%', 'add_to_cart'), { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        a.classList.remove('loading');
        if (!j || j.error) { window.location.href = a.href; return; }
        var frag = j.fragments || {};
        var html = frag['.count[data-cart-count]'];
        if (html) {
          var n = (html.match(/>(\d+)</) || [])[1] || '0';
          $$('[data-cart-count]').forEach(function (el) { el.textContent = n; el.hidden = n === '0'; });
          bump('[data-cart-count]');
        }
        toast('« ' + a.getAttribute('data-name') + ' » ' + (C.i18n.added || 'ajouté au panier'));
      })
      .catch(function () { window.location.href = a.href; });
  });

  // ---------- Order this product on WhatsApp ----------
  var waBtn = $('[data-wa-order]');
  if (waBtn) waBtn.addEventListener('click', function () {
    var form = $('form.cart');
    var qty = form && $('input.qty', form) ? $('input.qty', form).value : '1';
    var opts = [];
    var missing = false;
    if (form) $$('.variations select', form).forEach(function (s) {
      var label = (s.closest('tr') && $('label', s.closest('tr'))) ? $('label', s.closest('tr')).textContent.trim() : s.name.replace('attribute_', '');
      if (!s.value) missing = true;
      else opts.push(label + ' : ' + s.options[s.selectedIndex].text);
    });
    if (missing) { toast(C.lang === 'en' ? 'Choose an option first.' : 'Choisissez une option avant de commander.'); return; }
    var priceEl = form && $('.single_variation .price .amount, .single_variation .price ins .amount', form);
    var price = priceEl ? priceEl.textContent.trim() : '';
    var en = C.lang === 'en';
    var lines = [
      en ? 'Hello, I would like to order:' : 'Bonjour, je souhaite commander :',
      '',
      qty + ' × ' + waBtn.getAttribute('data-name') + (opts.length ? ' (' + opts.join(', ') + ')' : '') + (price ? ' : ' + price : ''),
      waBtn.getAttribute('data-url'),
      '',
      en ? 'Delivery or in-store pickup: ' : 'Livraison ou retrait en magasin : ',
      en ? 'My address: ' : 'Mon adresse : ',
    ];
    window.open('https://wa.me/' + waBtn.getAttribute('data-wa-number') + '?text=' + encodeURIComponent(lines.join('\n')), '_blank', 'noopener');
  });
})();
