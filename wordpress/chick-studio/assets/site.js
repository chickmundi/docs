/* CHICK Service landing: plugin demo, language switch, quote form. */
(function () {
  'use strict';
  var C = window.CHICK || {};
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ---------- Language ----------
  var FR = {
    'skip': 'Aller au contenu',
    'nav.build': 'Ce qu’on construit', 'nav.process': 'Comment ça marche', 'nav.faq': 'Questions',
    'cta.estimate': 'Obtenir une estimation gratuite', 'cta.short': 'Estimation gratuite', 'cta.ask': 'Poser une question', 'f.lead': 'Une autre question ? Posez-la dans votre demande : nous répondons à chaque message.', 'cta.how': 'Voir comment ça marche', 'cta.send': 'Envoyer ma demande',
    'hero.title': 'La fonction qui manque à votre site WordPress, conçue en plugin qui vous appartient.',
    'hero.lead': 'Nous concevons des plugins WordPress et WooCommerce sur mesure pour les entreprises : réservations, règles de prix, espaces membres, connexions à vos outils et tableaux de bord. Du code propre, un prix fixe dès le départ, et aucune dépendance.',
    'hero.f1': 'Prix fixe avant tout travail', 'hero.f2': 'Testé sur une copie de votre site', 'hero.f3': 'Français et anglais',
    'demo.aria': 'Essayez : activez un plugin et voyez le produit changer',
    'demo.plugins': 'Extensions', 'demo.hint': 'Essayez : activez-en une',
    'demo.flash': 'Ventes éclair', 'demo.flash.d': 'Prix limité dans le temps, avec le vrai stock',
    'demo.deposit': 'Acompte de réservation', 'demo.deposit.d': 'Réservez maintenant, payez 25&nbsp;% aujourd’hui',
    'demo.member': 'Prix membres', 'demo.member.d': 'De meilleurs prix pour les comptes approuvés',
    'demo.badge': 'Vente éclair', 'demo.product': 'Tasse en grès, 350&nbsp;ml', 'demo.ends': 'Se termine dans', 'demo.left': 'Plus que 8 à ce prix',
    'demo.memberline': 'Prix membre', 'demo.add': 'Ajouter au panier',
    'build.title': 'Si vous pouvez le décrire, ça peut devenir un plugin.',
    'build.lead': 'Les plugins tout faits couvrent les cas courants. Quand votre entreprise fonctionne autrement, un plugin conçu pour elle fait gagner des heures chaque semaine et met fin aux contournements.',
    'b1': 'Réservations et acomptes', 'b1.d': 'Plages horaires, capacité, acomptes, rappels et annulations selon vos règles.',
    'b2': 'Règles de prix et de paiement', 'b2.d': 'Offres en quantité, ventes éclair, prix de gros, zones de livraison et étapes de paiement sur mesure.',
    'b3': 'Espaces membres', 'b3.d': 'Comptes, approbations, contenu réservé, téléchargements et abonnements.',
    'b4': 'Connexions à vos outils', 'b4.d': 'Synchronisation avec votre CRM, votre inventaire, votre comptabilité, vos paiements ou votre transporteur.',
    'b5': 'Tableaux de bord et rapports', 'b5.d': 'Les chiffres que vous regardez chaque matin, sur un seul écran, aussi sur votre téléphone.',
    'b6': 'Automatisations', 'b6.d': 'Courriels, relances et alertes qui partent seuls, avec le consentement de vos clients.',
    'b7': 'Licences pour votre propre produit', 'b7.d': 'Vendez votre plugin avec des clés de licence, des forfaits, un essai gratuit et des renouvellements.',
    'b8': 'Réparations et reprises', 'b8.d': 'Un plugin fait par quelqu’un d’autre qui ne fonctionne plus : on l’examine, puis on le répare ou on le refait.',
    'work.title': 'Une épicerie gérée depuis un téléphone.',
    'work.lead': 'Pour une épicerie africaine au Québec, avec plus de 1&nbsp;100 produits en deux langues, nous avons construit une série de plugins sur WooCommerce.',
    'w1': 'Ventes éclair avec le vrai stock et une limite par commande', 'w2': 'Une application téléphone pour les commandes, les produits et les promotions, avec alertes',
    'w3': 'Une roue de bienvenue et du parrainage qui bloquent les inscriptions en double', 'w4': 'Des forfaits mensuels avec clés de licence, essai gratuit et paiement Interac',
    's1': 'Score de vitesse mobile', 's2': 'Score SEO', 's3': 'Produits, en français et en anglais',
    'work.note': 'Scores mesurés avec Google Lighthouse sur la boutique en ligne.',
    'work.alt': 'L’application téléphone de la boutique : modifier le prix, le prix promo et la photo d’un produit',
    'p.title': 'De l’idée à l’installation, en quatre étapes.', 'p.lead': 'Vous savez toujours ce qui sera construit, ce que ça coûte et quand ce sera prêt.',
    'p1': 'Dites-nous ce qu’il vous faut', 'p1.d': 'Quelques lignes suffisent. Nous répondons avec des questions, pas un discours de vente.',
    'p2': 'Recevez un prix fixe', 'p2.d': 'Contenu, prix et délai par écrit. Rien ne commence avant votre accord.',
    'p3': 'Essayez sur une copie', 'p3.d': 'Nous travaillons sur une copie de votre site : vos clients ne voient jamais le travail en cours.',
    'p4': 'Lancez et gardez-le', 'p4.d': 'Nous l’installons, montrons à votre équipe comment l’utiliser et vous remettons le code. Il est à vous.',
    'c.title': 'Construit à la manière WordPress, pour que les mises à jour ne le cassent pas.',
    'c1': 'Le code est à vous', 'c1.d': 'Aucun abonnement requis pour qu’il fonctionne, aucune licence cachée. N’importe quel développeur WordPress peut le lire.',
    'c2': 'Résiste aux mises à jour', 'c2.d': 'Nous ne modifions jamais WordPress, votre thème ni vos autres plugins. Tout se branche par les points d’accroche officiels.',
    'c3': 'Sécurisé dès le départ', 'c3.d': 'Chaque action vérifie qui a le droit de la faire, et chaque saisie est validée avant d’être enregistrée.',
    'c4': 'Léger et rapide', 'c4.d': 'Le code se charge seulement sur les pages qui en ont besoin : votre site reste rapide sur téléphone.',
    'care': 'Vous voulez qu’on s’en occupe ? Un forfait d’entretien mensuel, facultatif, couvre les mises à jour, les corrections et les petits changements. Annulable en tout temps.',
    'f.title': 'Questions',
    'q1': 'Combien coûte un plugin sur mesure ?', 'a1': 'Ça dépend de ce qu’il fait. Une petite fonction peut prendre quelques jours, un système plus grand quelques semaines. L’estimation est gratuite et vous recevez un prix fixe avant tout travail.',
    'q2': 'Va-t-il fonctionner avec mon thème et mes autres plugins ?', 'a2': 'Nous construisons et testons sur une copie de votre site, avec votre thème et vos plugins, avant toute mise en ligne.',
    'q3': 'À qui appartient le code ?', 'a3': 'À vous. Le plugin est livré avec son code source sous licence GPL, comme WordPress. Vous pouvez le garder, le modifier ou le confier à un autre développeur.',
    'q4': 'Que se passe-t-il quand WordPress se met à jour ?', 'a4': 'Le plugin utilise les interfaces officielles de WordPress et de WooCommerce : les mises à jour courantes ne l’affectent pas. Avec un forfait d’entretien, nous testons les grandes mises à jour pour vous.',
    'q5': 'Pouvez-vous réparer un plugin fait par quelqu’un d’autre ?', 'a5': 'Oui. Nous examinons d’abord le code et vous disons franchement s’il coûte moins cher de le réparer ou de le refaire.',
    'q6': 'Travaillez-vous en anglais ?', 'a6': 'Oui. Nous travaillons en français et en anglais, et les plugins peuvent être bilingues.',
    'e.title': 'Dites-nous ce que votre site devrait faire.',
    'e.lead': 'Décrivez la fonction avec vos mots. Nous répondons par courriel avec des questions ou une première estimation. Sans engagement.',
    'e.n1': 'Nous lisons votre demande et répondons par courriel.', 'e.n2': 'Si le projet nous convient, vous recevez un prix fixe.', 'e.n3': 'Vous décidez. Rien n’est facturé avant votre accord.',
    'l.name': 'Votre nom', 'l.email': 'Courriel', 'l.site': 'Votre site web', 'l.optional': 'facultatif', 'l.need': 'Que devrait faire le plugin ?',
    'l.need.ph': 'Exemple : les clients réservent un cours de cuisine, paient un acompte de 25 % en ligne et reçoivent un rappel la veille.',
    'l.budget': 'Budget', 'bu0': 'Je ne sais pas encore', 'bu1': 'Moins de 1 000 $', 'bu2': '1 000 à 3 000 $', 'bu3': '3 000 à 10 000 $', 'bu4': 'Plus de 10 000 $',
    'foot': 'Plugins WordPress sur mesure',
  };
  var MSG = {
    en: { sending: 'Sending…', ok: 'Request sent. We will reply by email.', invalid: 'Check the highlighted fields: your name, a valid email, and a few words about what the plugin should do.', too_many: 'Too many requests from this connection. Try again in an hour.', network: 'The request could not be sent. Check your connection and try again.', sent: 'Request sent', deposit: 'Reserve: pay {d} today' },
    fr: { sending: 'Envoi…', ok: 'Demande envoyée. Nous répondrons par courriel.', invalid: 'Vérifiez les champs en rouge : votre nom, un courriel valide et quelques mots sur ce que le plugin doit faire.', too_many: 'Trop de demandes depuis cette connexion. Réessayez dans une heure.', network: 'La demande n’a pas pu être envoyée. Vérifiez votre connexion et réessayez.', sent: 'Demande envoyée', deposit: 'Réserver : payez {d} aujourd’hui' },
  };
  var EN = {};
  var lang = 'en';
  document.querySelectorAll('[data-i18n]').forEach(function (el) { EN[el.dataset.i18n] = el.innerHTML; });
  document.querySelectorAll('[data-i18n-ph]').forEach(function (el) { EN[el.dataset.i18nPh] = el.getAttribute('placeholder'); });
  document.querySelectorAll('[data-i18n-alt]').forEach(function (el) { EN[el.dataset.i18nAlt] = el.getAttribute('alt'); });
  document.querySelectorAll('[data-i18n-aria]').forEach(function (el) { EN[el.dataset.i18nAria] = el.getAttribute('aria-label'); });

  function setLang(l) {
    lang = l === 'fr' ? 'fr' : 'en';
    var dict = lang === 'fr' ? FR : EN;
    document.documentElement.lang = lang === 'fr' ? 'fr-CA' : 'en-CA';
    document.querySelectorAll('[data-i18n]').forEach(function (el) { var v = dict[el.dataset.i18n]; if (v != null) el.innerHTML = v; });
    document.querySelectorAll('[data-i18n-ph]').forEach(function (el) { var v = dict[el.dataset.i18nPh]; if (v != null) el.setAttribute('placeholder', v); });
    document.querySelectorAll('[data-i18n-alt]').forEach(function (el) { var v = dict[el.dataset.i18nAlt]; if (v != null) el.setAttribute('alt', v); });
    document.querySelectorAll('[data-i18n-aria]').forEach(function (el) { var v = dict[el.dataset.i18nAria]; if (v != null) el.setAttribute('aria-label', v); });
    var t = document.querySelector('[data-lang-toggle]');
    t.textContent = lang === 'fr' ? 'EN' : 'FR';
    t.setAttribute('aria-label', lang === 'fr' ? 'EN, read in English' : 'FR, lire en français');
    t.setAttribute('lang', lang === 'fr' ? 'en' : 'fr');
    document.title = lang === 'fr' ? 'CHICK Service | Plugins WordPress sur mesure pour votre entreprise' : 'CHICK Service | Custom WordPress plugins, built for your business';
    try { localStorage.setItem('chick-lang', lang); } catch (e) {}
    paint();
  }
  document.querySelector('[data-lang-toggle]').addEventListener('click', function () { setLang(lang === 'fr' ? 'en' : 'fr'); });

  // ---------- Plugin demo ----------
  var BASE = 32;
  var on = { flash: false, deposit: false, member: false };
  var demo = document.querySelector('.demo');
  function money(v) { return lang === 'fr' ? v.toLocaleString('fr-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' $' : '$' + v.toFixed(2); }
  function paint() {
    var price = on.flash ? BASE * 0.75 : BASE;
    demo.querySelector('[data-price]').textContent = money(price);
    demo.querySelector('.item__price s').textContent = money(BASE);
    demo.querySelector('[data-member]').textContent = money(price * 0.85);
    demo.querySelector('.item').classList.toggle('is-flash', on.flash);
    demo.querySelectorAll('[data-show]').forEach(function (el) { el.classList.toggle('is-shown', !!on[el.dataset.show]); });
    var btn = demo.querySelector('[data-btn]');
    if (on.deposit) btn.textContent = MSG[lang].deposit.replace('{d}', money(price * 0.25));
    else btn.innerHTML = '<span data-i18n="demo.add">' + (lang === 'fr' ? FR['demo.add'] : EN['demo.add']) + '</span>';
    demo.querySelectorAll('.plug').forEach(function (p) {
      var k = p.dataset.plug;
      p.classList.toggle('is-on', on[k]);
      p.querySelector('.switch').setAttribute('aria-checked', on[k] ? 'true' : 'false');
    });
  }
  demo.querySelectorAll('.plug').forEach(function (p) {
    p.querySelector('.switch').addEventListener('click', function () { on[p.dataset.plug] = !on[p.dataset.plug]; touched = true; paint(); });
  });
  // Countdown (always about two hours from now).
  var end = Date.now() + (1 * 3600 + 59 * 60 + 43) * 1000;
  var cd = demo.querySelector('[data-countdown]');
  setInterval(function () {
    var s = Math.max(0, Math.round((end - Date.now()) / 1000));
    cd.textContent = [Math.floor(s / 3600), Math.floor(s / 60) % 60, s % 60].map(function (n) { return ('0' + n).slice(-2); }).join(':');
  }, 1000);

  // One orchestrated moment: shortly after load, the first plugin switches itself on.
  var touched = false;
  setTimeout(function () { if (!touched) { on.flash = true; paint(); } }, reduce ? 0 : 1100);

  // ---------- Header border on scroll ----------
  var top = document.querySelector('.top');
  addEventListener('scroll', function () { top.classList.toggle('is-scrolled', scrollY > 8); }, { passive: true });

  // ---------- Quote form ----------
  var form = document.querySelector('[data-lead]');
  var msg = form.querySelector('.form__msg');
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = form.querySelector('button[type=submit]');
    var data = Object.fromEntries(new FormData(form).entries());
    data.t = C.t; data.lang = lang;
    form.querySelectorAll('.is-bad').forEach(function (el) { el.classList.remove('is-bad'); });
    var bad = [];
    if (!data.name.trim()) bad.push('name');
    if (!/^\S+@\S+\.\S+$/.test(data.email.trim())) bad.push('email');
    if (data.need.trim().length < 15) bad.push('need');
    if (bad.length) { return fail('invalid', bad); }
    btn.disabled = true; msg.className = 'form__msg'; msg.textContent = MSG[lang].sending;
    fetch(C.rest, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok && res.j.ok) {
          form.classList.add('is-sent'); form.reset();
          btn.textContent = MSG[lang].sent;
          msg.className = 'form__msg is-ok'; msg.textContent = MSG[lang].ok;
        } else { btn.disabled = false; fail(res.j.code || 'network', res.j.fields); }
      })
      .catch(function () { btn.disabled = false; fail('network'); });
  });
  function fail(code, fields) {
    (fields || []).forEach(function (n) { var el = form.querySelector('[name="' + n + '"]'); if (el) el.classList.add('is-bad'); });
    msg.className = 'form__msg is-err'; msg.textContent = MSG[lang][code] || MSG[lang].network;
    var first = form.querySelector('.is-bad'); if (first) first.focus();
  }

  // ---------- Start: saved choice, otherwise the browser language ----------
  var saved = null; try { saved = localStorage.getItem('chick-lang'); } catch (e) {}
  var pref = saved || ((navigator.language || '').toLowerCase().indexOf('fr') === 0 ? 'fr' : 'en');
  setLang(pref);
})();
