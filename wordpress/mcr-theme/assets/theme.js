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
        return '<li class="pgrid__item"><article class="pcard"><div class="pcard__media"><a href="' + esc(i.url) + '" tabindex="-1">' + (i.image ? '<img src="' + esc(i.image) + '" alt="" loading="lazy">' : '') + '</a>' +
          '<button class="pcard__wish" type="button" aria-pressed="true" data-wish=\'' + esc(JSON.stringify(i)).replace(/'/g, '&#39;') + '\'><svg class="icon" width="18" height="18" aria-hidden="true"><use href="#i-heart"/></svg></button></div>' +
          '<div class="pcard__body"><h3 class="pcard__title"><a href="' + esc(i.url) + '">' + esc(i.name) + '</a></h3>' +
          '<div class="pcard__foot"><span class="pcard__price">' + esc(i.price) + '</span><a class="pcard__add pcard__add--ghost" href="' + esc(i.url) + '"><span>' + (C.lang === 'en' ? 'View' : 'Voir') + '</span></a></div></div></article></li>';
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
      var card = b.closest('.pcard');
      var scope = card || document.querySelector('.product.type-product') || document;
      var link = card ? $('.pcard__title a', card) : null;
      var img = card ? $('.pcard__media img', card) : $('.woocommerce-product-gallery img', scope);
      var price = card ? $('.pcard__price', card) : $('.summary .price', scope);
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
        a.classList.add('added');
        setTimeout(function () { a.classList.remove('added'); }, 1600);
        toast('« ' + a.getAttribute('data-name') + ' » ' + (C.i18n.added || 'ajouté au panier'));
      })
      .catch(function () { window.location.href = a.href; });
  });


  // ---------- Cart: quantity steppers, automatic update ----------
  var cartTimer = null;
  function cartUpdateSoon() {
    clearTimeout(cartTimer);
    cartTimer = setTimeout(function () {
      var btn = $('.woocommerce-cart-form [name="update_cart"]');
      if (!btn) return;
      btn.disabled = false;
      btn.removeAttribute('aria-disabled');
      btn.click();
    }, 450);
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.cartx__step');
    if (!b) return;
    e.preventDefault();
    var input = $('input.qty', b.closest('[data-stepper]'));
    if (!input) return;
    var min = parseFloat(input.min || '0') || 0;
    var max = parseFloat(input.max || '') || Infinity;
    var step = parseFloat(input.step || '1') || 1;
    var v = (parseFloat(input.value) || 0) + step * parseFloat(b.getAttribute('data-step'));
    v = Math.max(min, Math.min(max, v));
    if (String(v) === input.value) return;
    input.value = v;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    b.closest('.cartx__item').classList.add('is-busy');
    cartUpdateSoon();
  });
  document.addEventListener('change', function (e) {
    if (e.target.matches && e.target.matches('.cartx input.qty') && e.isTrusted) cartUpdateSoon();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.matches && e.target.matches('.cartx input.qty')) { e.preventDefault(); cartUpdateSoon(); }
  });

  // After the cart refreshes, update the item counts in the header and bottom bar.
  if (window.jQuery) window.jQuery(document.body).on('updated_cart_totals updated_wc_div', function () {
    var h = $('.cartx__head h2 span');
    var n = h ? ((h.textContent.match(/\d+/) || [])[0] || '0') : '0';
    $$('[data-cart-count]').forEach(function (el) { el.textContent = n; el.hidden = n === '0'; });
    $$('.cartx__item.is-busy').forEach(function (el) { el.classList.remove('is-busy'); });
  });


  // ---------- Mega menu: touch and keyboard ----------
  var hoverable = window.matchMedia('(hover: hover)').matches;
  $$('.has-mega').forEach(function (li) {
    var link = li.querySelector(':scope > a');
    if (!link) return;
    link.setAttribute('aria-expanded', 'false');
    link.addEventListener('click', function (e) {
      if (hoverable || li.classList.contains('is-open')) return;
      e.preventDefault();
      $$('.has-mega.is-open').forEach(function (o) { o.classList.remove('is-open'); });
      li.classList.add('is-open');
      link.setAttribute('aria-expanded', 'true');
    });
    li.addEventListener('mouseenter', function () { link.setAttribute('aria-expanded', 'true'); });
    li.addEventListener('mouseleave', function () { link.setAttribute('aria-expanded', 'false'); li.classList.remove('is-open'); });
  });
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.has-mega')) $$('.has-mega.is-open').forEach(function (o) { o.classList.remove('is-open'); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.activeElement && document.activeElement.closest('.has-mega');
    if (open) { var a = open.querySelector(':scope > a'); a.focus(); open.classList.remove('is-open'); a.blur(); a.focus(); }
  });

  // ---------- Tabs (popular products) ----------
  $$('[data-tabs]').forEach(function (list) {
    var tabs = $$('[role=tab]', list);
    function select(t, focus) {
      tabs.forEach(function (x) {
        var on = x === t;
        x.setAttribute('aria-selected', String(on));
        x.tabIndex = on ? 0 : -1;
        var pane = document.getElementById(x.getAttribute('aria-controls'));
        if (pane) {
          pane.hidden = !on;
          if (on) $$('[data-reveal]', pane).forEach(function (el) { el.classList.add('is-in'); });
        }
      });
      if (focus) t.focus();
    }
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { select(t); });
      t.addEventListener('keydown', function (e) {
        var k = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
        if (k) { e.preventDefault(); select(tabs[(i + k + tabs.length) % tabs.length], true); }
      });
    });
  });

  // ---------- Shop filters panel (phones and tablets) ----------
  var filters = $('[data-filters]');
  var fBackdrop = $('.filters__backdrop');
  function setFilters(open) {
    if (!filters) return;
    filters.classList.toggle('is-open', open);
    if (fBackdrop) fBackdrop.hidden = !open;
    document.body.classList.toggle('no-scroll', open);
    $$('[data-filters-open]').forEach(function (b) { b.setAttribute('aria-expanded', String(open)); });
    if (open) { var f = $('a, button, input', filters); if (f) f.focus(); }
  }
  $$('[data-filters-open]').forEach(function (b) { b.addEventListener('click', function () { setFilters(true); }); });
  $$('[data-filters-close]').forEach(function (b) { b.addEventListener('click', function () { setFilters(false); }); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && filters && filters.classList.contains('is-open')) setFilters(false); });

  // ---------- Search suggestions (real products, WooCommerce Store API) ----------
  var sForm = $('[data-search]');
  if (sForm && C.store && window.fetch) {
    var sInput = $('input[type=search]', sForm);
    var panel = $('.search__panel', sForm);
    var sTimer = null, sCtrl = null, sItems = [], sActive = -1, lastQ = '';
    var escH = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    var money = function (pr, v) {
      var n = parseInt(v, 10) / Math.pow(10, pr.currency_minor_unit || 2);
      var str = n.toFixed(pr.currency_minor_unit || 2).replace('.', pr.currency_decimal_separator || ',');
      return (pr.currency_prefix || '') + str + (pr.currency_suffix || '');
    };
    function closePanel() { panel.hidden = true; sInput.setAttribute('aria-expanded', 'false'); sActive = -1; }
    function showMsg(html) { panel.innerHTML = html; panel.hidden = false; sInput.setAttribute('aria-expanded', 'true'); }
    function render(list, q) {
      sItems = list;
      sActive = -1;
      var all = C.searchUrl + encodeURIComponent(q);
      if (!list.length) {
        showMsg('<p class="sug__msg">' + escH(C.i18n.noresult) + ' « ' + escH(q) + ' ».</p>');
        return;
      }
      showMsg(list.map(function (p, i) {
        var pr = p.prices || {};
        var price = pr.price ? (pr.sale_price && pr.regular_price !== pr.sale_price ? '<del>' + money(pr, pr.regular_price) + '</del>' : '') + money(pr, pr.price) : '';
        var img = p.images && p.images[0] ? '<img src="' + escH(p.images[0].thumbnail || p.images[0].src) + '" alt="" loading="lazy">' : '';
        var cat = p.categories && p.categories.length ? p.categories.filter(function (c) { return c.slug !== 'tout'; }).map(function (c) { return c.name; })[0] || '' : '';
        var div = document.createElement('div'); div.innerHTML = p.name; var name = div.textContent;
        return '<a class="sug" role="option" id="sug-' + i + '" href="' + escH(p.permalink) + '"><span class="sug__img">' + img + '</span><span><span class="sug__name">' + escH(name) + '</span><span class="sug__cat">' + escH(cat) + '</span></span><span class="sug__price">' + price + (p.is_in_stock ? '' : '<span class="sug__out">' + escH(C.i18n.out) + '</span>') + '</span></a>';
      }).join('') + '<a class="sug__all" href="' + escH(all) + '">' + escH(C.i18n.allresults) + ' →</a>');
    }
    sInput.addEventListener('input', function () {
      var q = sInput.value.trim();
      clearTimeout(sTimer);
      if (q.length < 2) { closePanel(); return; }
      sTimer = setTimeout(function () {
        if (q === lastQ && !panel.hidden) return;
        lastQ = q;
        if (sCtrl) sCtrl.abort();
        sCtrl = window.AbortController ? new AbortController() : null;
        showMsg('<p class="sug__load">' + escH(C.i18n.searching) + '</p>');
        fetch(C.store + 'products?per_page=6&search=' + encodeURIComponent(q), { signal: sCtrl && sCtrl.signal, headers: { Accept: 'application/json' } })
          .then(function (r) { return r.ok ? r.json() : []; })
          .then(function (list) { if (sInput.value.trim() === q) render(Array.isArray(list) ? list : [], q); })
          .catch(function (e) { if (e.name !== 'AbortError') closePanel(); });
      }, 220);
    });
    sInput.addEventListener('keydown', function (e) {
      var opts = $$('.sug', panel);
      if (panel.hidden || !opts.length) { if (e.key === 'Escape') closePanel(); return; }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        sActive = (sActive + (e.key === 'ArrowDown' ? 1 : -1) + opts.length) % opts.length;
        opts.forEach(function (o, i) { o.setAttribute('aria-selected', String(i === sActive)); });
        sInput.setAttribute('aria-activedescendant', opts[sActive].id);
        opts[sActive].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter' && sActive >= 0) {
        e.preventDefault();
        window.location.href = opts[sActive].href;
      } else if (e.key === 'Escape') {
        closePanel();
      }
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('[data-search]')) closePanel(); });
    sInput.addEventListener('focus', function () { if (sInput.value.trim().length >= 2 && panel.innerHTML) { panel.hidden = false; sInput.setAttribute('aria-expanded', 'true'); } });
  }


  // ---------- Product carousels (arrows; the deals row also slides by itself) ----------
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  $$('[data-carousel]').forEach(function (wrap) {
    var row = $('.prow', wrap);
    if (!row) return;
    var en = C.lang === 'en';
    var nav = document.createElement('div');
    nav.className = 'prow-nav';
    nav.innerHTML = '<button type="button" class="prow-nav__btn" data-dir="-1" aria-label="' + (en ? 'Previous products' : 'Produits précédents') + '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg></button>' +
      '<button type="button" class="prow-nav__btn" data-dir="1" aria-label="' + (en ? 'Next products' : 'Produits suivants') + '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></button>';
    wrap.insertBefore(nav, row);
    var prev = nav.children[0], next = nav.children[1];
    function step() { var li = row.querySelector('li'); if (!li) return row.clientWidth; var gap = parseFloat(getComputedStyle(row).columnGap) || 14; return li.getBoundingClientRect().width + gap; }
    function atEnd() { return row.scrollLeft + row.clientWidth >= row.scrollWidth - 4; }
    function update() {
      var scrollable = row.scrollWidth > row.clientWidth + 4;
      nav.hidden = !scrollable;
      prev.disabled = row.scrollLeft <= 4;
      next.disabled = atEnd();
    }
    function go(dir) { row.scrollBy({ left: dir * step(), behavior: reduceMotion ? 'auto' : 'smooth' }); }
    nav.addEventListener('click', function (e) { var b = e.target.closest('[data-dir]'); if (!b) return; pause(); go(+b.getAttribute('data-dir')); });
    row.addEventListener('scroll', function () { window.requestAnimationFrame(update); }, { passive: true });
    window.addEventListener('resize', update);
    update();

    // Auto-slide (deals): one card every 3.5 s, back to the start at the end.
    if (!wrap.hasAttribute('data-autoplay') || reduceMotion) return;
    var timer = null, visible = false, pausedUntil = 0;
    function pause() { pausedUntil = Date.now() + 8000; }
    function tick() {
      if (!visible || document.hidden || Date.now() < pausedUntil || row.matches(':hover') || wrap.contains(document.activeElement)) return;
      if (atEnd()) row.scrollTo({ left: 0, behavior: 'smooth' }); else go(1);
    }
    ['pointerdown', 'touchstart', 'wheel', 'keydown'].forEach(function (ev) { row.addEventListener(ev, pause, { passive: true }); });
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (es) { visible = es[0].isIntersecting; }, { threshold: 0.4 }).observe(row);
    } else { visible = true; }
    timer = setInterval(tick, 3500);
    wrap.classList.add('is-auto');
  });

})();
