/* MCR checkout: Quebec address suggestions while typing (Photon / OpenStreetMap).
   Picking a suggestion fills street, city and postal code and sends the exact
   map position, so the delivery price uses the right distance. */
(function ($) {
  'use strict';
  var C = window.MCR_CHECKOUT || {};
  var strip = function (s) { return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); };
  var keyOf = function (street, city) { return strip(street + '|' + city).replace(/[^a-z0-9|]+/g, ''); };

  function suggest(q, signal) {
    var p = new URLSearchParams({ q: q, limit: '10', lang: C.lang === 'en' ? 'en' : 'fr', lat: String(C.lat), lon: String(C.lon) });
    p.append('layer', 'house');
    p.append('layer', 'street');
    return fetch('https://photon.komoot.io/api/?' + p, { signal: signal, headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) {
        var typed = (q.trim().match(/^\d+[a-zA-Z]?/) || [])[0] || '';
        var seen = {}, out = [];
        (d.features || []).forEach(function (f) {
          if (out.length >= 5) return;
          var pr = f.properties || {};
          if (pr.countrycode && pr.countrycode !== 'CA') return;
          var streetName = pr.street || pr.name;
          if (!streetName) return;
          var street = [pr.housenumber || typed, streetName].filter(Boolean).join(' ');
          var city = pr.city || pr.town || pr.village || pr.district || pr.county || '';
          var k = keyOf(street, city);
          if (seen[k]) return;
          seen[k] = 1;
          out.push({ street: street, city: city, postcode: pr.postcode || '', state: pr.state || '', lat: f.geometry.coordinates[1], lon: f.geometry.coordinates[0] });
        });
        return out;
      });
  }

  function attach(prefix) {
    var input = document.getElementById(prefix + '_address_1');
    if (!input || input.dataset.mcrAc) return;
    input.dataset.mcrAc = '1';
    input.setAttribute('autocomplete', 'off');
    var wrap = input.parentNode;
    wrap.style.position = 'relative';
    var list = document.createElement('ul');
    list.className = 'mcr-ac';
    list.setAttribute('role', 'listbox');
    list.id = prefix + '_mcr_ac';
    list.hidden = true;
    wrap.appendChild(list);
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('aria-autocomplete', 'list');
    var items = [], active = -1, timer = null, ctrl = null;

    function close() { list.hidden = true; active = -1; input.setAttribute('aria-expanded', 'false'); }
    function render(msg) {
      list.innerHTML = '';
      if (msg) {
        var li = document.createElement('li');
        li.className = 'mcr-ac__msg';
        li.textContent = msg;
        list.appendChild(li);
      }
      items.forEach(function (s, i) {
        var li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.id = list.id + '_' + i;
        li.className = 'mcr-ac__item' + (i === active ? ' is-active' : '');
        li.innerHTML = '<strong></strong><span></span>';
        li.firstChild.textContent = s.street;
        li.lastChild.textContent = [s.city, s.postcode].filter(Boolean).join(', ');
        li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(s); });
        list.appendChild(li);
      });
      list.hidden = !msg && !items.length;
      input.setAttribute('aria-expanded', String(!list.hidden));
      input.setAttribute('aria-activedescendant', active >= 0 ? list.id + '_' + active : '');
    }
    function set(id, v) {
      var el = document.getElementById(prefix + '_' + id);
      if (el && v) { el.value = v; $(el).trigger('change'); }
    }
    function pick(s) {
      input.value = s.street;
      set('city', s.city);
      set('postcode', s.postcode);
      set('country', 'CA');
      set('state', 'QC');
      document.getElementById('mcr_lat').value = s.lat;
      document.getElementById('mcr_lon').value = s.lon;
      document.getElementById('mcr_place_key').value = keyOf(s.street, s.city);
      close();
      $(document.body).trigger('update_checkout');
    }
    input.addEventListener('input', function () {
      clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < 4) { items = []; close(); return; }
      timer = setTimeout(function () {
        if (ctrl) ctrl.abort();
        ctrl = window.AbortController ? new AbortController() : null;
        items = [];
        render(C.searching);
        var city = (document.getElementById(prefix + '_city') || {}).value || '';
        suggest(city && q.indexOf(',') === -1 ? q + ', ' + city : q, ctrl && ctrl.signal)
          .then(function (r) { items = r; active = -1; render(r.length ? '' : C.none); })
          .catch(function (e) { if (e.name !== 'AbortError') { items = []; close(); } });
      }, 280);
    });
    input.addEventListener('keydown', function (e) {
      if (list.hidden || !items.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        render('');
      } else if (e.key === 'Enter' && active >= 0) {
        e.preventDefault();
        pick(items[active]);
      } else if (e.key === 'Escape') {
        close();
      }
    });
    input.addEventListener('blur', function () { setTimeout(close, 150); });
  }


  // ---------- "Livraison ou retrait" cards (drive WooCommerce's shipping choice) ----------
  var KEY = 'mcr-mode';
  var desired = null;
  try { desired = sessionStorage.getItem(KEY); } catch (e) {}
  function methodInputs() { return Array.prototype.slice.call(document.querySelectorAll('#order_review input[name^="shipping_method"]')); }
  function isPickup(v) { return /^(mcr_pickup|local_pickup)/.test(v || ''); }
  function inputFor(mode) {
    return methodInputs().filter(function (i) { return mode === 'pickup' ? isPickup(i.value) : !isPickup(i.value); })[0] || null;
  }
  function labelText(input) {
    if (!input) return '';
    var l = input.id ? document.querySelector('label[for="' + input.id + '"]') : null;
    return (l || input.parentNode).textContent.replace(/\s+/g, ' ').trim();
  }
  function setMode(mode, fromUser) {
    var box = document.querySelector('[data-mcr-mode]');
    if (!box || !mode) return;
    desired = mode;
    try { sessionStorage.setItem(KEY, mode); } catch (e) {}
    box.querySelectorAll('[data-mode]').forEach(function (b) { b.setAttribute('aria-checked', String(b.getAttribute('data-mode') === mode)); });
    var hidden = box.querySelector('[data-mode-input]');
    if (hidden) hidden.value = mode;
    document.querySelectorAll('.mcr-addr').forEach(function (row) { row.classList.toggle('is-off', mode === 'pickup'); });
    var slots = document.querySelector('[data-mcr-slots]');
    if (slots) slots.hidden = mode !== 'pickup';
    var input = inputFor(mode);
    if (input && input.type === 'radio' && !input.checked) {
      input.checked = true;
      $(input).trigger('change');
    }
    refreshNote();
    if (fromUser && mode === 'delivery') {
      var a = document.getElementById('billing_address_1');
      if (a && !a.value) setTimeout(function () { a.focus(); }, 60);
    }
  }
  function refreshNote() {
    var box = document.querySelector('[data-mcr-mode]');
    if (!box) return;
    var note = box.querySelector('[data-mode-note]');
    var priceEl = box.querySelector('[data-mode-price]');
    var del = inputFor('delivery');
    var reviewNote = document.querySelector('#order_review .mcr-ship-note');
    if (priceEl && del) {
      var t = labelText(del).replace(/^(Livraison|Delivery)\s*/, '');
      priceEl.textContent = t.replace(/^\((.*?)\)\s*:\s*/, '$1 · ').replace(/^(gratuite|free)\s*\((.*?)\)/i, function (m, a, km) { return km + ' · ' + (C.lang === 'en' ? 'free' : 'gratuite'); });
    }
    var msg = '';
    if (desired === 'delivery' && !del) {
      var street = (document.getElementById('billing_address_1') || {}).value || '';
      msg = reviewNote ? reviewNote.textContent.trim() : (street ? (C.lang === 'en' ? 'Calculating the delivery price…' : 'Calcul du prix de livraison…') : (C.lang === 'en' ? 'Enter your address to see the delivery price.' : 'Entrez votre adresse pour voir le prix de livraison.'));
    }
    note.hidden = !msg;
    note.textContent = msg;
    note.classList.toggle('is-warn', !!reviewNote && !del);
  }
  $(document.body).on('click', '[data-mcr-mode] [data-mode]', function (e) {
    e.preventDefault();
    setMode(this.getAttribute('data-mode'), true);
  });
  $(document.body).on('updated_checkout', function () {
    if (!document.querySelector('[data-mcr-mode]')) return;
    if (!desired) {
      var checked = methodInputs().filter(function (i) { return i.type === 'hidden' || i.checked; })[0];
      desired = checked && isPickup(checked.value) ? 'pickup' : 'delivery';
    }
    setMode(desired, false);
  });

  // ---------- Pickup time slots ----------
  $(document.body).on('click', '[data-mcr-slots] [data-day]', function () {
    var box = this.closest('[data-mcr-slots]');
    var day = this.getAttribute('data-day');
    box.querySelectorAll('[data-day]').forEach(function (b) { b.setAttribute('aria-selected', String(b.getAttribute('data-day') === day)); });
    box.querySelectorAll('[data-day-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-day-panel') !== day; });
  });
  $(document.body).on('click', '[data-mcr-slots] [data-slot]', function () {
    var box = this.closest('[data-mcr-slots]');
    box.querySelectorAll('[data-slot]').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
    this.setAttribute('aria-pressed', 'true');
    box.querySelector('[data-slot-input]').value = this.getAttribute('data-slot');
    var chosen = box.querySelector('[data-slot-chosen]');
    if (chosen) { chosen.hidden = false; chosen.textContent = (C.lang === 'en' ? 'Pickup: ' : 'Retrait : ') + this.getAttribute('data-slot-label'); }
    box.classList.remove('is-missing');
  });
  // Clear reminder if the customer tries to order without a time.
  $(document.body).on('checkout_error', function () {
    var box = document.querySelector('[data-mcr-slots]');
    if (box && !box.hidden && !box.querySelector('[data-slot-input]').value) { box.classList.add('is-missing'); box.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
  });

  function init() { attach('billing'); attach('shipping'); }
  $(init);
  $(document.body).on('updated_checkout', init);
})(jQuery);
