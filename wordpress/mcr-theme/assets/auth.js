/* Customer sign-in / account creation: switch, show password, strength, checks, busy button. */
(function () {
  'use strict';
  var root = document.querySelector('[data-auth]');
  if (!root) return;
  var C = window.MCR_AUTH || {};
  var track = root.querySelector('.auth__track');
  var panes = { login: root.querySelector('[data-pane="login"]'), register: root.querySelector('[data-pane="register"]') };
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ---------- Switch between "Se connecter" and "Créer un compte" ----------
  function show(mode, animate) {
    if (!panes[mode]) mode = 'login';
    var from = root.getAttribute('data-mode');
    if (from === mode) return;
    var startH = track.offsetHeight;
    root.setAttribute('data-mode', mode);
    Object.keys(panes).forEach(function (k) {
      if (!panes[k]) return;
      panes[k].hidden = k !== mode;
      panes[k].classList.remove('is-enter', 'is-enter-back');
    });
    root.querySelectorAll('[role="tab"]').forEach(function (t) { t.setAttribute('aria-selected', t.getAttribute('data-tab') === mode ? 'true' : 'false'); });
    root.querySelectorAll('[data-title-login]').forEach(function (h) { h.hidden = mode !== 'login'; });
    root.querySelectorAll('[data-title-register]').forEach(function (h) { h.hidden = mode !== 'register'; });
    if (animate && !reduce) {
      panes[mode].classList.add(mode === 'register' ? 'is-enter' : 'is-enter-back');
      // Smooth height change between the two forms.
      var endH = track.offsetHeight;
      track.style.height = startH + 'px';
      track.offsetHeight; // eslint-disable-line no-unused-expressions
      track.style.height = endH + 'px';
      setTimeout(function () { track.style.height = ''; }, 480);
      var first = panes[mode].querySelector('input:not([type=hidden]):not([type=checkbox])');
      if (first && window.matchMedia('(pointer: fine)').matches) setTimeout(function () { first.focus({ preventScroll: true }); }, 260);
    }
    try { history.replaceState(null, '', mode === 'register' ? '#creer' : location.pathname + location.search); } catch (e) {}
  }
  root.addEventListener('click', function (e) {
    var t = e.target.closest('[data-tab]');
    if (t) { e.preventDefault(); show(t.getAttribute('data-tab'), true); }
  });
  root.querySelector('.auth__switch') && root.querySelector('.auth__switch').addEventListener('keydown', function (e) {
    if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
      var next = root.getAttribute('data-mode') === 'login' ? 'register' : 'login';
      show(next, true); root.querySelector('[data-tab="' + next + '"][role="tab"]').focus();
    }
  });
  var start = root.getAttribute('data-start');
  if (/^#(creer|register|inscription)$/.test(location.hash)) start = 'register';
  show(start, false);

  // ---------- Show / hide password ----------
  root.querySelectorAll('[data-eye]').forEach(function (b) {
    b.addEventListener('click', function () {
      var input = b.parentNode.querySelector('input');
      var on = input.type === 'password';
      input.type = on ? 'text' : 'password';
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      input.focus();
    });
  });

  // ---------- Password strength (guidance only) ----------
  var reg = document.getElementById('reg_password');
  var meter = root.querySelector('.auth__meter');
  var hint = root.querySelector('[data-meter-hint]');
  var hintText = hint ? hint.textContent : '';
  function score(p) {
    if (p.length < 8) return p.length ? 1 : 0;
    var s = 1;
    if (/[a-z]/.test(p) && /[A-Z]/.test(p)) s++;
    if (/\d/.test(p)) s++;
    if (/[^A-Za-z0-9]/.test(p) || p.length >= 12) s++;
    return Math.min(4, s);
  }
  if (reg && meter) {
    reg.addEventListener('input', function () {
      var s = score(reg.value);
      meter.setAttribute('data-s', String(s));
      if (hint) hint.innerHTML = reg.value ? '<b>' + (C.words || [])[reg.value.length < 8 ? 0 : s] + '</b> · ' + hintText : hintText;
    });
  }

  // ---------- Checks before sending, and a busy button ----------
  function setErr(input, msg) {
    var f = input.closest('.fld');
    if (!f) return;
    f.classList.toggle('is-bad', !!msg);
    var e = f.querySelector('.fld__err');
    if (msg) {
      if (!e) { e = document.createElement('p'); e.className = 'fld__err'; e.setAttribute('role', 'alert'); f.appendChild(e); }
      e.textContent = msg;
    } else if (e) e.remove();
  }
  root.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var bad = null;
      form.querySelectorAll('.fld__in').forEach(function (i) {
        var msg = '';
        if (i.required && !i.value.trim()) msg = C.required;
        else if (i.type === 'email' && i.value && !/^\S+@\S+\.\S+$/.test(i.value)) msg = C.email;
        else if (i.id === 'reg_password' && i.value.length < 8) msg = C.short;
        setErr(i, msg);
        if (msg && !bad) bad = i;
      });
      if (bad) {
        e.preventDefault();
        if (!reduce) { root.classList.remove('is-shake'); void root.offsetWidth; root.classList.add('is-shake'); }
        bad.focus();
        return;
      }
      var btn = form.querySelector('.auth__btn');
      if (btn) setTimeout(function () { btn.classList.add('is-busy'); btn.setAttribute('aria-busy', 'true'); }, 0);
    });
    form.addEventListener('input', function (e) { if (e.target.classList.contains('fld__in')) setErr(e.target, ''); });
  });

  // A message from the server (wrong password, email already used…): draw attention to the card once.
  if (!reduce && document.querySelector('.woocommerce-error')) {
    setTimeout(function () { root.classList.add('is-shake'); }, 820);
  }
})();
