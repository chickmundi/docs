/* Welcome wheel + referral: spin animation, confetti, live countdown, gift button, friend welcome. */
(function () {
  var root = document.getElementById('mcr-rw');
  if (!root) return;
  var en = root.getAttribute('data-lang') === 'en';
  var T = function (fr, e) { return en ? e : fr; };
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var now = function () { return Math.floor(Date.now() / 1000); };
  var ck = function (n) { var m = document.cookie.match('(?:^|; )' + n + '=([^;]*)'); return m ? decodeURIComponent(m[1]) : ''; };
  var setCk = function (n, v, s) { document.cookie = n + '=' + v + '; path=/; max-age=' + s + '; SameSite=Lax'; };
  var dlg = document.getElementById('rw-dlg');
  var friend = document.getElementById('rw-friend');
  var fab = root.querySelector('[data-rw-open]');
  var state = null, timerId = null;
  // A random id for this device (one spin per device).
  function device() {
    var id = ck('mcr_dev');
    try { id = id || localStorage.getItem('mcr_dev') || ''; } catch (x) {}
    if (!id) id = (Date.now().toString(36) + Math.random().toString(36).slice(2, 12));
    try { localStorage.setItem('mcr_dev', id); } catch (x) {}
    setCk('mcr_dev', id, 2 * 365 * 86400);
    return id;
  }

  // ---------- Confetti (canvas inside the open window) ----------
  function confetti(d) {
    if (reduce || !d) return;
    var cv = d.querySelector('.rw-confetti');
    if (!cv) return;
    var r = d.getBoundingClientRect(), dpr = Math.min(2, window.devicePixelRatio || 1);
    cv.width = r.width * dpr; cv.height = r.height * dpr;
    var c = cv.getContext('2d'); c.scale(dpr, dpr);
    var cols = ['#e6b74f', '#c96b43', '#174b35', '#f2d48b', '#1fa855', '#ffffff', '#a9532f'];
    var ps = [];
    for (var i = 0; i < 160; i++) {
      ps.push({ x: r.width / 2, y: r.height * 0.42, vx: (Math.random() - 0.5) * 14, vy: -Math.random() * 13 - 4, s: 5 + Math.random() * 6, a: Math.random() * 6.28, va: (Math.random() - 0.5) * 0.4, c: cols[i % cols.length], round: Math.random() < 0.3 });
    }
    var t0 = performance.now();
    (function frame(t) {
      var el = t - t0;
      c.clearRect(0, 0, r.width, r.height);
      ps.forEach(function (p) {
        p.vy += 0.35; p.vx *= 0.99; p.x += p.vx; p.y += p.vy; p.a += p.va;
        c.save(); c.globalAlpha = Math.max(0, 1 - el / 2800); c.translate(p.x, p.y); c.rotate(p.a); c.fillStyle = p.c;
        if (p.round) { c.beginPath(); c.arc(0, 0, p.s / 2.4, 0, 6.28); c.fill(); } else c.fillRect(-p.s / 2, -p.s / 4, p.s, p.s / 2);
        c.restore();
      });
      if (el < 2800) requestAnimationFrame(frame); else c.clearRect(0, 0, r.width, r.height);
    })(t0);
  }

  // ---------- Countdown ----------
  function fmt(left) {
    if (left <= 0) return T('expiré', 'expired');
    var d = Math.floor(left / 86400), h = Math.floor(left % 86400 / 3600), m = Math.floor(left % 3600 / 60), s = left % 60;
    var p = function (v) { return (v < 10 ? '0' : '') + v; };
    return (d ? d + T(' j ', ' d ') : '') + p(h) + ':' + p(m) + ':' + p(s);
  }
  function tick() {
    if (!state || !state.expires) return;
    var left = state.expires - now();
    var t = dlg && dlg.querySelector('[data-rw="timer"]');
    if (t) t.textContent = fmt(left);
    if (fab && !state.used) {
      fab.querySelector('[data-rw-fab-txt]').textContent = left > 0 ? state.prize + ' · ' + fmt(left) : T('Cadeau', 'Gift');
      fab.classList.toggle('is-timer', left > 0);
      fab.classList.toggle('is-hurry', left > 0 && left < 6 * 3600);
    }
  }

  // ---------- Fill the "you won" panel ----------
  function fill(st) {
    state = st;
    if (!dlg) return;
    var q = function (k) { return dlg.querySelector('[data-rw="' + k + '"]'); };
    q('prize').textContent = st.prize;
    q('conditions').textContent = st.conditions;
    q('coupon').textContent = st.coupon;
    q('use').href = st.use;
    q('used').hidden = !st.used;
    q('timerwrap').hidden = st.used || !st.expires || st.expires < now();
    q('use').hidden = st.used || (st.expires && st.expires < now());
    if (st.ref && q('refTitle')) {
      q('refTitle').textContent = T('Offrez ' + st.ref.friend + ', recevez ' + st.ref.reward, 'Give ' + st.ref.friend + ', get ' + st.ref.reward);
      q('refText').textContent = T('Vos amis reçoivent ' + st.ref.friend + ' sur leur 1re commande (dès ' + st.ref.min + '). Vous recevez ' + st.ref.reward + ' quand leur commande est terminée.',
        'Your friends get ' + st.ref.friend + ' off their first order (from ' + st.ref.min + '). You get ' + st.ref.reward + ' when their order is completed.');
      var msg = T('Salut ! Voici ' + st.ref.friend + ' de rabais sur ta première commande au Marché Chemin du Roy, l’épicerie africaine de Trois-Rivières : ', 'Hi! Here is ' + st.ref.friend + ' off your first order at Marché Chemin du Roy, the African grocery in Trois-Rivières: ') + st.ref.link;
      q('refWa').href = 'https://wa.me/?text=' + encodeURIComponent(msg);
      dlg.querySelector('.rw-invite [data-rw-copy]').setAttribute('data-rw-copy', st.ref.link);
      var got = q('refGot');
      if (st.ref.friends || st.ref.rewards.length) {
        got.hidden = false;
        got.textContent = T(st.ref.friends + ' ami(s) ont commandé avec votre lien.', st.ref.friends + ' friend(s) ordered with your link.') + (st.ref.rewards.length ? T(' Vos codes : ', ' Your codes: ') + st.ref.rewards.join(', ') : '');
      }
    }
    if (fab) { fab.hidden = false; fab.classList.add('is-won'); if (st.used || !st.expires) fab.querySelector('[data-rw-fab-txt]').textContent = st.ref ? T('Inviter', 'Invite') : T('Cadeau', 'Gift'); }
    clearInterval(timerId); tick(); timerId = setInterval(tick, 1000);
  }
  function showStep(name) {
    dlg.querySelectorAll('[data-rw-step]').forEach(function (s) { s.hidden = s.getAttribute('data-rw-step') !== name; });
    dlg.classList.toggle('is-win', name === 'win');
  }
  function open(d) {
    if (!d || d.open) return;
    if (document.querySelector('dialog[open]')) return;
    d.showModal();
  }

  // ---------- The spin ----------
  var rot = dlg && dlg.querySelector('.rw-wheel__rot');
  var ptr = dlg && dlg.querySelector('.rw-wheel__ptr');
  var n = +root.getAttribute('data-segments') || 6, seg = 360 / n;
  var angle = 0, idleRaf = null, spinning = false;
  function setAngle(a) { angle = a; if (rot) rot.setAttribute('transform', 'rotate(' + (a % 360).toFixed(2) + ' 100 100)'); }
  // Gentle idle rotation so the wheel looks alive.
  function idle() {
    if (reduce || !rot) return;
    var last = performance.now();
    (function f(t) { if (spinning) return; setAngle(angle + (t - last) * 0.012); last = t; idleRaf = requestAnimationFrame(f); })(last);
  }
  function spinTo(index, done) {
    spinning = true; cancelAnimationFrame(idleRaf);
    var start = angle, center = index * seg + seg / 2, jitter = (Math.random() - 0.5) * seg * 0.6;
    var target = start - (start % 360) + 360 * 7 + (360 - center) + jitter;
    if (reduce) { setAngle(target); done(); return; }
    var dur = 5600, t0 = performance.now(), lastSeg = Math.floor(start / seg);
    var ease = function (x) { return 1 - Math.pow(1 - x, 4); };
    (function f(t) {
      var x = Math.min(1, (t - t0) / dur), a = start + (target - start) * ease(x);
      setAngle(a);
      var s = Math.floor(a / seg);
      if (s !== lastSeg && ptr) { lastSeg = s; ptr.classList.remove('is-tick'); void ptr.offsetWidth; ptr.classList.add('is-tick'); }
      if (x < 1) requestAnimationFrame(f); else { spinning = false; done(); }
    })(t0);
  }

  var form = dlg && dlg.querySelector('#rw-form');
  if (form) {
    var err = form.querySelector('.rw-err');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (spinning) return;
      err.hidden = true;
      if (!form.phone.value.trim()) { err.textContent = T('Entrez votre numéro WhatsApp pour tourner la roue.', 'Enter your WhatsApp number to spin the wheel.'); err.hidden = false; form.phone.focus(); return; }
      if (!form.consent.checked) { err.textContent = T('Cochez la case pour accepter nos messages WhatsApp.', 'Please tick the box to accept our WhatsApp messages.'); err.hidden = false; return; }
      dlg.classList.add('is-spinning');
      var body = new URLSearchParams(new FormData(form));
      body.append('lang', en ? 'en' : 'fr');
      body.append('dev', device());
      fetch(root.getAttribute('data-ajax-spin'), { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          if (!r || !r.success) { dlg.classList.remove('is-spinning'); err.textContent = (r && r.data && r.data.message) || T('Erreur, réessayez.', 'Error, please try again.'); err.hidden = false; return; }
          var st = r.data;
          spinTo(Math.max(0, st.segment), function () {
            dlg.classList.remove('is-spinning');
            fill(st);
            setTimeout(function () { showStep('win'); confetti(dlg); }, reduce ? 0 : 450);
          });
        })
        .catch(function () { dlg.classList.remove('is-spinning'); err.textContent = T('Erreur réseau, réessayez.', 'Network error, please try again.'); err.hidden = false; });
    });
  }

  // ---------- Buttons ----------
  document.addEventListener('click', function (e) {
    var c = e.target.closest('[data-rw-copy]');
    if (c) {
      var v = c.getAttribute('data-rw-copy');
      (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).catch(function () {}).then(function () {
        var old = c.textContent; c.textContent = T('Copié !', 'Copied!'); setTimeout(function () { c.textContent = old; }, 1600);
      });
    }
    if (e.target.closest('[data-rw-open]')) { if (state) showStep('win'); open(dlg); if (!state) idle(); }
  });
  [dlg, friend].forEach(function (d) {
    if (!d) return;
    d.addEventListener('click', function (e) {
      if (e.target === d || e.target.closest('[data-rw-close]')) { if (!e.target.closest('a')) e.preventDefault(); d.close(); }
      if (e.target.closest('[data-rw-later]')) { e.preventDefault(); setCk('mcr_wheel_later', '1', 2 * 86400); d.close(); }
    });
  });

  // ---------- Start ----------
  if (dlg && ck('mcr_spun')) {
    fetch(root.getAttribute('data-ajax-me'), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) { if (r && r.success) fill(r.data); else if (fab) fab.hidden = false; }).catch(function () {});
  } else if (fab) {
    fab.hidden = false;
  }

  // A friend arriving with a referral link: welcome them first.
  var fromRef = /[?&]ref=/.test(location.search) && ck('mcr_ref');
  if (fromRef && friend) {
    var shown = false; try { shown = sessionStorage.getItem('mcr_ref_seen') === ck('mcr_ref'); sessionStorage.setItem('mcr_ref_seen', ck('mcr_ref')); } catch (x) {}
    if (!shown) setTimeout(function () { open(friend); confetti(friend); }, 900);
  }

  // The wheel opens by itself once, after the promotions window (or after a couple of pages).
  if (dlg && root.getAttribute('data-auto') === '1' && !ck('mcr_spun') && !ck('mcr_wheel_later') && !fromRef) {
    var pages = 0; try { pages = +(sessionStorage.getItem('mcr_rw_pages') || 0) + 1; sessionStorage.setItem('mcr_rw_pages', pages); } catch (x) {}
    var promoAt = +ck('mcr_pp_seen') || 0;
    if ((promoAt && now() - promoAt > 40) || pages >= 2) {
      var tries = 0;
      var go = function () {
        if (document.querySelector('dialog[open]') || document.hidden) { if (++tries < 6) setTimeout(go, 12000); return; }
        showStep('wheel'); open(dlg); idle();
      };
      setTimeout(go, 9000 + Math.random() * 7000);
    }
  }
})();
