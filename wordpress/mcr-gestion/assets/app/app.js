/* MCR Commandes: the store team's order app (installable, push notifications). */
(function () {
  'use strict';
  var C = window.MCR_APP || {};
  var app = document.getElementById('app');
  var state = { tab: 'open', data: null, order: null, loading: false, seen: load('mcr-seen', {}), pushOn: false };
  var STATUS_COLORS = { processing: 'blue', 'on-hold': 'amber', 'mcr-preparing': 'amber', 'mcr-ready': 'green', 'mcr-out': 'violet', completed: 'grey', cancelled: 'red', refunded: 'red', failed: 'red', pending: 'grey' };

  function load(k, d) { try { return JSON.parse(localStorage.getItem(k)) || d; } catch (e) { return d; } }
  function save(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ago(ts) {
    var s = Math.max(0, Date.now() / 1000 - ts);
    if (s < 60) return 'à l’instant';
    if (s < 3600) return 'il y a ' + Math.floor(s / 60) + ' min';
    if (s < 86400) return 'il y a ' + Math.floor(s / 3600) + ' h';
    var d = new Date(ts * 1000);
    return d.toLocaleDateString('fr-CA', { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString('fr-CA', { hour: '2-digit', minute: '2-digit' });
  }
  var ICON = {
    bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    refresh: '<path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M8 16H3v5"/>',
    phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>',
    map: '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
    back: '<path d="m15 18-6-6 6-6"/>',
    truck: '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
    store: '<path d="m2 7 4.4-4.4A2 2 0 0 1 7.8 2h8.4a2 2 0 0 1 1.4.6L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/>',
    chat: '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
    note: '<path d="M15.5 3H5a2 2 0 0 0-2 2v14c0 1.1.9 2 2 2h14a2 2 0 0 0 2-2V8.5L15.5 3Z"/><path d="M15 3v6h6"/>',
    out: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
    ext: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
  };
  function ic(n, s) { return '<svg width="' + (s || 20) + '" height="' + (s || 20) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICON[n] + '</svg>'; }

  // ---------- API ----------
  function api(path, opts) {
    opts = opts || {};
    var headers = { 'X-WP-Nonce': C.nonce, Accept: 'application/json' };
    if (opts.body) headers['Content-Type'] = 'application/json';
    return fetch(C.rest + path, { method: opts.method || 'GET', credentials: 'same-origin', headers: headers, body: opts.body ? JSON.stringify(opts.body) : undefined, cache: 'no-store' })
      .then(function (r) {
        if (r.status === 401 || r.status === 403) { location.reload(); throw new Error('auth'); }
        return r.json().then(function (j) { if (!r.ok) throw new Error(j && j.message || r.status); return j; });
      });
  }

  // ---------- Sound when an order arrives while the app is open ----------
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

  // ---------- Push notifications ----------
  var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
  var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  var pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  var swReg = null;

  function b64ToBytes(b64) {
    var pad = '='.repeat((4 - (b64.length % 4)) % 4);
    var raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }
  function deviceName() {
    var ua = navigator.userAgent;
    var m = ua.match(/\(([^)]+)\)/);
    var os = /iphone/i.test(ua) ? 'iPhone' : /ipad/i.test(ua) ? 'iPad' : /android/i.test(ua) ? 'Android' + ((ua.match(/Android [\d.]+; ([^;)]+)/) || [])[1] ? ' · ' + (ua.match(/Android [\d.]+; ([^;)]+)/) || [])[1] : '') : m ? m[1].split(';')[0] : 'Appareil';
    var br = /edg/i.test(ua) ? 'Edge' : /chrome|crios/i.test(ua) ? 'Chrome' : /firefox/i.test(ua) ? 'Firefox' : /safari/i.test(ua) ? 'Safari' : '';
    return os + (br ? ' · ' + br : '') + (standalone ? ' · app' : '');
  }
  function registerSW() {
    if (!('serviceWorker' in navigator)) return Promise.resolve(null);
    return navigator.serviceWorker.register(C.sw, { scope: C.scope }).then(function (r) { swReg = r; return r; }).catch(function () { return null; });
  }
  function checkPush() {
    if (!pushSupported || !swReg) { state.pushOn = false; return Promise.resolve(false); }
    return swReg.pushManager.getSubscription().then(function (s) {
      state.pushOn = !!s && Notification.permission === 'granted';
      if (s && state.pushOn) api('subscribe', { method: 'POST', body: { subscription: s.toJSON(), device: deviceName() } }).catch(function () {});
      return state.pushOn;
    });
  }
  function enablePush() {
    if (!pushSupported) return Promise.reject(new Error(isIOS && !standalone ? 'ios-install' : 'unsupported'));
    return Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') throw new Error('denied');
      return (swReg ? Promise.resolve(swReg) : registerSW()).then(function (reg) {
        return reg.pushManager.getSubscription().then(function (s) {
          return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(C.vapid) });
        });
      });
    }).then(function (sub) {
      return api('subscribe', { method: 'POST', body: { subscription: sub.toJSON(), device: deviceName() } }).then(function () { state.pushOn = true; return sub; });
    });
  }
  function disablePush() {
    if (!swReg) return Promise.resolve();
    return swReg.pushManager.getSubscription().then(function (s) {
      if (!s) return;
      return api('unsubscribe', { method: 'POST', body: { endpoint: s.endpoint } }).finally(function () { return s.unsubscribe(); });
    }).then(function () { state.pushOn = false; });
  }
  function testPush() {
    return swReg.pushManager.getSubscription().then(function (s) { return api('test', { method: 'POST', body: { endpoint: s ? s.endpoint : '' } }); });
  }

  // ---------- Data ----------
  var lastOpenIds = null;
  function refresh(silent) {
    if (state.loading) return Promise.resolve();
    state.loading = true;
    if (!silent) renderLoading();
    return api('orders?tab=' + state.tab).then(function (d) {
      if (d.nonce) C.nonce = d.nonce;
      var ids = d.orders.filter(function (o) { return o.status === 'processing' || o.status === 'on-hold'; }).map(function (o) { return o.id; });
      if (lastOpenIds && state.tab === 'open' && ids.some(function (id) { return lastOpenIds.indexOf(id) === -1; })) chime();
      if (state.tab === 'open') lastOpenIds = ids;
      // First use on this phone: what is already there is not "new".
      if (!localStorage.getItem('mcr-seen-init')) {
        d.orders.forEach(function (o) { state.seen[o.id] = 1; });
        save('mcr-seen', state.seen);
        try { localStorage.setItem('mcr-seen-init', '1'); } catch (e) {}
      }
      state.data = d;
      if (navigator.setAppBadge) { d.open ? navigator.setAppBadge(d.open).catch(function () {}) : navigator.clearAppBadge().catch(function () {}); }
      state.loading = false;
      if (!state.order) renderList();
    }).catch(function (e) { state.loading = false; if (e.message !== 'auth') toast('Connexion impossible. Nouvel essai dans quelques secondes.'); });
  }
  function openOrder(id) {
    state.order = { id: id, loading: true };
    renderOrder();
    api('order/' + id).then(function (o) {
      state.order = o;
      state.seen[id] = 1; save('mcr-seen', state.seen);
      renderOrder();
    }).catch(function () { toast('Commande introuvable.'); goList(); });
  }
  function goList() {
    state.order = null;
    if (location.hash) history.replaceState(null, '', location.pathname);
    renderList();
    refresh(true);
  }
  function setStatus(id, status, btn) {
    btn.disabled = true; btn.classList.add('is-busy');
    api('order/' + id + '/status', { method: 'POST', body: { status: status } }).then(function (o) {
      state.order = o; renderOrder(); toast('Commande n° ' + o.number + ' : ' + o.status_label);
      if (navigator.vibrate) navigator.vibrate(40);
    }).catch(function (e) { btn.disabled = false; btn.classList.remove('is-busy'); toast('Impossible : ' + e.message); });
  }

  // ---------- Views ----------
  function header(extra) {
    return '<header class="bar"><div class="bar__t"><strong>Commandes</strong><small>' + esc(C.store || '') + '</small></div>' + (extra || '') +
      '<button class="iconbtn" data-act="refresh" aria-label="Actualiser">' + ic('refresh') + '</button></header>';
  }
  function pushBanner() {
    if (state.pushOn) return '';
    if (isIOS && !standalone) {
      return '<div class="banner banner--warn"><strong>Installez l’application pour recevoir les alertes</strong><span>Dans Safari : touchez <b>Partager</b> puis <b>Sur l’écran d’accueil</b>. Ouvrez ensuite « Commandes » depuis l’écran d’accueil.</span></div>';
    }
    if (!pushSupported) return '<div class="banner banner--warn"><strong>Notifications non disponibles</strong><span>Ce navigateur ne permet pas les notifications. Utilisez Chrome (Android) ou l’app installée (iPhone).</span></div>';
    if (Notification.permission === 'denied') return '<div class="banner banner--warn"><strong>Notifications bloquées</strong><span>Autorisez les notifications pour ce site dans les réglages du téléphone, puis revenez ici.</span></div>';
    return '<div class="banner"><div>' + ic('bell', 26) + '</div><div><strong>Recevez chaque nouvelle commande en direct</strong><span>Une alerte sonne sur ce téléphone dès qu’une commande est payée.</span></div><button class="btn btn--gold" data-act="push-on">Activer les notifications</button></div>';
  }
  function renderLoading() {
    if (state.data) return;
    app.innerHTML = header() + '<main class="main"><div class="skel"></div><div class="skel"></div><div class="skel"></div></main>';
  }
  function chip(o) { return '<span class="chip chip--' + (STATUS_COLORS[o.status] || 'grey') + '">' + esc(o.status_label) + '</span>'; }
  function renderList() {
    var d = state.data || { orders: [], open: 0, today: { count: 0, total: '' } };
    var tabs = [['open', 'À traiter' + (d.open ? ' · ' + d.open : '')], ['done', 'Terminées'], ['all', 'Toutes']];
    var list = d.orders.length ? d.orders.map(function (o) {
      var fresh = (o.status === 'processing' || o.status === 'on-hold') && !state.seen[o.id];
      return '<li><button class="card' + (fresh ? ' is-new' : '') + '" data-order="' + o.id + '">' +
        '<span class="card__top"><strong>n° ' + esc(o.number) + '</strong>' + chip(o) + '<span class="card__ago">' + ago(o.date) + '</span></span>' +
        '<span class="card__mid"><span class="card__name">' + esc(o.name || 'Client') + '</span><span class="card__total">' + esc(o.total_label) + '</span></span>' +
        '<span class="card__bot"><span class="mode mode--' + esc(o.mode || 'none') + '">' + (o.mode === 'pickup' ? ic('store', 15) + ' Retrait' : o.mode === 'delivery' ? ic('truck', 15) + ' Livraison' : '') + '</span>' +
        (o.slot ? '<span class="slot">🕒 ' + esc(o.slot) + '</span>' : '') + '<span>' + o.items_count + ' article' + (o.items_count > 1 ? 's' : '') + '</span>' + (o.paid ? '<span class="paid">Payée</span>' : '<span class="unpaid">À encaisser</span>') + '</span>' +
        (fresh ? '<span class="dot" aria-label="Nouvelle"></span>' : '') + '</button></li>';
    }).join('') : '<li class="empty">' + (state.tab === 'open' ? '🎉 Aucune commande à traiter pour le moment.' : 'Aucune commande.') + '</li>';
    app.innerHTML = header() +
      '<main class="main">' + pushBanner() +
      '<section class="stats"><div><small>À traiter</small><strong>' + d.open + '</strong></div><div><small>Aujourd’hui</small><strong>' + d.today.count + '</strong></div><div><small>Ventes du jour</small><strong>' + esc(d.today.total || '–') + '</strong></div></section>' +
      '<nav class="tabs">' + tabs.map(function (t) { return '<button data-tab="' + t[0] + '"' + (state.tab === t[0] ? ' aria-current="true"' : '') + '>' + t[1] + '</button>'; }).join('') + '</nav>' +
      '<ul class="list">' + list + '</ul>' +
      '<footer class="foot">' + (state.pushOn ? '<button class="link" data-act="push-test">' + ic('bell', 16) + ' Tester la notification</button> · <button class="link" data-act="push-off">Désactiver sur ce téléphone</button>' : '') +
      '<p>Connecté : ' + esc(C.user) + ' · <a href="' + esc(C.logout) + '">Se déconnecter</a></p></footer></main>';
  }
  function renderOrder() {
    var o = state.order;
    if (!o || o.loading) {
      app.innerHTML = header('<button class="iconbtn iconbtn--back" data-act="back" aria-label="Retour">' + ic('back') + '</button>') + '<main class="main"><div class="skel skel--big"></div></main>';
      return;
    }
    var digits = (o.phone || '').replace(/\D/g, '');
    var wa = digits ? 'https://wa.me/' + (digits.length === 10 ? '1' + digits : digits) + '?text=' + encodeURIComponent('Bonjour ' + (o.name || '') + ', ici le Marché Chemin-du-Roy au sujet de votre commande n° ' + o.number + '.') : '';
    var maps = o.address ? 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(o.address) : '';
    var adminLink = (C.hpos ? C.adminHpos : C.admin) + o.id;
    app.innerHTML = header('<button class="iconbtn iconbtn--back" data-act="back" aria-label="Retour">' + ic('back') + '</button>') +
      '<main class="main main--order">' +
      '<section class="ohead"><div><h1>Commande n° ' + esc(o.number) + '</h1><p>' + ago(o.date) + ' · ' + esc(o.payment || '') + '</p></div>' + chip(o) + '</section>' +
      (o.next.length ? '<section class="steps">' + o.next.map(function (n, i) { return '<button class="btn ' + (i === 0 ? 'btn--primary' : 'btn--soft') + '" data-status="' + n.status + '">' + esc(n.label) + '</button>'; }).join('') + '</section>' : '') +
      '<section class="box"><div class="box__row"><div><small>Client</small><strong>' + esc(o.name || '—') + '</strong>' + (o.phone ? '<span>' + esc(o.phone) + '</span>' : '') + '</div></div>' +
      '<div class="actions">' + (digits ? '<a class="btn btn--soft" href="tel:' + esc(digits) + '">' + ic('phone', 18) + ' Appeler</a><a class="btn btn--wa" href="' + esc(wa) + '" target="_blank" rel="noopener">' + ic('chat', 18) + ' WhatsApp</a>' : '') + '</div></section>' +
      '<section class="box"><div class="box__row"><div><small>' + (o.mode === 'pickup' ? 'Retrait en magasin' : 'Livraison') + '</small><strong>' + esc(o.mode === 'pickup' ? (o.slot ? '🕒 ' + o.slot : 'Le client vient chercher la commande') : (o.address || 'Adresse non indiquée')) + '</strong>' + (o.distance ? '<span>' + esc(o.distance) + ' · ' + esc(o.shipping_total) + '</span>' : '') + '</div>' + (o.mode === 'pickup' ? ic('store', 26) : ic('truck', 26)) + '</div>' +
      (maps ? '<div class="actions"><a class="btn btn--soft" href="' + esc(maps) + '" target="_blank" rel="noopener">' + ic('map', 18) + ' Ouvrir dans Maps</a></div>' : '') + '</section>' +
      (o.note ? '<section class="box box--note">' + ic('note', 20) + '<div><small>Note du client</small><p>' + esc(o.note) + '</p></div></section>' : '') +
      '<section class="box"><small>' + o.items_count + ' article' + (o.items_count > 1 ? 's' : '') + '</small><ul class="items">' + o.items.map(function (it) {
        return '<li><span class="items__img">' + (it.image ? '<img src="' + esc(it.image) + '" alt="" loading="lazy">' : '') + '</span><span class="items__q">' + it.qty + '×</span><span class="items__n">' + esc(it.name) + (it.meta.length ? '<small>' + esc(it.meta.join(' · ')) + '</small>' : '') + (it.sku ? '<small>UGS ' + esc(it.sku) + '</small>' : '') + '</span><span class="items__t">' + esc(it.total) + '</span></li>';
      }).join('') + '</ul><div class="total"><span>Total</span><strong>' + esc(o.total_label) + '</strong></div><p class="paystate">' + (o.paid ? '✅ Payée' : '💵 À encaisser à la livraison ou au retrait') + '</p></section>' +
      '<a class="link link--admin" href="' + esc(adminLink) + '" target="_blank" rel="noopener">' + ic('ext', 16) + ' Ouvrir dans WordPress</a>' +
      '</main>';
  }

  var toastTimer = null;
  function toast(msg) {
    var t = document.querySelector('.toast');
    if (!t) { t = document.createElement('div'); t.className = 'toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
    t.textContent = msg; t.classList.add('is-on');
    clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.classList.remove('is-on'); }, 2800);
  }

  // ---------- Events ----------
  app.addEventListener('click', function (e) {
    var el = e.target.closest('[data-order],[data-tab],[data-act],[data-status]');
    if (!el) return;
    if (el.dataset.order) { history.pushState(null, '', '#order-' + el.dataset.order); openOrder(el.dataset.order); return; }
    if (el.dataset.tab) { state.tab = el.dataset.tab; state.data = null; refresh(); return; }
    if (el.dataset.status) { setStatus(state.order.id, el.dataset.status, el); return; }
    var act = el.dataset.act;
    if (act === 'refresh') { state.order ? openOrder(state.order.id) : refresh(); }
    if (act === 'back') history.length > 1 && location.hash ? history.back() : goList();
    if (act === 'push-on') {
      el.disabled = true;
      enablePush().then(function () { toast('Notifications activées ✅'); renderList(); return testPush(); }).catch(function (err) {
        el.disabled = false;
        toast(err.message === 'denied' ? 'Notifications refusées. Autorisez-les dans les réglages.' : err.message === 'ios-install' ? 'Installez d’abord l’app sur l’écran d’accueil.' : 'Impossible d’activer : ' + err.message);
      });
    }
    if (act === 'push-off') disablePush().then(function () { toast('Notifications désactivées sur ce téléphone.'); renderList(); });
    if (act === 'push-test') testPush().then(function (r) { toast(r.sent && r.sent[0] ? 'Notification envoyée.' : 'Échec de l’envoi.'); });
  });
  window.addEventListener('popstate', function () { var m = location.hash.match(/order-(\d+)/); m ? openOrder(m[1]) : goList(); });
  if (navigator.serviceWorker) navigator.serviceWorker.addEventListener('message', function (e) {
    if (!e.data) return;
    if (e.data.type === 'mcr-push') { chime(); refresh(true); }
    if (e.data.type === 'mcr-open') { var m = String(e.data.url).match(/order-(\d+)/); if (m) { history.pushState(null, '', '#order-' + m[1]); openOrder(m[1]); } }
  });
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { state.order ? null : refresh(true); } });
  setInterval(function () { if (!document.hidden && !state.order) refresh(true); }, 20000);

  // ---------- Start ----------
  registerSW().then(checkPush).then(function () {
    var m = location.hash.match(/order-(\d+)/);
    if (m) openOrder(m[1]); else refresh();
  });
})();
