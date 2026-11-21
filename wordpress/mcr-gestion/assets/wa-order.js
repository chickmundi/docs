/* MCR: smart "Commander via WhatsApp" form (product page and cart).
   Collects name, phone, delivery address (with its real fee) or a pickup time, saves the
   order on the site, then opens WhatsApp with the complete message. */
(function () {
  'use strict';
  var C = window.MCR_WA;
  var sheet = document.getElementById('wa-sheet');
  if (!C || !sheet || !sheet.showModal) return;
  var form = sheet.querySelector('[data-wa-form]');
  var $ = function (s, r) { return (r || form).querySelector(s); };
  var f = function (n) { return form.elements[n]; };
  var t = C.t;
  var source = 'product', price = 0, variationPrice = null;

  function money(n) {
    var c = C.currency, d = Number(n).toFixed(c.decimals).split('.');
    d[0] = d[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.thousand);
    return c.format.replace('%1$s', c.symbol).replace('%2$s', d.join(c.dec)).replace(/ /g, ' ');
  }

  // ---------- What is being ordered ----------
  function productRequest() {
    var cart = document.querySelector('form.cart');
    var btn = document.querySelector('[data-wa-sheet="product"]');
    var req = { product_id: btn ? btn.getAttribute('data-product-id') : '', qty: '1', variation_id: '', attributes: {} };
    if (!cart) return req;
    var q = cart.querySelector('input.qty');
    if (q) req.qty = q.value || '1';
    var v = cart.querySelector('input[name="variation_id"]');
    if (cart.classList.contains('variations_form')) {
      if (!v || !v.value || v.value === '0') return null;
      req.variation_id = v.value;
      cart.querySelectorAll('.variations select').forEach(function (s) { req.attributes[s.name] = s.value; });
    }
    return req;
  }
  if (window.jQuery) {
    window.jQuery(document).on('found_variation', 'form.variations_form', function (e, v) { variationPrice = v && v.display_price; });
    window.jQuery(document).on('reset_data', 'form.variations_form', function () { variationPrice = null; });
  }
  function subtotal() {
    if (source === 'cart') return Number(C.cartSubtotal) || 0;
    var r = productRequest();
    var unit = variationPrice != null ? Number(variationPrice) : price;
    return r ? unit * (parseInt(r.qty, 10) || 1) : 0;
  }

  // ---------- Opening ----------
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-wa-sheet]');
    if (!btn) return;
    e.preventDefault();
    source = btn.getAttribute('data-wa-sheet');
    price = parseFloat(btn.getAttribute('data-price')) || 0;
    if (source === 'product' && !productRequest()) {
      var msg = btn.parentNode.querySelector('.wa-inline-err');
      if (!msg) { msg = document.createElement('p'); msg.className = 'wa-inline-err'; msg.setAttribute('role', 'alert'); btn.insertAdjacentElement('afterend', msg); }
      msg.textContent = t.variation;
      var sel = document.querySelector('form.variations_form .variations select');
      if (sel) sel.focus();
      setTimeout(function () { msg.remove(); }, 5000);
      return;
    }
    open(btn);
  });

  function open(btn) {
    showStep('form');
    error('');
    var s = $('[data-wa-summary]');
    if (source === 'cart') {
      s.textContent = btn.getAttribute('data-summary') || money(subtotal());
    } else {
      var r = productRequest();
      s.textContent = (r.qty || 1) + ' × ' + (btn.getAttribute('data-name') || '') + (subtotal() ? ' — ' + money(subtotal()) : '');
    }
    sheet.showModal();
    document.documentElement.classList.add('wa-open');
    refreshQuote();
    setTimeout(function () { (f('name').value ? (f('phone').value ? $('[data-wa-send]') : f('phone')) : f('name')).focus(); }, 60);
  }
  function close() { sheet.close(); }
  sheet.addEventListener('close', function () { document.documentElement.classList.remove('wa-open'); });
  sheet.addEventListener('click', function (e) { if (e.target === sheet || e.target.closest('[data-wa-close]')) close(); });

  // ---------- Remembered details ----------
  var saved = {};
  try { saved = JSON.parse(localStorage.getItem('mcr_wa') || '{}') || {}; } catch (e) { saved = {}; }
  var pre = {};
  try { pre = JSON.parse(sheet.getAttribute('data-prefill') || '{}') || {}; } catch (e) { pre = {}; }
  ['name', 'phone', 'street', 'city', 'postcode', 'lat', 'lon'].forEach(function (k) { if (f(k)) f(k).value = saved[k] || pre[k] || ''; });
  if (saved.mode === 'pickup') f('mode').value = 'pickup';
  function remember() {
    var o = {};
    ['name', 'phone', 'street', 'city', 'postcode', 'lat', 'lon', 'mode'].forEach(function (k) { o[k] = f(k) ? f(k).value : ''; });
    try { localStorage.setItem('mcr_wa', JSON.stringify(o)); } catch (e) { /* private mode */ }
  }

  // ---------- Delivery or pickup ----------
  function mode() { return f('mode').value; }
  function syncMode() {
    $('[data-wa-delivery]').hidden = mode() !== 'delivery';
    $('[data-wa-pickup]').hidden = mode() !== 'pickup';
    error('');
  }
  form.addEventListener('change', function (e) { if (e.target.name === 'mode') { syncMode(); refreshQuote(); } });
  syncMode();

  // ---------- Address suggestions (Photon / OpenStreetMap) and delivery price ----------
  var input = $('[data-wa-address]'), list = $('#wa-ac', sheet), ctrl = null, timer = null, items = [], active = -1;
  function suggest(q) {
    if (ctrl) ctrl.abort();
    ctrl = window.AbortController ? new AbortController() : null;
    var p = new URLSearchParams({ q: q, limit: '10', lang: C.lang, lat: String(C.lat), lon: String(C.lon) });
    p.append('layer', 'house'); p.append('layer', 'street');
    return fetch('https://photon.komoot.io/api/?' + p, { signal: ctrl && ctrl.signal }).then(function (r) { return r.json(); }).then(function (d) {
      var typed = (q.trim().match(/^\d+[a-zA-Z]?/) || [])[0] || '', seen = {}, out = [];
      (d.features || []).forEach(function (ft) {
        var pr = ft.properties || {};
        if (out.length >= 5 || (pr.countrycode && pr.countrycode !== 'CA')) return;
        var name = pr.street || pr.name;
        if (!name) return;
        var street = [pr.housenumber || typed, name].filter(Boolean).join(' ');
        var city = pr.city || pr.town || pr.village || pr.district || pr.county || '';
        var k = (street + '|' + city).toLowerCase();
        if (seen[k]) return;
        seen[k] = 1;
        out.push({ street: street, city: city, postcode: pr.postcode || '', lat: ft.geometry.coordinates[1], lon: ft.geometry.coordinates[0] });
      });
      return out;
    });
  }
  function renderList() {
    list.innerHTML = items.map(function (it, i) {
      return '<li role="option" id="wa-ac-' + i + '" data-i="' + i + '"' + (i === active ? ' aria-selected="true"' : '') + '><b></b><small></small></li>';
    }).join('');
    items.forEach(function (it, i) {
      var li = list.children[i];
      li.querySelector('b').textContent = it.street;
      li.querySelector('small').textContent = [it.city, it.postcode].filter(Boolean).join(' ');
    });
    list.hidden = !items.length;
    input.setAttribute('aria-expanded', items.length ? 'true' : 'false');
    if (active >= 0) input.setAttribute('aria-activedescendant', 'wa-ac-' + active); else input.removeAttribute('aria-activedescendant');
  }
  function pick(it) {
    f('street').value = it.street; f('city').value = it.city; f('postcode').value = it.postcode;
    f('lat').value = it.lat; f('lon').value = it.lon;
    items = []; active = -1; renderList();
    refreshQuote();
  }
  input.addEventListener('input', function () {
    f('lat').value = ''; f('lon').value = ''; f('city').value = ''; f('postcode').value = '';
    clearTimeout(timer);
    var q = input.value.trim();
    quote('');
    if (q.length < 4) { items = []; renderList(); return; }
    timer = setTimeout(function () {
      suggest(q).then(function (out) { items = out; active = -1; renderList(); }).catch(function () {});
    }, 280);
  });
  input.addEventListener('keydown', function (e) {
    if (!items.length) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length; renderList(); }
    else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); pick(items[active]); }
    else if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); items = []; renderList(); }
  });
  input.addEventListener('blur', function () { setTimeout(function () { items = []; renderList(); if (!f('lat').value && input.value.trim().length >= 6) refreshQuote(); }, 200); });
  list.addEventListener('mousedown', function (e) { var li = e.target.closest('li'); if (li) { e.preventDefault(); pick(items[+li.getAttribute('data-i')]); } });

  var quoteOk = null;
  function quote(text, bad) {
    var el = $('[data-wa-quote]');
    el.textContent = text;
    el.classList.toggle('is-bad', !!bad);
  }
  function refreshQuote() {
    if (mode() !== 'delivery') return;
    var lat = f('lat').value, lon = f('lon').value, street = f('street').value.trim();
    if (!street) { quote(''); return; }
    quote(t.searching);
    var url = C.quote + (lat && lon ? '&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lon) : '&address=' + encodeURIComponent([street, f('city').value].filter(Boolean).join(', ')));
    fetch(url).then(function (r) { return r.json(); }).then(function (r) {
      if (!r.success) { quoteOk = false; quote(t.notfound, true); return; }
      var d = r.data;
      quoteOk = d.ok;
      if (!d.ok) { quote(t.far.replace('%s', d.km_label), true); return; }
      if (d.free_eligible && subtotal() >= d.free_from) quote('✓ ' + t.free + ' · ' + d.km_label);
      else quote(t.fee.replace('%s', money(d.fee)) + ' · ' + d.km_label + (d.free_eligible ? ' (' + t.freeFrom.replace('%s', money(d.free_from)) + ')' : ''));
    }).catch(function () { quote(''); });
  }

  // ---------- Sending ----------
  function error(msg) { var e = $('[data-wa-error]'); e.textContent = msg; e.hidden = !msg; }
  function showStep(s) { form.querySelectorAll('[data-wa-step]').forEach(function (el) { el.hidden = el.getAttribute('data-wa-step') !== s; }); }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    error('');
    var send = $('[data-wa-send]');
    if (!f('name').value.trim()) { f('name').focus(); return error(f('name').closest('label').textContent.trim()); }
    if (f('phone').value.replace(/\D/g, '').length < 10) { f('phone').focus(); return error(f('phone').closest('label').querySelector('span').textContent.trim() + ' ?'); }
    if (mode() === 'delivery' && f('street').value.trim().length < 4) { f('street').focus(); return error(t.pick); }
    if (mode() === 'delivery' && quoteOk === false) { return error($('[data-wa-quote]').textContent); }
    var body = new FormData(form);
    body.append('source', source);
    body.append('lang', C.lang);
    if (source === 'product') {
      var r = productRequest();
      if (!r) return error(t.variation);
      body.append('product_id', r.product_id); body.append('qty', r.qty); body.append('variation_id', r.variation_id);
      Object.keys(r.attributes).forEach(function (k) { body.append('attributes[' + k + ']', r.attributes[k]); });
    }
    send.disabled = true;
    var label = send.innerHTML;
    send.textContent = t.sending;
    fetch(C.endpoint, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (res) { return res.json(); }).then(function (res) {
      send.disabled = false; send.innerHTML = label;
      if (!res || !res.success) { error((res && res.data && res.data.message) || t.error); return; }
      remember();
      var d = res.data;
      $('[data-wa-done-title]').textContent = d.ref + ' · ' + d.total;
      $('[data-wa-open]').href = d.wa;
      $('[data-wa-summary-link]').href = d.summary;
      showStep('done');
      setTimeout(function () { window.location.href = d.wa; }, 700);
    }).catch(function () { send.disabled = false; send.innerHTML = label; error(t.error); });
  });
})();
