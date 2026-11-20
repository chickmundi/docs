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

  function init() { attach('billing'); attach('shipping'); }
  $(init);
  $(document.body).on('updated_checkout', init);
})(jQuery);
