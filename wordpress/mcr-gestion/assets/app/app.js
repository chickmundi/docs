/* MCR Gestion: the store team's app (orders, products, promotions, shop). Installable, push notifications. */
(function () {
  'use strict';
  var C = window.MCR_APP || {};
  var CAPS = C.caps || { orders: true };
  var app = document.getElementById('app');
  var STATUS_TONE = { 'mcr-whatsapp': 'green', processing: 'blue', 'on-hold': 'orange', 'mcr-preparing': 'orange', 'mcr-ready': 'green', 'mcr-out': 'purple', completed: 'gray', cancelled: 'red', refunded: 'red', failed: 'red', pending: 'gray' };

  // ---------- Helpers ----------
  function load(k, d) { try { return JSON.parse(localStorage.getItem(k)) || d; } catch (e) { return d; } }
  function save(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(v) { var n = Number(v || 0); return n.toLocaleString('fr-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' $'; }
  function num(v) { var n = parseFloat(String(v == null ? '' : v).replace(',', '.')); return isNaN(n) ? 0 : n; }
  function ago(ts) {
    var s = Math.max(0, Date.now() / 1000 - ts);
    if (s < 60) return 'À l’instant';
    if (s < 3600) return 'Il y a ' + Math.floor(s / 60) + ' min';
    if (s < 86400) return 'Il y a ' + Math.floor(s / 3600) + ' h';
    var d = new Date(ts * 1000);
    return d.toLocaleDateString('fr-CA', { day: 'numeric', month: 'short' }) + ', ' + d.toLocaleTimeString('fr-CA', { hour: '2-digit', minute: '2-digit' });
  }
  function fmtDate(ymd, withDay) {
    if (!ymd) return '';
    var d = new Date(ymd + 'T12:00:00');
    return d.toLocaleDateString('fr-CA', withDay ? { weekday: 'short', day: 'numeric', month: 'short' } : { day: 'numeric', month: 'long' });
  }
  function fmtWhen(ts) {
    var d = new Date(ts * 1000), now = new Date();
    var same = d.toDateString() === now.toDateString();
    var tom = new Date(now.getTime() + 86400000).toDateString() === d.toDateString();
    var t = d.toLocaleTimeString('fr-CA', { hour: '2-digit', minute: '2-digit' });
    return (same ? 'Aujourd’hui' : tom ? 'Demain' : d.toLocaleDateString('fr-CA', { weekday: 'short', day: 'numeric', month: 'short' })) + ' à ' + t;
  }
  function tap() { if (navigator.vibrate) navigator.vibrate(8); }
  function addDays(n) { var d = new Date(); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); }

  var P = {
    bag: '<path d="M6 7h12l1 13H5L6 7Z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/>',
    box: '<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/>',
    tag: '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9-9-9Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
    store: '<path d="M4 10v10h16V10"/><path d="M2 10 4.5 4h15L22 10a3 3 0 0 1-5 2 3 3 0 0 1-5 0 3 3 0 0 1-5 0 3 3 0 0 1-5-2Z"/><path d="M10 20v-5h4v5"/>',
    back: '<path d="m15 5-7 7 7 7"/>',
    chev: '<path d="m9 5 7 7-7 7"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    check: '<path d="m5 12 5 5L20 7"/>',
    phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>',
    chat: '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
    map: '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
    truck: '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.6a1 1 0 0 0-.2-.6l-3.5-4.4A1 1 0 0 0 17.5 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
    bolt: '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/>',
    star: '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9L12 3Z"/>',
    camera: '<path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13.5" r="3.5"/>',
    bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
    megaphone: '<path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1Z"/><path d="M15 9a4 4 0 0 1 0 6"/><path d="M18 6a8 8 0 0 1 0 12"/>',
    globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    wp: '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 9h8M8 13h8M8 17h5"/>',
    out: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
    alert: '<path d="M12 3 2 20h20L12 3Z"/><path d="M12 10v4M12 17h.01"/>',
    eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    note: '<path d="M15.5 3H5a2 2 0 0 0-2 2v14c0 1.1.9 2 2 2h14a2 2 0 0 0 2-2V8.5L15.5 3Z"/><path d="M15 3v6h6"/>',
    ext: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    photo: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>',
  };
  function ic(n, s, w) { return '<svg class="i" width="' + (s || 22) + '" height="' + (s || 22) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + (w || 2) + '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + P[n] + '</svg>'; }

  // ---------- API ----------
  function api(path, opts) {
    opts = opts || {};
    var headers = { 'X-WP-Nonce': C.nonce, Accept: 'application/json' };
    var body = opts.body;
    if (body && !(body instanceof FormData)) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
    return fetch(C.rest + path, { method: opts.method || (body ? 'POST' : 'GET'), credentials: 'same-origin', headers: headers, body: body, cache: 'no-store' })
      .then(function (r) {
        if (r.status === 401) { location.reload(); throw new Error('auth'); }
        return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error((j && j.message) || 'Erreur ' + r.status); return j; });
      });
  }

  // ---------- Tabs and navigation stacks ----------
  var TABS = [
    { id: 'orders', label: 'Commandes', icon: 'bag', on: CAPS.orders },
    { id: 'products', label: 'Produits', icon: 'box', on: CAPS.products },
    { id: 'promos', label: 'Promos', icon: 'tag', on: CAPS.shop && (CAPS.promotions || CAPS.flash) },
    { id: 'shop', label: 'Boutique', icon: 'store', on: true },
  ].filter(function (t) { return t.on; });
  var S = {
    tab: load('mcr-tab', TABS[0].id),
    stacks: {},
    seen: load('mcr-seen', {}),
    pushOn: false,
    cats: null,
    anim: '',
  };
  if (!TABS.some(function (t) { return t.id === S.tab; })) S.tab = TABS[0].id;
  TABS.forEach(function (t) { S.stacks[t.id] = [{ v: t.id }]; });
  function cur() { var s = S.stacks[S.tab]; return s[s.length - 1]; }
  function push(view) { tap(); cur().scroll = window.scrollY; S.stacks[S.tab].push(view); S.anim = 'push'; history.pushState({ d: S.stacks[S.tab].length }, ''); render(); }
  function pop(silent) {
    var s = S.stacks[S.tab];
    if (s.length < 2) return;
    s.pop(); S.anim = 'pop';
    if (!silent) render();
  }
  function goBack() { if (history.state && history.state.d) history.back(); else pop(); }
  window.addEventListener('popstate', function () { closeSheet(true); if (S.stacks[S.tab].length > 1) pop(); });
  function switchTab(id) {
    if (S.tab === id) { if (S.stacks[id].length > 1) { S.stacks[id] = [S.stacks[id][0]]; S.anim = 'pop'; render(); } else { window.scrollTo({ top: 0, behavior: 'smooth' }); } return; }
    tap(); cur().scroll = window.scrollY; S.tab = id; save('mcr-tab', id); S.anim = 'fade'; render();
  }

  // ---------- Rendering ----------
  var VIEWS = {};
  function render() {
    var v = cur();
    var spec = VIEWS[v.v](v);
    var depth = S.stacks[S.tab].length;
    var left = depth > 1 ? '<button class="nav__back" data-act="back">' + ic('back', 24, 2.4) + '<span>' + esc(spec.backLabel || 'Retour') + '</span></button>' : (spec.left || '');
    app.innerHTML =
      '<header class="nav' + (spec.large === false ? ' nav--small' : '') + '"><div class="nav__in"><div class="nav__l">' + left + '</div><div class="nav__t">' + esc(spec.title) + '</div><div class="nav__r">' + (spec.right || '') + '</div></div></header>' +
      '<main class="view ' + (S.anim ? 'anim-' + S.anim : '') + '">' + (spec.large === false ? '' : '<h1 class="large">' + esc(spec.title) + '</h1>') + spec.body + '</main>' +
      '<nav class="tabbar">' + TABS.map(function (t) {
        var badge = t.id === 'orders' && S.openCount ? '<b class="tabbar__badge">' + S.openCount + '</b>' : '';
        return '<button data-tabid="' + t.id + '"' + (S.tab === t.id ? ' aria-current="page"' : '') + '><span class="tabbar__i">' + ic(t.icon, 25, S.tab === t.id ? 2.3 : 1.8) + badge + '</span><span>' + t.label + '</span></button>';
      }).join('') + '</nav>';
    S.anim = '';
    window.scrollTo(0, v.scroll || 0);
    onScroll();
    if (spec.after) spec.after(v);
  }
  function refreshView() { var y = window.scrollY; cur().scroll = y; render(); }
  function onScroll() { var n = document.querySelector('.nav'); if (n) n.classList.toggle('is-scrolled', window.scrollY > 38); }
  window.addEventListener('scroll', onScroll, { passive: true });
  function loadInto(v, path, key) {
    if (v.loading) return;
    v.loading = true;
    api(path).then(function (d) { v[key || 'data'] = d; v.loading = false; v.err = ''; if (cur() === v) refreshView(); })
      .catch(function (e) { v.loading = false; v.err = e.message; if (cur() === v) refreshView(); });
  }
  function skel(n) { var h = ''; for (var i = 0; i < (n || 4); i++) h += '<div class="skel"></div>'; return '<div class="group">' + h + '</div>'; }
  function errBox(v) { return v.err ? '<div class="empty"><p>' + esc(v.err) + '</p><button class="btn btn--tint" data-act="retry">Réessayer</button></div>' : ''; }

  // UI pieces
  function group(title, rows, foot, hint) { return (title ? '<h2 class="ghead">' + title + '</h2>' : '') + '<div class="group">' + rows.join('') + '</div>' + (foot || hint ? '<p class="gfoot"' + (hint ? ' data-hint="' + hint + '"' : '') + '>' + (foot || '') + '</p>' : ''); }
  function row(o) {
    var tag = o.act || o.href ? (o.href ? 'a href="' + esc(o.href) + '"' + (o.ext ? ' target="_blank" rel="noopener"' : '') : 'button') : 'div';
    var close = tag.split(' ')[0];
    return '<' + tag + ' class="row' + (o.cls ? ' ' + o.cls : '') + '"' + (o.act ? ' data-act="' + o.act + '"' : '') + (o.id != null ? ' data-id="' + esc(o.id) + '"' : '') + '>' +
      (o.icon ? '<span class="row__ic row__ic--' + (o.tone || 'tint') + '">' + ic(o.icon, 18, 2.2) + '</span>' : '') +
      (o.img !== undefined ? '<span class="thumb">' + (o.img ? '<img src="' + esc(o.img) + '" alt="" loading="lazy">' : ic('photo', 20, 1.6)) + '</span>' : '') +
      '<span class="row__m"><span class="row__t">' + o.title + '</span>' + (o.sub ? '<span class="row__s">' + o.sub + '</span>' : '') + '</span>' +
      (o.value != null ? '<span class="row__v">' + o.value + '</span>' : '') + (o.chev ? ic('chev', 16, 2.4) : '') + '</' + close + '>';
  }
  function toggle(name, on, label, sub, icon, tone) {
    return '<label class="row">' + (icon ? '<span class="row__ic row__ic--' + (tone || 'tint') + '">' + ic(icon, 18, 2.2) + '</span>' : '') + '<span class="row__m"><span class="row__t">' + label + '</span>' + (sub ? '<span class="row__s">' + sub + '</span>' : '') + '</span><input type="checkbox" class="switch" data-f="' + name + '"' + (on ? ' checked' : '') + '></label>';
  }
  function field(name, label, val, opts) {
    opts = opts || {};
    return '<label class="row row--field"><span class="row__label">' + label + '</span><input data-f="' + name + '" value="' + esc(val == null ? '' : val) + '" type="' + (opts.type || 'text') + '"' + (opts.mode ? ' inputmode="' + opts.mode + '"' : '') + ' placeholder="' + esc(opts.ph || '') + '"' + (opts.dis ? ' disabled' : '') + (opts.min != null ? ' min="' + opts.min + '"' : '') + '>' + (opts.unit ? '<span class="row__unit">' + opts.unit + '</span>' : '') + '</label>';
  }
  function seg(name, val, items) { return '<div class="seg" role="tablist">' + items.map(function (i) { return '<button data-seg="' + name + '" data-val="' + i[0] + '"' + (String(val) === String(i[0]) ? ' aria-selected="true"' : '') + '>' + i[1] + '</button>'; }).join('') + '</div>'; }
  function stepper(name, val, min, max, step, unit) {
    return '<div class="row"><span class="row__m"><span class="row__t">' + unit[0] + '</span>' + (unit[1] ? '<span class="row__s">' + unit[1] + '</span>' : '') + '</span><span class="stepper"><button data-step="' + name + '" data-d="-' + (step || 1) + '" data-min="' + min + '" data-max="' + max + '" aria-label="Moins">−</button><output data-out="' + name + '">' + val + '</output><button data-step="' + name + '" data-d="' + (step || 1) + '" data-min="' + min + '" data-max="' + max + '" aria-label="Plus">+</button></span></div>';
  }
  function pill(text, tone) { return '<span class="pill pill--' + (tone || 'gray') + '">' + text + '</span>'; }
  function saveBtn(v, label) { return '<button class="nav__btn nav__btn--strong" data-act="save"' + (v.dirty ? '' : ' disabled') + '>' + (label || 'Enregistrer') + '</button>'; }
  function markDirty(v) { v.dirty = true; var b = document.querySelector('[data-act="save"]'); if (b) b.disabled = false; }

  // ---------- Toast (HUD) ----------
  var toastTimer = null;
  function toast(msg, tone) {
    var t = document.querySelector('.hud');
    if (!t) { t = document.createElement('div'); t.className = 'hud'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
    t.innerHTML = (tone === 'ok' ? ic('check', 18, 2.6) : tone === 'err' ? ic('alert', 18, 2.2) : '') + '<span>' + esc(msg) + '</span>';
    t.className = 'hud is-on' + (tone ? ' hud--' + tone : '');
    clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.classList.remove('is-on'); }, 2600);
  }

  // ---------- Sheets (bottom sheets and action sheets) ----------
  var sheet = null;
  function openSheet(html, opts) {
    closeSheet(true);
    opts = opts || {};
    var wrap = document.createElement('div');
    wrap.className = 'sheet' + (opts.action ? ' sheet--action' : '');
    wrap.innerHTML = '<div class="sheet__bg" data-sheet="close"></div><div class="sheet__panel" role="dialog" aria-modal="true">' + (opts.action ? '' : '<div class="sheet__grab"></div>') + html + '</div>';
    document.body.appendChild(wrap);
    document.body.classList.add('has-sheet');
    requestAnimationFrame(function () { wrap.classList.add('is-on'); });
    sheet = { el: wrap, onPick: opts.onPick, data: opts.data };
    wrap.addEventListener('click', sheetClick);
    wrap.addEventListener('input', sheetInput);
    if (opts.after) opts.after(wrap);
    return wrap;
  }
  function closeSheet(now) {
    if (!sheet) return;
    var el = sheet.el; sheet = null;
    document.body.classList.remove('has-sheet');
    if (now) { el.remove(); return; }
    el.classList.remove('is-on'); setTimeout(function () { el.remove(); }, 320);
  }
  function confirmSheet(title, msg, actions) {
    openSheet('<div class="asheet"><div class="asheet__group"><div class="asheet__head"><strong>' + esc(title) + '</strong>' + (msg ? '<span>' + esc(msg) + '</span>' : '') + '</div>' +
      actions.map(function (a, i) { return '<button class="asheet__btn' + (a.danger ? ' is-danger' : '') + '" data-sheet="act" data-i="' + i + '">' + esc(a.label) + '</button>'; }).join('') +
      '</div><button class="asheet__btn asheet__cancel" data-sheet="close">Annuler</button></div>', { action: true, data: actions });
  }
  function sheetClick(e) {
    var el = e.target.closest('[data-sheet]');
    if (!el || !sheet) return;
    var k = el.dataset.sheet;
    if (k === 'close') { closeSheet(); return; }
    if (k === 'act') { var a = sheet.data[+el.dataset.i]; closeSheet(); tap(); a.run(); return; }
    if (k === 'pick') { var cb = sheet.onPick; if (cb) cb(el.dataset.val, el); return; }
    if (k === 'done') { var cb2 = sheet.onPick; closeSheet(); if (cb2) cb2('__done'); }
  }
  var sheetSearchT = null;
  function sheetInput(e) {
    if (!sheet || !e.target.matches('[data-sheet-search]')) return;
    var q = e.target.value;
    clearTimeout(sheetSearchT);
    sheetSearchT = setTimeout(function () { if (sheet && sheet.search) sheet.search(q); }, 250);
  }

  // Product picker (single or multiple)
  function pickProducts(opts) {
    var chosen = {}; (opts.selected || []).forEach(function (p) { chosen[p.id] = p; });
    var multi = !!opts.multi;
    var el = openSheet('<div class="sheet__head"><button class="nav__btn" data-sheet="close">Annuler</button><strong>' + esc(opts.title || 'Choisir un produit') + '</strong>' + (multi ? '<button class="nav__btn nav__btn--strong" data-sheet="done">OK</button>' : '<span class="nav__btn"></span>') + '</div>' +
      '<div class="sheet__search"><label class="searchbar">' + ic('search', 18) + '<input type="search" placeholder="Rechercher un produit" data-sheet-search autocomplete="off"></label></div><div class="sheet__body"><div class="group" data-results>' + skel(3).replace(/^<div class="group">|<\/div>$/g, '') + '</div></div>', {
      onPick: function (val, btn) {
        if (val === '__done') { opts.done(Object.keys(chosen).map(function (k) { return chosen[k]; })); return; }
        var p = sheet.items[val];
        if (!multi) { closeSheet(); opts.done([p]); return; }
        tap();
        if (chosen[p.id]) delete chosen[p.id]; else chosen[p.id] = p;
        btn.classList.toggle('is-checked', !!chosen[p.id]);
      },
    });
    function run(q) {
      api('products?q=' + encodeURIComponent(q || '') + (q ? '' : '&filter=all')).then(function (d) {
        if (!sheet) return;
        sheet.items = {};
        var html = d.items.map(function (p) {
          sheet.items[p.id] = { id: p.id, name: p.name, image: p.image, regular: p.price };
          return '<button class="row row--pick' + (chosen[p.id] ? ' is-checked' : '') + '" data-sheet="pick" data-val="' + p.id + '"><span class="thumb">' + (p.image ? '<img src="' + esc(p.image) + '" alt="" loading="lazy">' : '') + '</span><span class="row__m"><span class="row__t">' + esc(p.name) + '</span><span class="row__s">' + esc(p.price) + (p.stock === 'outofstock' ? ' · Rupture' : '') + '</span></span><span class="check">' + ic('check', 16, 3) + '</span></button>';
        }).join('');
        el.querySelector('[data-results]').innerHTML = html || '<div class="empty"><p>Aucun produit trouvé.</p></div>';
      });
    }
    sheet.search = run;
    run('');
    setTimeout(function () { var i = el.querySelector('[data-sheet-search]'); if (i && !('ontouchstart' in window)) i.focus(); }, 300);
  }
  // Category picker (multiple)
  function pickCats(selected, done) {
    var chosen = {}; selected.forEach(function (id) { chosen[id] = 1; });
    function draw(cats) {
      var byParent = {};
      cats.forEach(function (c) { (byParent[c.parent] = byParent[c.parent] || []).push(c); });
      var out = '';
      (function walk(parent, depth) { (byParent[parent] || []).forEach(function (c) {
        out += '<button class="row row--pick' + (chosen[c.id] ? ' is-checked' : '') + '" data-sheet="pick" data-val="' + c.id + '" style="padding-left:' + (16 + depth * 18) + 'px"><span class="row__m"><span class="row__t">' + esc(c.name) + '</span><span class="row__s">' + c.count + ' produit' + (c.count > 1 ? 's' : '') + '</span></span><span class="check">' + ic('check', 16, 3) + '</span></button>';
        walk(c.id, depth + 1);
      }); })(0, 0);
      return out;
    }
    var el = openSheet('<div class="sheet__head"><button class="nav__btn" data-sheet="close">Annuler</button><strong>Catégories</strong><button class="nav__btn nav__btn--strong" data-sheet="done">OK</button></div><div class="sheet__body"><div class="group" data-results></div></div>', {
      onPick: function (val, btn) {
        if (val === '__done') { done(Object.keys(chosen).map(Number)); return; }
        tap(); if (chosen[val]) delete chosen[val]; else chosen[val] = 1;
        btn.classList.toggle('is-checked', !!chosen[val]);
      },
    });
    getCats().then(function (cats) { var r = el.querySelector('[data-results]'); if (r) r.innerHTML = draw(cats); });
  }
  function getCats() { return S.cats ? Promise.resolve(S.cats) : api('cats').then(function (c) { S.cats = c; return c; }); }
  function catNames(ids) {
    if (!ids || !ids.length) return 'Aucune';
    if (!S.cats) return ids.length + ' catégorie' + (ids.length > 1 ? 's' : '');
    var names = S.cats.filter(function (c) { return ids.indexOf(c.id) > -1; }).map(function (c) { return c.name; });
    return names.length > 2 ? names.slice(0, 2).join(', ') + ' +' + (names.length - 2) : names.join(', ');
  }

  // ---------- Photo upload (resized on the phone) ----------
  function resizeImage(file) {
    return new Promise(function (resolve) {
      var img = new Image(), url = URL.createObjectURL(file);
      img.onload = function () {
        var max = 1600, w = img.naturalWidth, h = img.naturalHeight, k = Math.min(1, max / Math.max(w, h));
        var c = document.createElement('canvas'); c.width = Math.round(w * k); c.height = Math.round(h * k);
        var ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); ctx.drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob(function (b) { resolve(b || file); }, 'image/jpeg', 0.86);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }
  function uploadPhoto(file, title) {
    return resizeImage(file).then(function (blob) {
      var fd = new FormData();
      fd.append('file', blob, (title || 'photo').replace(/[^\w-]+/g, '-').slice(0, 40) + '.jpg');
      fd.append('title', title || '');
      return api('media', { method: 'POST', body: fd });
    });
  }

  // Live hints under price fields (updated in place: no re-render while typing).
  function hintFor(v) {
    var f = v.f;
    if (!f) return '';
    if (v.v === 'product') {
      if (num(f.sale) > 0 && num(f.regular) > 0) return num(f.sale) < num(f.regular) ? 'Rabais réel de <b>' + Math.round((1 - num(f.sale) / num(f.regular)) * 100) + ' %</b> sur le prix régulier. Videz le champ pour arrêter la promo.' : '<span class="warn">Le prix promo doit être plus bas que le prix régulier.</span>';
      return 'Laissez « Prix promo » vide s’il n’y a pas de promo.';
    }
    if (v.v === 'flashEdit') {
      var reg = f.product ? num(f.product.regular) : 0;
      if (!reg) return f.product ? 'Produit à variantes : le rabais s’applique à chaque variante.' : '';
      var price = f.mode === 'percent' ? reg * (1 - Math.min(95, num(f.value)) / 100) : Math.min(num(f.value), reg);
      return 'Prix pendant la vente : <b>' + money(price) + '</b> au lieu de ' + money(reg) + '.';
    }
    if (v.v === 'promoEdit' && f.type === 'qty') return num(f.price) > 0 ? 'Affiché : <b>' + f.qty + ' pour ' + money(f.price) + '</b>' : '';
    return '';
  }
  function updateHints(v) { var h = document.querySelector('[data-hint="price"]'); if (h) h.innerHTML = hintFor(v); }

  // ============================================================ ORDERS
  var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
  var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  var pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  var swReg = null;
  var lastOpenIds = null;

  VIEWS.orders = function (v) {
    v.tab = v.tab || 'open';
    if (!v.data && !v.loading && !v.err) fetchOrders(v);
    var d = v.data;
    var body = pushBanner();
    if (d) {
      body += '<div class="stats">' +
        '<div class="stat stat--hero"><small>À traiter</small><strong>' + d.open + '</strong></div>' +
        '<div class="stat"><small>Aujourd’hui</small><strong>' + d.today.count + '</strong></div>' +
        '<div class="stat"><small>Ventes du jour</small><strong>' + esc(d.today.total || '–') + '</strong></div></div>';
    }
    body += '<div class="pad">' + seg('otab', v.tab, [['open', 'À traiter'], ['done', 'Terminées'], ['all', 'Toutes']]) + '</div>';
    if (!d) body += v.err ? errBox(v) : skel(4);
    else if (!d.orders.length) body += '<div class="empty empty--big">' + ic('check', 44, 1.6) + '<strong>' + (v.tab === 'open' ? 'Tout est traité' : 'Aucune commande') + '</strong><p>' + (v.tab === 'open' ? 'Les nouvelles commandes apparaîtront ici.' : '') + '</p></div>';
    else body += '<div class="cards">' + d.orders.map(function (o) {
      var fresh = /^(processing|on-hold|mcr-whatsapp)$/.test(o.status) && !S.seen[o.id];
      return '<button class="ocard' + (fresh ? ' is-new' : '') + '" data-act="order" data-id="' + o.id + '">' +
        '<span class="ocard__top"><strong>N° ' + esc(o.number) + '</strong>' + pill(esc(o.status_label), STATUS_TONE[o.status]) + '<span class="ocard__ago">' + ago(o.date) + '</span></span>' +
        '<span class="ocard__mid"><span>' + esc(o.name || 'Client') + '</span><strong>' + esc(o.total_label) + '</strong></span>' +
        '<span class="ocard__bot">' + (o.mode === 'pickup' ? '<span>' + ic('store', 14) + ' Retrait</span>' : o.mode === 'delivery' ? '<span>' + ic('truck', 14) + ' Livraison</span>' : '') +
        (o.slot ? '<span>' + ic('clock', 14) + ' ' + esc(o.slot) + '</span>' : '') + '<span>' + o.items_count + ' article' + (o.items_count > 1 ? 's' : '') + '</span>' +
        (o.paid ? '<span class="ok">Payée</span>' : '<span class="due">À encaisser</span>') + '</span>' + (fresh ? '<i class="dot"></i>' : '') + '</button>';
    }).join('') + '</div>';
    return { title: 'Commandes', body: body };
  };
  function fetchOrders(v, silent) {
    if (v.loading) return;
    v.loading = true;
    api('orders?tab=' + v.tab).then(function (d) {
      v.loading = false; v.err = '';
      if (d.nonce) C.nonce = d.nonce;
      var ids = d.orders.filter(function (o) { return /^(processing|on-hold|mcr-whatsapp)$/.test(o.status); }).map(function (o) { return o.id; });
      if (lastOpenIds && v.tab === 'open' && ids.some(function (id) { return lastOpenIds.indexOf(id) === -1; })) chime();
      if (v.tab === 'open') lastOpenIds = ids;
      if (!localStorage.getItem('mcr-seen-init')) { d.orders.forEach(function (o) { S.seen[o.id] = 1; }); save('mcr-seen', S.seen); try { localStorage.setItem('mcr-seen-init', '1'); } catch (e) {} }
      v.data = d; S.openCount = d.open;
      if (navigator.setAppBadge) { d.open ? navigator.setAppBadge(d.open).catch(function () {}) : navigator.clearAppBadge().catch(function () {}); }
      if (cur() === v && !(silent && sheet)) refreshView();
      else { var b = document.querySelector('[data-tabid="orders"] .tabbar__i'); if (b) { var old = b.querySelector('.tabbar__badge'); if (old) old.remove(); if (d.open) b.insertAdjacentHTML('beforeend', '<b class="tabbar__badge">' + d.open + '</b>'); } }
    }).catch(function (e) { v.loading = false; if (!silent) { v.err = e.message; if (cur() === v) refreshView(); } });
  }
  VIEWS.order = function (v) {
    if (!v.data && !v.loading && !v.err) { loadInto(v, 'order/' + v.id); }
    var o = v.data;
    if (!o) return { title: 'Commande', large: false, body: v.err ? errBox(v) : skel(5) };
    if (!S.seen[o.id]) { S.seen[o.id] = 1; save('mcr-seen', S.seen); }
    var digits = (o.phone || '').replace(/\D/g, '');
    var wa = digits ? 'https://wa.me/' + (digits.length === 10 ? '1' + digits : digits) + '?text=' + encodeURIComponent('Bonjour ' + (o.name || '') + ', ici le Marché Chemin-du-Roy au sujet de votre commande n° ' + o.number + '.') : '';
    var maps = o.address ? 'https://maps.apple.com/?q=' + encodeURIComponent(o.address) : '';
    var body = '<div class="ohead"><div><h1 class="large large--inline">N° ' + esc(o.number) + '</h1><p>' + ago(o.date) + (o.payment ? ' · ' + esc(o.payment) : '') + '</p></div>' + pill(esc(o.status_label), STATUS_TONE[o.status]) + '</div>';
    if (o.next.length) body += '<div class="pad stack">' + o.next.map(function (n, i) { return '<button class="btn ' + (n.status === 'cancelled' ? 'btn--gray btn--danger' : i === 0 ? 'btn--tint' : 'btn--gray') + '" data-act="ostatus" data-id="' + n.status + '">' + esc(n.label) + '</button>'; }).join('') + '</div>';
    body += group('Client', [row({ icon: 'bag', title: esc(o.name || '—'), sub: esc(o.phone || '') })]) +
      (digits ? '<div class="pad duo"><a class="btn btn--gray" href="tel:' + esc(digits) + '">' + ic('phone', 18) + ' Appeler</a><a class="btn btn--wa" href="' + esc(wa) + '" target="_blank" rel="noopener">' + ic('chat', 18) + ' WhatsApp</a></div>' : '');
    body += group(o.mode === 'pickup' ? 'Retrait en magasin' : 'Livraison', [
      row({ icon: o.mode === 'pickup' ? 'store' : 'truck', tone: o.mode === 'pickup' ? 'orange' : 'blue', title: esc(o.mode === 'pickup' ? (o.slot || 'Le client vient chercher la commande') : (o.address || 'Adresse non indiquée')), sub: o.distance ? esc(o.distance + ' · ' + o.shipping_total) : '' }),
    ].concat(maps ? [row({ icon: 'map', tone: 'green', title: 'Ouvrir dans Plans', href: maps, ext: true, chev: true })] : []));
    if (o.note) body += group('Note du client', ['<div class="row"><span class="row__m"><span class="row__t row__t--wrap">' + esc(o.note) + '</span></span></div>']);
    body += group(o.items_count + ' article' + (o.items_count > 1 ? 's' : ''), o.items.map(function (it) {
      return row({ img: it.image || '', title: '<b class="qty">' + it.qty + '×</b> ' + esc(it.name), sub: esc([].concat(it.meta || [], it.sku ? ['UGS ' + it.sku] : []).join(' · ')), value: esc(it.total) });
    }).concat(['<div class="row row--total"><span class="row__m"><span class="row__t">Total</span><span class="row__s">' + (o.paid ? 'Payée' : 'À encaisser à la livraison ou au retrait') + '</span></span><span class="row__v">' + esc(o.total_label) + '</span></div>']));
    body += group('', [row({ icon: 'wp', tone: 'gray', title: 'Ouvrir dans WordPress', href: (C.hpos ? C.adminHpos : C.admin) + o.id, ext: true, chev: true })]);
    return { title: 'N° ' + o.number, large: false, backLabel: 'Commandes', body: body };
  };

  // ============================================================ PRODUCTS
  var FILTERS = [['all', 'Tous'], ['low', 'Stock bas'], ['out', 'Rupture'], ['sale', 'En promo'], ['hidden', 'Masqués'], ['new', 'Récents']];
  VIEWS.products = function (v) {
    v.filter = v.filter || 'all'; v.q = v.q || '';
    if (!v.data && !v.loading && !v.err) fetchProducts(v);
    var d = v.data;
    var body = '<div class="pad"><label class="searchbar">' + ic('search', 18) + '<input type="search" data-f="q" value="' + esc(v.q) + '" placeholder="Rechercher un produit ou une UGS" autocomplete="off" enterkeyhint="search"></label></div>' +
      '<div class="chips">' + FILTERS.map(function (f) { return '<button data-seg="pfilter" data-val="' + f[0] + '"' + (v.filter === f[0] ? ' aria-selected="true"' : '') + '>' + f[1] + '</button>'; }).join('') + '</div>';
    body += '<div data-plist>' + productList(v) + '</div>';
    return { title: 'Produits', right: '<button class="nav__icon" data-act="newprod" aria-label="Nouveau produit">' + ic('plus', 26, 2.2) + '</button>', body: body };
  };
  function productList(v) {
    var d = v.data, body = '';
    if (!d) return v.err ? errBox(v) : skel(6);
    if (!d.items.length) return '<div class="empty empty--big">' + ic('box', 44, 1.5) + '<strong>Aucun produit</strong><p>' + (v.q ? 'Essayez un autre mot.' : 'Rien dans ce filtre.') + '</p></div>';
    body += '<p class="gfoot gfoot--top">' + d.total + ' produit' + (d.total > 1 ? 's' : '') + '</p>' + group('', d.items.map(prodRow));
    if (d.page < d.pages) body += '<div class="pad"><button class="btn btn--gray" data-act="more"' + (v.loading ? ' disabled' : '') + '>Afficher plus</button></div>';
    return body;
  }
  function prodRow(p) {
    var badge = p.status !== 'publish' ? pill('Masqué', 'gray') : p.stock === 'outofstock' ? pill('Rupture', 'red') : p.low ? pill('Plus que ' + p.low, 'orange') : p.on_sale ? pill('Promo', 'green') : '';
    return row({ act: 'prod', id: p.id, img: p.image || '', title: esc(p.name), sub: '<span class="price">' + esc(p.price) + '</span>' + (p.qty != null && !p.low && p.stock !== 'outofstock' ? ' · ' + p.qty + ' en stock' : ''), value: badge, chev: true, cls: p.status !== 'publish' ? 'is-dim' : '' });
  }
  function fetchProducts(v, more) {
    v.loading = true;
    var seq = v.seq = (v.seq || 0) + 1;
    var page = more ? v.data.page + 1 : 1;
    api('products?q=' + encodeURIComponent(v.q) + '&filter=' + v.filter + '&page=' + page).then(function (d) {
      if (seq !== v.seq) return;
      v.loading = false; v.err = '';
      if (more) { d.items = v.data.items.concat(d.items); }
      v.data = d;
      if (cur() === v) { var box = document.querySelector('[data-plist]'); if (box) box.innerHTML = productList(v); else refreshView(); }
    }).catch(function (e) { v.loading = false; v.err = e.message; if (cur() === v) refreshView(); });
  }

  VIEWS.product = function (v) {
    if (v.id && !v.data && !v.loading && !v.err) loadInto(v, 'product/' + v.id);
    if (v.id && !v.data) return { title: 'Produit', large: false, body: v.err ? errBox(v) : '<div class="hero hero--skel"></div>' + skel(4) };
    if (!S.cats) getCats().then(function () { if (cur() === v) refreshView(); });
    var p = v.data || {};
    var f = v.f = v.f || {
      name: p.name || '', name_en: p.name_en || '', regular: p.regular || '', sale: p.sale || '', sale_to: p.sale_to || '',
      manage: p.id ? !!p.manage : false, qty: p.qty == null ? '' : p.qty, stock: p.stock || 'instock', status: p.id ? p.status === 'publish' : true,
      cats: p.cats || [], image_id: p.image_id || 0, image: p.image_full || p.image || '',
    };
    var variable = p.type === 'variable';
    var body = '<div class="hero">' + (f.image ? '<img src="' + esc(f.image) + '" alt="">' : '<div class="hero__empty">' + ic('photo', 46, 1.4) + '<span>Aucune photo</span></div>') +
      '<label class="hero__btn">' + ic('camera', 18) + '<span>' + (v.uploading ? 'Envoi…' : f.image ? 'Changer la photo' : 'Ajouter une photo') + '</span><input type="file" accept="image/*" data-act="photo" hidden></label></div>';
    body += group('Nom', [field('name', 'Français', f.name, { ph: 'Ex. Farine de manioc 1 kg' }), field('name_en', 'Anglais', f.name_en, { ph: 'Facultatif' })]);
    if (!variable) {
      var locked = !!p.campaign;
      body += group('Prix', [
        field('regular', 'Prix régulier', f.regular, { mode: 'decimal', unit: '$', ph: '0,00' }),
        field('sale', 'Prix promo', f.sale, { mode: 'decimal', unit: '$', ph: 'Aucun', dis: locked }),
      ].concat(!locked ? [field('sale_to', 'Fin de la promo', f.sale_to, { type: 'date', min: C.today })] : []),
        locked ? 'Ce prix promo est géré par la promotion « ' + esc(p.campaign) + ' ». Il reviendra seul à la fin.' : hintFor(v), locked ? '' : 'price');
      body += group('Stock', [toggle('manage', f.manage, 'Suivre la quantité', f.manage ? 'Rupture automatique à 0' : 'Indiquez seulement en stock ou en rupture', 'box', 'blue')].concat(
        f.manage ? [stepper('qty', f.qty === '' ? 0 : f.qty, 0, 99999, 1, ['Quantité en stock', ''])] : [toggle('instock', f.stock !== 'outofstock', 'En stock', f.stock === 'outofstock' ? 'Affiché « Rupture » sur le site' : '', 'check', 'green')]));
    }
    body += group('Sur le site', [
      toggle('status', f.status, 'Visible sur le site', f.status ? '' : 'Masqué : les clients ne le voient pas', 'eye', 'tint'),
      row({ act: 'cats', icon: 'tag', tone: 'orange', title: 'Catégories', value: esc(catNames(f.cats)), chev: true }),
    ].concat(p.flash ? [row({ icon: 'bolt', tone: 'red', title: 'Vente éclair', value: p.flash.state === 'active' ? 'En cours · ' + p.flash.left + ' restants' : p.flash.state === 'scheduled' ? 'Programmée' : 'Terminée' })] : []));
    if (variable) {
      body += group('Variantes', p.variations.map(function (x, i) {
        var badge = !x.enabled ? pill('Désactivée', 'gray') : x.stock === 'outofstock' ? pill('Rupture', 'red') : x.manage ? pill(x.qty + ' en stock', x.qty <= 5 ? 'orange' : 'gray') : '';
        return row({ act: 'var', id: i, img: x.image || '', title: esc(x.name || 'Variante'), sub: '<span class="price">' + (x.sale ? '<s>' + money(x.regular) + '</s> ' + money(x.sale) : money(x.regular)) + '</span>', value: badge, chev: true });
      }), 'Touchez une variante pour changer son prix ou son stock.');
    }
    if (p.id) body += group('', [row({ icon: 'globe', tone: 'gray', title: 'Voir sur le site', href: p.link, ext: true, chev: true })]);
    return { title: p.id ? 'Produit' : 'Nouveau produit', large: false, backLabel: 'Produits', right: saveBtn(v, p.id ? 'Enregistrer' : 'Créer'), body: body };
  };
  function saveProduct(v) {
    var f = v.f, p = v.data || {};
    var body = { name: f.name.trim(), name_en: f.name_en.trim(), status: f.status ? 'publish' : 'draft', cats: f.cats, image_id: f.image_id || 0 };
    if (p.type !== 'variable') {
      body.regular = f.regular; body.manage = f.manage;
      if (!p.campaign) { body.sale = f.sale; body.sale_to = f.sale_to; }
      if (f.manage) body.qty = f.qty === '' ? 0 : f.qty; else body.stock = f.stock;
    }
    if (!body.name) { toast('Donnez un nom au produit.', 'err'); return; }
    if (p.type !== 'variable' && num(f.regular) <= 0) { toast('Indiquez le prix régulier.', 'err'); return; }
    var btn = document.querySelector('[data-act="save"]'); if (btn) { btn.disabled = true; btn.textContent = '…'; }
    api(p.id ? 'product/' + p.id : 'product', { body: body }).then(function (np) {
      toast(p.id ? 'Produit enregistré' : 'Produit créé', 'ok');
      v.data = np; v.id = np.id; v.f = null; v.dirty = false;
      var list = S.stacks.products[0]; if (list.data) { list.data = null; }
      refreshView();
    }).catch(function (e) { toast(e.message, 'err'); if (btn) { btn.disabled = false; btn.textContent = p.id ? 'Enregistrer' : 'Créer'; } });
  }
  function editVariation(v, i) {
    var x = v.data.variations[i];
    var f = { regular: x.regular, sale: x.sale, manage: x.manage, qty: x.qty == null ? 0 : x.qty, stock: x.stock, enabled: x.enabled };
    var locked = !!x.campaign;
    function html() {
      return '<div class="sheet__head"><button class="nav__btn" data-sheet="close">Annuler</button><strong>' + esc(x.name || 'Variante') + '</strong><button class="nav__btn nav__btn--strong" data-sheet="done">Enregistrer</button></div><div class="sheet__body">' +
        group('Prix', [field('regular', 'Prix régulier', f.regular, { mode: 'decimal', unit: '$' }), field('sale', 'Prix promo', f.sale, { mode: 'decimal', unit: '$', ph: 'Aucun', dis: locked })], locked ? 'Prix promo géré par « ' + esc(x.campaign) + ' ».' : '') +
        group('Stock', [toggle('manage', f.manage, 'Suivre la quantité', '', 'box', 'blue')].concat(f.manage ? [stepper('qty', f.qty, 0, 99999, 1, ['Quantité en stock', ''])] : [toggle('instock', f.stock !== 'outofstock', 'En stock', '', 'check', 'green')])) +
        group('', [toggle('enabled', f.enabled, 'Variante active', 'Désactivée : non proposée aux clients', 'eye', 'tint')]) + '</div>';
    }
    var el = openSheet(html(), {
      onPick: function (val) {
        if (val !== '__done') return;
        var body = { regular: f.regular, manage: f.manage, enabled: f.enabled };
        if (!locked) body.sale = f.sale;
        if (f.manage) body.qty = f.qty; else body.stock = f.stock;
        api('variation/' + x.id, { body: body }).then(function (np) { v.data = np; toast('Variante enregistrée', 'ok'); refreshView(); }).catch(function (e) { toast(e.message, 'err'); });
      },
    });
    function bind() {
      el.querySelector('.sheet__panel').addEventListener('input', function (e) { var k = e.target.dataset.f; if (k === 'regular' || k === 'sale') f[k] = e.target.value; });
      el.querySelector('.sheet__panel').addEventListener('change', function (e) {
        var k = e.target.dataset.f; if (!k || e.target.type !== 'checkbox') return;
        tap();
        if (k === 'manage') { f.manage = e.target.checked; redraw(); }
        if (k === 'instock') f.stock = e.target.checked ? 'instock' : 'outofstock';
        if (k === 'enabled') f.enabled = e.target.checked;
      });
      el.querySelector('.sheet__panel').addEventListener('click', function (e) {
        var b = e.target.closest('[data-step]'); if (!b) return;
        f.qty = Math.max(+b.dataset.min, Math.min(+b.dataset.max, (+f.qty || 0) + +b.dataset.d)); tap();
        el.querySelector('[data-out="qty"]').textContent = f.qty;
      });
    }
    function redraw() { var panel = el.querySelector('.sheet__panel'); panel.innerHTML = '<div class="sheet__grab"></div>' + html(); }
    bind();
  }

  // ============================================================ PROMOTIONS
  var STATE_LABEL = { active: ['En cours', 'green'], scheduled: ['Programmée', 'blue'], soldout: ['Épuisée', 'orange'], ended: ['Terminée', 'gray'] };
  VIEWS.promos = function (v) {
    v.seg = v.seg || (CAPS.flash ? 'flash' : 'promo');
    if (!v.data && !v.loading && !v.err) loadInto(v, 'promos');
    var d = v.data;
    var body = CAPS.flash && CAPS.promotions ? '<div class="pad">' + seg('pseg', v.seg, [['flash', 'Ventes éclair'], ['promo', 'Promotions']]) + '</div>' : '';
    if (!d) return { title: 'Promos', body: body + (v.err ? errBox(v) : skel(3)) };
    if (v.seg === 'flash') {
      var live = d.flash.filter(function (x) { return x.state !== 'ended'; }), past = d.flash.filter(function (x) { return x.state === 'ended'; }).slice(0, 10);
      body += live.length ? '<div class="cards">' + live.map(flashCard).join('') + '</div>' : '<div class="empty empty--big">' + ic('bolt', 44, 1.5) + '<strong>Aucune vente éclair</strong><p>Un produit, un vrai rabais, une quantité limitée et un compte à rebours. Idéal pour écouler un stock.</p><button class="btn btn--tint" data-act="newflash">Créer une vente éclair</button></div>';
      if (past.length) body += group('Terminées', past.map(function (x) { return row({ act: 'flash', id: x.id, img: x.product ? x.product.image : '', title: esc(x.product ? x.product.name : 'Produit supprimé'), sub: fmtWhen(x.start) + ' · ' + x.sold + '/' + x.qty + ' vendus', chev: true }); }));
    } else {
      if (d.week) body += '<h2 class="ghead">Promo de la semaine</h2><button class="feature" data-act="week">' + (d.week.image ? '<img src="' + esc(d.week.image) + '" alt="">' : '') + '<span><small>' + ic('star', 14, 2.4) + ' En vedette sur l’accueil</small><strong>' + esc(d.week.name) + '</strong><em>' + esc(d.week.price) + '</em></span>' + ic('chev', 16, 2.4) + '</button>';
      else body += group('Promo de la semaine', [row({ act: 'week', icon: 'star', tone: 'orange', title: 'Choisir le produit en vedette', chev: true })]);
      var groups = { active: [], scheduled: [], ended: [] };
      d.promos.forEach(function (c) { groups[c.state].push(c); });
      if (!d.promos.length) body += '<div class="empty empty--big">' + ic('tag', 44, 1.5) + '<strong>Aucune promotion</strong><p>Rabais en %, offres « 2 pour 5 $ » ou kits à prix spécial.</p><button class="btn btn--tint" data-act="newpromo">Créer une promotion</button></div>';
      [['active', 'En cours'], ['scheduled', 'À venir'], ['ended', 'Terminées']].forEach(function (g) {
        if (!groups[g[0]].length) return;
        body += group(g[1], groups[g[0]].slice(0, g[0] === 'ended' ? 10 : 50).map(function (c) {
          var when = c.start && c.end ? fmtDate(c.start) + ' – ' + fmtDate(c.end) : c.end ? 'Jusqu’au ' + fmtDate(c.end) : c.start ? 'Dès le ' + fmtDate(c.start) : 'Sans date de fin';
          return row({ act: 'promo', id: c.id, icon: c.type === 'percent' ? 'tag' : c.type === 'qty' ? 'bag' : 'box', tone: c.state === 'active' ? 'green' : c.state === 'scheduled' ? 'blue' : 'gray', title: esc(c.title_fr), sub: esc(c.label) + ' · ' + esc(when) + (c.working ? ' · mise à jour des prix…' : ''), chev: true });
        }));
      });
    }
    var add = v.seg === 'flash' ? 'newflash' : 'newpromo';
    return { title: 'Promos', right: '<button class="nav__icon" data-act="' + add + '" aria-label="Ajouter">' + ic('plus', 26, 2.2) + '</button>', body: body };
  };
  function flashCard(x) {
    var st = STATE_LABEL[x.state], pct = x.qty ? Math.min(100, Math.round(x.sold / x.qty * 100)) : 0;
    var when = x.state === 'active' ? 'Se termine ' + fmtWhen(x.end).toLowerCase() : x.state === 'scheduled' ? 'Commence ' + fmtWhen(x.start).toLowerCase() : 'Plus de stock éclair';
    return '<button class="fcard" data-act="flash" data-id="' + x.id + '">' +
      '<span class="fcard__img">' + (x.product && x.product.image ? '<img src="' + esc(x.product.image) + '" alt="">' : '') + '</span>' +
      '<span class="fcard__m"><span class="fcard__top">' + pill(st[0], st[1]) + '<span class="fcard__deal">' + (x.mode === 'percent' ? '−' + x.value + ' %' : esc(x.flash_price)) + '</span></span>' +
      '<strong>' + esc(x.product ? x.product.name : 'Produit supprimé') + '</strong><small>' + when + '</small>' +
      '<span class="meter"><i style="width:' + pct + '%"></i></span><small>' + x.sold + ' vendus sur ' + x.qty + '</small></span></button>';
  }

  VIEWS.flashEdit = function (v) {
    var x = v.src || {};
    var f = v.f = v.f || {
      id: x.id || '', product: x.product || null, mode: x.mode || 'percent', value: x.value || 20,
      date: x.date || C.today, time: x.time || nextHour(), hours: x.hours || 3, qty: x.qty || 10, per_order: x.per_order != null ? x.per_order : 2,
    };
    var reg = f.product ? num(f.product.regular) : 0;
    var body = group('Produit', [f.product ? row({ act: 'fprod', img: f.product.image || '', title: esc(f.product.name), sub: reg ? 'Prix régulier ' + money(reg) : '', chev: true }) : row({ act: 'fprod', icon: 'box', title: 'Choisir le produit', chev: true })]);
    body += '<h2 class="ghead">Rabais</h2><div class="pad">' + seg('fmode', f.mode, [['percent', 'Pourcentage'], ['price', 'Prix éclair']]) + '</div>' +
      group('', [field('value', f.mode === 'percent' ? 'Rabais' : 'Prix éclair', f.value, { mode: 'decimal', unit: f.mode === 'percent' ? '%' : '$' })], hintFor(v), 'price');
    body += group('Quand', [field('date', 'Date', f.date, { type: 'date', min: C.today }), field('time', 'Heure', f.time, { type: 'time' })]) +
      '<div class="chips chips--wrap">' + [1, 2, 3, 6, 12, 24, 48].map(function (h) { return '<button data-seg="fhours" data-val="' + h + '"' + (+f.hours === h ? ' aria-selected="true"' : '') + '>' + h + ' h</button>'; }).join('') + '</div>' +
      '<p class="gfoot">Durée de la vente : ' + f.hours + ' heure' + (f.hours > 1 ? 's' : '') + '.</p>';
    body += group('Quantité', [stepper('qty', f.qty, 1, 9999, 1, ['Unités en vente éclair', 'Le prix normal revient quand elles sont vendues']), stepper('per_order', f.per_order, 0, 99, 1, ['Maximum par commande', f.per_order ? '' : 'Sans limite'])]);
    if (x.id) {
      var acts = [];
      if (x.state === 'active' || x.state === 'soldout' || x.state === 'scheduled') acts.push(row({ act: 'fdo', id: 'stop', icon: 'clock', tone: 'orange', title: x.state === 'scheduled' ? 'Annuler cette vente' : 'Arrêter maintenant' }));
      acts.push(row({ act: 'fdo', id: 'tomorrow', icon: 'bolt', tone: 'tint', title: 'Refaire demain à la même heure' }));
      acts.push(row({ act: 'fdo', id: 'delete', icon: 'trash', tone: 'red', title: 'Supprimer', cls: 'is-danger' }));
      body += group('', acts, x.sold ? x.sold + ' unité' + (x.sold > 1 ? 's' : '') + ' vendue' + (x.sold > 1 ? 's' : '') + ' pendant cette vente.' : '');
    }
    return { title: x.id ? 'Vente éclair' : 'Nouvelle vente éclair', large: false, backLabel: 'Promos', right: saveBtn(v, x.id ? 'Enregistrer' : 'Créer'), body: body };
  };
  function nextHour() { var d = new Date(); d.setHours(d.getHours() + 1, 0, 0, 0); return ('0' + d.getHours()).slice(-2) + ':00'; }
  function saveFlash(v) {
    var f = v.f;
    if (!f.product) { toast('Choisissez un produit.', 'err'); return; }
    var body = { id: f.id, product: f.product.id, mode: f.mode, value: f.value, date: f.date, time: f.time, hours: f.hours, qty: f.qty, per_order: f.per_order };
    sendPromo(v, 'flash', body, f.id ? 'Vente éclair enregistrée' : 'Vente éclair créée');
  }
  function sendPromo(v, path, body, msg) {
    var btn = document.querySelector('[data-act="save"]'); if (btn) { btn.disabled = true; btn.textContent = '…'; }
    api(path, { body: body }).then(function (d) {
      S.stacks.promos[0].data = d; toast(msg, 'ok'); v.dirty = false; goBack();
    }).catch(function (e) { toast(e.message, 'err'); if (btn) { btn.disabled = false; btn.textContent = 'Enregistrer'; } });
  }

  VIEWS.promoEdit = function (v) {
    var c = v.src || {};
    if (!S.cats) getCats().then(function () { if (cur() === v) refreshView(); });
    var f = v.f = v.f || {
      id: c.id || '', type: c.type || 'percent', title_fr: c.title_fr || '', title_en: c.title_en || '', text_fr: c.text_fr || '', text_en: c.text_en || '',
      percent: c.percent || 15, qty: c.qty || 2, price: c.price || '', products: c.products || [], cats: c.cats || [],
      start: c.start || C.today, end: c.end || addDays(7), banner: c.id ? !!c.banner : true, skip_existing: c.id ? !!c.skip_existing : true,
    };
    var body = '<div class="pad">' + seg('ptype', f.type, [['percent', 'Rabais %'], ['qty', 'Lot'], ['bundle', 'Kit']]) + '</div>' +
      '<p class="gfoot gfoot--top">' + (f.type === 'percent' ? 'Un vrai rabais sur le prix régulier, du début à la fin. Les prix reviennent seuls.' : f.type === 'qty' ? 'Ex. « 2 pour 5 $ » : appliqué dans le panier quand le client prend la quantité.' : 'Plusieurs produits ensemble pour un prix spécial, appliqué dans le panier.') + '</p>';
    body += group('Nom', [field('title_fr', 'Français', f.title_fr, { ph: f.type === 'percent' ? 'Ex. Semaine des épices' : f.type === 'qty' ? 'Ex. Plantain 2 pour 5 $' : 'Ex. Kit fufu' }), field('title_en', 'Anglais', f.title_en, { ph: 'Facultatif' })]);
    if (f.type === 'percent') body += group('Rabais', [stepper('percent', f.percent, 1, 90, 5, ['Pourcentage', f.percent + ' % de moins sur le prix régulier'])]);
    if (f.type === 'qty') body += group('Offre', [stepper('qty', f.qty, 2, 20, 1, ['Quantité', '']), field('price', 'Prix du lot', f.price, { mode: 'decimal', unit: '$', ph: '0,00' })], hintFor(v), 'price');
    if (f.type === 'bundle') body += group('Kit', [field('price', 'Prix du kit', f.price, { mode: 'decimal', unit: '$', ph: '0,00' })]);
    var prodRows = f.products.map(function (p, i) { return row({ img: p.image || '', title: esc(p.name), value: '<button class="rm" data-act="rmprod" data-id="' + i + '" aria-label="Retirer">' + ic('x', 14, 3) + '</button>' }); });
    prodRows.push(row({ act: 'pprods', icon: 'plus', tone: 'tint', title: f.products.length ? 'Ajouter ou retirer des produits' : 'Choisir des produits', chev: true }));
    body += group('Produits' + (f.products.length ? ' (' + f.products.length + ')' : ''), prodRows);
    if (f.type !== 'bundle') body += group('', [row({ act: 'pcats', icon: 'tag', tone: 'orange', title: 'Catégories entières', value: esc(catNames(f.cats)), chev: true })], 'Tous les produits des catégories choisies sont inclus.');
    body += group('Dates', [field('start', 'Début', f.start, { type: 'date' }), field('end', 'Fin', f.end, { type: 'date', min: f.start })]);
    body += group('Options', [toggle('banner', f.banner, 'Afficher dans la fenêtre promo', 'Sur l’accueil et la page Promotions', 'megaphone', 'red')].concat(f.type === 'percent' ? [toggle('skip_existing', f.skip_existing, 'Garder les promos déjà en place', 'Ne touche pas aux produits qui ont déjà un prix promo', 'tag', 'gray')] : []));
    if (c.id) body += group('', [row({ act: 'pdelete', icon: 'trash', tone: 'red', title: 'Supprimer la promotion', cls: 'is-danger' })], c.type === 'percent' ? 'Les prix d’origine sont remis automatiquement.' : '');
    return { title: c.id ? 'Promotion' : 'Nouvelle promotion', large: false, backLabel: 'Promos', right: saveBtn(v, c.id ? 'Enregistrer' : 'Créer'), body: body };
  };
  function savePromoForm(v) {
    var f = v.f;
    var body = { id: f.id, type: f.type, title_fr: f.title_fr, title_en: f.title_en, text_fr: f.text_fr, text_en: f.text_en, percent: f.percent, qty: f.qty, price: f.price,
      products: f.products.map(function (p) { return p.id; }), cats: f.type === 'bundle' ? [] : f.cats, start: f.start, end: f.end, banner: f.banner ? 1 : '', skip_existing: f.skip_existing ? 1 : '' };
    sendPromo(v, 'promo', body, f.type === 'percent' ? 'Promotion enregistrée. Les prix se mettent à jour.' : 'Offre enregistrée');
  }

  // ============================================================ SHOP
  VIEWS.shop = function (v) {
    var body = '';
    if (CAPS.shop) {
      if (!v.data && !v.loading && !v.err) loadInto(v, 'shop');
      var d = v.data;
      if (!d) body += v.err ? errBox(v) : '<div class="tiles"><div class="skel skel--tile"></div><div class="skel skel--tile"></div></div>' + skel(3);
      else {
        var s = d.sales, max = Math.max.apply(null, s.days.map(function (x) { return x.total; }).concat([1]));
        body += '<div class="tiles"><div class="tile tile--hero"><small>Aujourd’hui</small><strong>' + esc(s.today.total) + '</strong><span>' + s.today.count + ' commande' + (s.today.count > 1 ? 's' : '') + '</span></div>' +
          '<div class="tile"><small>7 derniers jours</small><strong>' + esc(s.week.total) + '</strong><span>' + s.week.count + ' commandes · panier moyen ' + esc(s.week.avg) + '</span></div></div>';
        body += '<div class="chart" role="img" aria-label="Ventes des 7 derniers jours">' + s.days.map(function (x, i) {
          return '<div class="chart__col' + (i === s.days.length - 1 ? ' is-today' : '') + '"><span class="chart__v">' + (x.total ? Math.round(x.total) + ' $' : '') + '</span><i style="height:' + Math.max(3, Math.round(x.total / max * 100)) + '%"></i><span class="chart__l">' + esc(x.label) + '</span></div>';
        }).join('') + '</div>';
        body += group('Stock', [
          row({ act: 'stockout', icon: 'alert', tone: 'red', title: 'En rupture', value: '<b>' + d.stock.out + '</b>', chev: true }),
          row({ act: 'stocklow', icon: 'box', tone: 'orange', title: 'Stock bas', sub: d.stock.low_at + ' unités ou moins', value: '<b>' + d.stock.low + '</b>', chev: true }),
        ]);
        if (s.best.length) body += group('Meilleures ventes · 7 jours', s.best.map(function (b, i) { return row({ act: 'prod', id: b.id, img: b.image || '', title: '<b class="rank">' + (i + 1) + '</b>' + esc(b.name), value: b.qty + ' vendus', chev: true }); }));
        body += group('Service', [
          toggle('delivery', d.delivery, 'Livraison', d.delivery ? 'Les clients peuvent se faire livrer' : 'Désactivée sur le site', 'truck', 'blue'),
          toggle('pickup', d.pickup, 'Retrait en magasin', d.pickup ? 'Les clients peuvent passer chercher' : 'Désactivé sur le site', 'store', 'orange'),
        ], 'Utile en cas d’imprévu : le changement est immédiat sur le site.');
        var a = v.ann = v.ann || { enabled: d.announcement.enabled, text_fr: d.announcement.text_fr, text_en: d.announcement.text_en };
        body += group('Bandeau d’annonce', [
          toggle('ann_on', a.enabled, 'Afficher le bandeau', 'En haut de toutes les pages', 'megaphone', 'red'),
          '<label class="row row--area"><span class="row__label">Français</span><textarea data-f="ann_fr" rows="2">' + esc(a.text_fr) + '</textarea></label>',
          '<label class="row row--area"><span class="row__label">Anglais</span><textarea data-f="ann_en" rows="2">' + esc(a.text_en) + '</textarea></label>',
        ]) + '<div class="pad"><button class="btn btn--gray" data-act="annsave"' + (v.annDirty ? '' : ' disabled') + '>Enregistrer le bandeau</button></div>';
      }
    }
    body += group('Notifications', notifRows(), notifFoot());
    var links = [];
    if (v.data) links.push(row({ icon: 'globe', tone: 'tint', title: 'Voir le site', href: v.data.site, ext: true, chev: true }));
    links.push(row({ icon: 'wp', tone: 'gray', title: 'Ouvrir WordPress', href: C.admin.replace(/post\.php.*$/, ''), ext: true, chev: true }));
    body += group('', links) + group('', [row({ icon: 'out', tone: 'red', title: 'Se déconnecter', href: C.logout, cls: 'is-danger' })], 'Connecté : ' + esc(C.user) + ' · ' + esc(C.store || ''));
    return { title: 'Boutique', body: body };
  };
  function notifRows() {
    if (state_push()) return [row({ icon: 'bell', tone: 'red', title: 'Alertes de commandes', value: 'Activées' }), row({ act: 'push-test', icon: 'check', tone: 'green', title: 'Envoyer une notification test' }), row({ act: 'push-off', icon: 'x', tone: 'gray', title: 'Désactiver sur ce téléphone' })];
    return [row({ act: 'push-on', icon: 'bell', tone: 'red', title: 'Activer les alertes de commandes', sub: 'Une alerte sonne dès qu’une commande arrive', chev: true })];
  }
  function notifFoot() {
    if (state_push()) return '';
    if (isIOS && !standalone) return 'Sur iPhone : dans Safari, touchez Partager puis « Sur l’écran d’accueil », puis ouvrez l’app depuis l’écran d’accueil.';
    if (!pushSupported) return 'Ce navigateur ne permet pas les notifications.';
    if (Notification.permission === 'denied') return 'Notifications bloquées : autorisez-les dans les réglages du téléphone.';
    return '';
  }
  function state_push() { return S.pushOn; }
  function pushBanner() {
    if (S.pushOn) return '';
    if (isIOS && !standalone) return '<div class="callout"><span class="callout__ic">' + ic('bell', 22) + '</span><div><strong>Installez l’app pour les alertes</strong><span>Dans Safari : Partager, puis « Sur l’écran d’accueil ».</span></div></div>';
    if (!pushSupported || Notification.permission === 'denied') return '';
    return '<button class="callout callout--btn" data-act="push-on"><span class="callout__ic">' + ic('bell', 22) + '</span><div><strong>Activer les alertes de commandes</strong><span>Une alerte sonne dès qu’une commande est payée.</span></div>' + ic('chev', 16, 2.4) + '</button>';
  }

  // ---------- Sound when an order arrives ----------
  var audioCtx = null;
  function chime() {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      [0, 0.18, 0.36].forEach(function (t, i) {
        var o = audioCtx.createOscillator(), g = audioCtx.createGain();
        o.type = 'sine'; o.frequency.value = [880, 1175, 1568][i];
        g.gain.setValueAtTime(0.0001, audioCtx.currentTime + t);
        g.gain.exponentialRampToValueAtTime(0.35, audioCtx.currentTime + t + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + t + 0.3);
        o.connect(g); g.connect(audioCtx.destination); o.start(audioCtx.currentTime + t); o.stop(audioCtx.currentTime + t + 0.32);
      });
    } catch (e) {}
    if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
  }
  document.addEventListener('pointerdown', function () { try { audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)(); audioCtx.resume(); } catch (e) {} }, { once: true });

  // ---------- Push ----------
  function b64ToBytes(b64) { var pad = '='.repeat((4 - (b64.length % 4)) % 4); var raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/')); var out = new Uint8Array(raw.length); for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i); return out; }
  function deviceName() {
    var ua = navigator.userAgent, m = ua.match(/\(([^)]+)\)/), am = ua.match(/Android [\d.]+; ([^;)]+)/);
    var os = /iphone/i.test(ua) ? 'iPhone' : /ipad/i.test(ua) ? 'iPad' : /android/i.test(ua) ? 'Android' + (am ? ' · ' + am[1] : '') : m ? m[1].split(';')[0] : 'Appareil';
    var br = /edg/i.test(ua) ? 'Edge' : /chrome|crios/i.test(ua) ? 'Chrome' : /firefox/i.test(ua) ? 'Firefox' : /safari/i.test(ua) ? 'Safari' : '';
    return os + (br ? ' · ' + br : '') + (standalone ? ' · app' : '');
  }
  function registerSW() { if (!('serviceWorker' in navigator)) return Promise.resolve(null); return navigator.serviceWorker.register(C.sw, { scope: C.scope }).then(function (r) { swReg = r; return r; }).catch(function () { return null; }); }
  function checkPush() {
    if (!pushSupported || !swReg) { S.pushOn = false; return Promise.resolve(false); }
    return swReg.pushManager.getSubscription().then(function (s) {
      S.pushOn = !!s && Notification.permission === 'granted';
      if (s && S.pushOn) api('subscribe', { method: 'POST', body: { subscription: s.toJSON(), device: deviceName() } }).catch(function () {});
      return S.pushOn;
    });
  }
  function enablePush() {
    if (!pushSupported) return Promise.reject(new Error(isIOS && !standalone ? 'ios-install' : 'unsupported'));
    return Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') throw new Error('denied');
      return (swReg ? Promise.resolve(swReg) : registerSW()).then(function (reg) { return reg.pushManager.getSubscription().then(function (s) { return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(C.vapid) }); }); });
    }).then(function (sub) { return api('subscribe', { method: 'POST', body: { subscription: sub.toJSON(), device: deviceName() } }).then(function () { S.pushOn = true; }); });
  }
  function disablePush() {
    if (!swReg) return Promise.resolve();
    return swReg.pushManager.getSubscription().then(function (s) { if (!s) return; return api('unsubscribe', { method: 'POST', body: { endpoint: s.endpoint } }).finally(function () { return s.unsubscribe(); }); }).then(function () { S.pushOn = false; });
  }
  function testPush() { return swReg.pushManager.getSubscription().then(function (s) { return api('test', { method: 'POST', body: { endpoint: s ? s.endpoint : '' } }); }); }

  // ============================================================ EVENTS
  app.addEventListener('click', function (e) {
    var t = e.target.closest('[data-tabid],[data-act],[data-seg],[data-step]');
    if (!t) return;
    var v = cur();
    if (t.dataset.tabid) { switchTab(t.dataset.tabid); return; }
    if (t.dataset.step) { e.preventDefault(); stepField(v, t); return; }
    if (t.dataset.seg) { onSeg(v, t.dataset.seg, t.dataset.val); return; }
    var act = t.dataset.act, id = t.dataset.id;
    if (t.tagName === 'INPUT') return;
    switch (act) {
      case 'back': goBack(); break;
      case 'retry': v.err = ''; v.data = null; v.loading = false; refreshView(); break;
      case 'save':
        if (v.v === 'product') saveProduct(v);
        if (v.v === 'flashEdit') saveFlash(v);
        if (v.v === 'promoEdit') savePromoForm(v);
        break;
      // orders
      case 'order': cur().scroll = window.scrollY; push({ v: 'order', id: id }); break;
      case 'ostatus':
        if (id === 'cancelled' && !t.dataset.ok) { confirmSheet('Annuler la commande ?', 'Le client sera prévenu. Cette action ne peut pas être défaite.', [{ label: 'Annuler la commande', danger: true, run: function () { t.dataset.ok = 1; t.click(); } }]); break; }
        t.disabled = true; t.classList.add('is-busy');
        api('order/' + v.id + '/status', { method: 'POST', body: { status: id } }).then(function (o) { v.data = o; toast('N° ' + o.number + ' : ' + o.status_label, 'ok'); S.stacks.orders[0].data = null; refreshView(); })
          .catch(function (err) { t.disabled = false; t.classList.remove('is-busy'); toast(err.message, 'err'); });
        break;
      // products
      case 'more': fetchProducts(v, true); t.disabled = true; break;
      case 'prod': cur().scroll = window.scrollY; if (S.tab !== 'products') { S.stacks.products = [S.stacks.products[0]]; S.tab = 'products'; save('mcr-tab', 'products'); } push({ v: 'product', id: +id }); break;
      case 'newprod': push({ v: 'product', dirty: false }); break;
      case 'cats': pickCats(v.f.cats, function (ids) { v.f.cats = ids; v.dirty = true; refreshView(); }); break;
      case 'var': editVariation(v, +id); break;
      // promos
      case 'newflash': push({ v: 'flashEdit' }); break;
      case 'flash': push({ v: 'flashEdit', src: S.stacks.promos[0].data.flash.filter(function (x) { return x.id === id; })[0] }); break;
      case 'fprod': pickProducts({ title: 'Produit en vente éclair', done: function (ps) { if (!ps[0]) return; api('product/' + ps[0].id).then(function (p) { v.f.product = { id: p.id, name: p.name, image: p.image, regular: p.type === 'variable' ? 0 : p.regular }; v.dirty = true; refreshView(); }); } }); break;
      case 'fdo':
        var labels = { stop: v.src.state === 'scheduled' ? 'Annuler la vente' : 'Arrêter maintenant', tomorrow: 'Programmer pour demain', delete: 'Supprimer' };
        confirmSheet(labels[id], id === 'delete' ? 'La vente et son historique seront supprimés.' : id === 'stop' ? 'Le prix normal revient tout de suite.' : 'Même produit, même rabais et même quantité, demain à la même heure.', [{ label: labels[id], danger: id !== 'tomorrow', run: function () {
          api('flash/' + v.src.id + '/' + id, { method: 'POST' }).then(function (d) { S.stacks.promos[0].data = d; toast(id === 'tomorrow' ? 'Programmée pour demain' : id === 'stop' ? 'Vente arrêtée' : 'Vente supprimée', 'ok'); goBack(); }).catch(function (err) { toast(err.message, 'err'); });
        } }]);
        break;
      case 'newpromo': push({ v: 'promoEdit' }); break;
      case 'promo': push({ v: 'promoEdit', src: S.stacks.promos[0].data.promos.filter(function (x) { return x.id === id; })[0] }); break;
      case 'pprods': pickProducts({ multi: true, title: 'Produits', selected: v.f.products, done: function (ps) { v.f.products = ps; v.dirty = true; refreshView(); } }); break;
      case 'rmprod': e.stopPropagation(); v.f.products.splice(+id, 1); markDirty(v); refreshView(); break;
      case 'pcats': pickCats(v.f.cats, function (ids) { v.f.cats = ids; v.dirty = true; refreshView(); }); break;
      case 'pdelete':
        confirmSheet('Supprimer la promotion ?', v.src.type === 'percent' ? 'Les prix d’origine seront remis.' : '', [{ label: 'Supprimer', danger: true, run: function () {
          api('promo/' + v.src.id + '/delete', { method: 'POST' }).then(function (d) { S.stacks.promos[0].data = d; toast('Promotion supprimée', 'ok'); goBack(); }).catch(function (err) { toast(err.message, 'err'); });
        } }]);
        break;
      case 'week': pickProducts({ title: 'Promo de la semaine', done: function (ps) { if (!ps[0]) return; api('promo-week', { body: { product: ps[0].id } }).then(function (d) { v.data = d; toast('Promo de la semaine changée', 'ok'); refreshView(); }); } }); break;
      // shop
      case 'stockout': case 'stocklow':
        S.tab = 'products'; save('mcr-tab', 'products'); S.stacks.products = [{ v: 'products', filter: act === 'stockout' ? 'out' : 'low' }]; S.anim = 'fade'; render(); break;
      case 'annsave':
        t.disabled = true;
        api('shop', { body: { announcement: v.ann } }).then(function () { v.annDirty = false; toast('Bandeau enregistré', 'ok'); refreshView(); }).catch(function (err) { t.disabled = false; toast(err.message, 'err'); });
        break;
      case 'push-on':
        enablePush().then(function () { toast('Alertes activées', 'ok'); refreshView(); return testPush(); }).catch(function (err) {
          toast(err.message === 'denied' ? 'Notifications refusées. Autorisez-les dans les réglages.' : err.message === 'ios-install' ? 'Ajoutez d’abord l’app à l’écran d’accueil.' : 'Impossible : ' + err.message, 'err');
        });
        break;
      case 'push-off': disablePush().then(function () { toast('Alertes désactivées sur ce téléphone'); refreshView(); }); break;
      case 'push-test': testPush().then(function (r) { toast(r.sent && r.sent[0] ? 'Notification envoyée' : 'Échec de l’envoi', r.sent && r.sent[0] ? 'ok' : 'err'); }); break;
    }
  });
  function onSeg(v, name, val) {
    tap();
    if (name === 'otab') { v.tab = val; v.data = null; refreshView(); return; }
    if (name === 'pfilter') { v.filter = val; v.data = null; v.scroll = 0; v.loading = false; refreshView(); return; }
    if (name === 'pseg') { v.seg = val; refreshView(); return; }
    if (name === 'fmode') { v.f.mode = val; v.f.value = val === 'percent' ? 20 : (v.f.product && num(v.f.product.regular) ? Math.floor(num(v.f.product.regular) * 0.7 * 100) / 100 : ''); markDirty(v); refreshView(); return; }
    if (name === 'fhours') { v.f.hours = +val; markDirty(v); refreshView(); return; }
    if (name === 'ptype') { v.f.type = val; markDirty(v); refreshView(); }
  }
  function stepField(v, b) {
    var k = b.dataset.step, f = sheet ? null : v.f;
    if (!f) return;
    f[k] = Math.max(+b.dataset.min, Math.min(+b.dataset.max, (+f[k] || 0) + +b.dataset.d));
    tap(); markDirty(v);
    var out = document.querySelector('[data-out="' + k + '"]'); if (out) out.textContent = f[k];
    if (k === 'percent' || k === 'per_order') refreshView(); else updateHints(v);
  }
  var searchT = null;
  app.addEventListener('input', function (e) {
    var k = e.target.dataset.f, v = cur();
    if (!k) return;
    if (v.v === 'products' && k === 'q') { v.q = e.target.value; clearTimeout(searchT); searchT = setTimeout(function () { fetchProducts(v); }, 300); return; }
    if (v.v === 'shop') {
      if (k === 'ann_fr') v.ann.text_fr = e.target.value; if (k === 'ann_en') v.ann.text_en = e.target.value;
      v.annDirty = true; var b = document.querySelector('[data-act="annsave"]'); if (b) b.disabled = false; return;
    }
    if (v.f && e.target.type !== 'checkbox') {
      v.f[k] = e.target.value; markDirty(v); updateHints(v);
    }
  });
  app.addEventListener('change', function (e) {
    var k = e.target.dataset.f, v = cur();
    if (e.target.dataset.act === 'photo') {
      var file = e.target.files && e.target.files[0]; if (!file) return;
      v.uploading = true; refreshView();
      uploadPhoto(file, v.f.name).then(function (m) { v.uploading = false; v.f.image_id = m.id; v.f.image = m.url; v.dirty = true; toast('Photo prête. Touchez Enregistrer.', 'ok'); refreshView(); })
        .catch(function (err) { v.uploading = false; toast(err.message, 'err'); refreshView(); });
      return;
    }
    if (!k) return;
    if (e.target.type === 'checkbox') {
      tap();
      if (v.v === 'shop') {
        if (k === 'ann_on') { v.ann.enabled = e.target.checked; v.annDirty = true; var b = document.querySelector('[data-act="annsave"]'); if (b) b.disabled = false; return; }
        var body = {}; body[k] = e.target.checked;
        api('shop', { body: body }).then(function () { v.data[k] = e.target.checked; toast(k === 'delivery' ? (e.target.checked ? 'Livraison activée' : 'Livraison désactivée') : (e.target.checked ? 'Retrait activé' : 'Retrait désactivé'), 'ok'); refreshView(); })
          .catch(function (err) { e.target.checked = !e.target.checked; toast(err.message, 'err'); });
        return;
      }
      if (!v.f) return;
      if (k === 'instock') v.f.stock = e.target.checked ? 'instock' : 'outofstock'; else v.f[k] = e.target.checked;
      markDirty(v);
      if (k === 'manage' || k === 'status' || k === 'instock') refreshView();
      return;
    }
    if (v.f && (k === 'date' || k === 'start' || k === 'end' || k === 'sale_to' || k === 'time')) { v.f[k] = e.target.value; markDirty(v); }
  });
  app.addEventListener('submit', function (e) { e.preventDefault(); });
  app.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.matches('input[type=search]')) e.target.blur(); });

  if (navigator.serviceWorker) navigator.serviceWorker.addEventListener('message', function (e) {
    if (!e.data) return;
    if (e.data.type === 'mcr-push') { chime(); var o = S.stacks.orders && S.stacks.orders[0]; if (o) fetchOrders(o, true); }
    if (e.data.type === 'mcr-open') { var m = String(e.data.url).match(/order-(\d+)/); if (m && S.stacks.orders) { S.tab = 'orders'; S.stacks.orders = [S.stacks.orders[0], { v: 'order', id: m[1] }]; render(); } }
  });
  function poll() { var o = S.stacks.orders && S.stacks.orders[0]; if (o && !document.hidden && !sheet && !(cur().f && cur().dirty)) fetchOrders(o, true); }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  setInterval(poll, 20000);

  // ---------- Start ----------
  registerSW().then(checkPush).then(function () {
    var m = location.hash.match(/order-(\d+)/);
    if (m && S.stacks.orders) { S.tab = 'orders'; S.stacks.orders.push({ v: 'order', id: m[1] }); history.replaceState(null, '', location.pathname); }
    render();
    if (S.tab !== 'orders' && S.stacks.orders) fetchOrders(S.stacks.orders[0], true);
  });
})();
