<?php
/**
 * "Affiche à partager": an image of the current promotions (real products and prices), made
 * in the browser, to download or share straight to WhatsApp Status, Facebook or Instagram.
 * Included by mcr_render_promo_flyer() (MCR Gestion > Promotions).
 */
defined('ABSPATH') || exit;

$ids = array_values(array_filter(wc_get_product_ids_on_sale(), fn($id) => get_post_type($id) === 'product' && get_post_status($id) === 'publish'));
$items = [];
foreach ($ids as $id) {
    $p = wc_get_product($id);
    if (!$p || !$p->is_in_stock() || !$p->is_visible()) {
        continue;
    }
    if ($p->is_type('variable')) {
        $prices = $p->get_variation_prices(true);
        $best = null;
        foreach ($prices['regular_price'] as $vid => $reg) {
            $sale = (float) ($prices['sale_price'][$vid] ?? $reg);
            if ((float) $reg > $sale && (!$best || ($reg - $sale) / $reg > ($best[0] - $best[1]) / $best[0])) {
                $best = [(float) $reg, $sale];
            }
        }
        if (!$best) {
            continue;
        }
        [$reg, $sale] = $best;
        $from = (float) $p->get_variation_price('min', true) !== (float) $p->get_variation_price('max', true);
    } else {
        $reg = (float) wc_get_price_to_display($p, ['price' => $p->get_regular_price()]);
        $sale = (float) wc_get_price_to_display($p);
        $from = false;
    }
    if ($reg <= $sale) {
        continue;
    }
    $img = wp_get_attachment_image_url($p->get_image_id(), 'woocommerce_single') ?: wc_placeholder_img_src('woocommerce_single');
    $end = 0;
    foreach ($p->is_type('variable') ? array_filter(array_map('wc_get_product', $p->get_visible_children())) : [$p] as $v) {
        if ($v->is_on_sale() && $v->get_date_on_sale_to()) {
            $end = $end ? min($end, $v->get_date_on_sale_to()->getTimestamp()) : $v->get_date_on_sale_to()->getTimestamp();
        }
    }
    $items[] = [
        'name' => $p->get_name(),
        'reg' => html_entity_decode(wp_strip_all_tags(wc_price($reg))),
        'sale' => html_entity_decode(wp_strip_all_tags(wc_price($sale))),
        'pct' => (int) round((1 - $sale / $reg) * 100),
        'from' => $from,
        'img' => $img,
        'end' => $end ? wp_date('j M', $end, mcr_store_tz()) : '',
        'dept' => implode(',', (array) wp_get_post_terms($id, 'mcr_department', ['fields' => 'slugs'])),
    ];
}
usort($items, fn($a, $b) => $b['pct'] <=> $a['pct']);
foreach (function_exists('mcr_promo_active_deals') ? mcr_promo_active_deals() : [] as $d) {
    $first = wc_get_product((int) (($d['type'] === 'bundle' ? $d['products'] : $d['targets'])[0] ?? 0));
    if ($first) {
        array_unshift($items, ['name' => mcr_promo_title($d, false) ?: $first->get_name(), 'reg' => '', 'sale' => mcr_promo_label($d, false), 'pct' => 0, 'from' => false, 'img' => wp_get_attachment_image_url($first->get_image_id(), 'woocommerce_single') ?: '', 'end' => '', 'dept' => 'deal']);
    }
}
$store = mcr_setting('store');
$logo = defined('MCRT_URL') ? MCRT_URL . '/assets/MRC-logo-whit.png' : '';
$data = [
    'items' => $items,
    'logo' => $logo,
    'site' => preg_replace('#^https?://#', '', untrailingslashit((string) get_option('home'))),
    'phone' => (string) ($store['phone'] ?? ''),
    'wa' => (string) ($store['whatsapp_display'] ?? ''),
    'address' => trim(($store['street'] ?? '') . ', ' . ($store['city'] ?? '')),
    'free' => html_entity_decode(wp_strip_all_tags(wc_price((float) mcr_setting('delivery', 'free_min_subtotal')))),
];
?>
<div class="mcr-flyer-admin">
  <p style="max-width:780px">Une affiche prête à partager, faite avec vos <strong>vrais produits en promotion et leurs vrais prix</strong>. Choisissez le format et les produits, puis téléchargez l’image ou partagez-la directement (sur téléphone : statut WhatsApp, Facebook, Instagram).</p>
  <?php if (!$items) : ?>
    <div class="notice notice-warning inline"><p>Aucun produit en promotion pour le moment. Créez une promotion dans l’onglet « Promotions et offres ».</p></div>
  <?php else : ?>
  <div class="fl-grid">
    <form class="fl-opts" onsubmit="return false">
      <p><label><strong>Format</strong><br>
        <select name="format"><option value="post">Publication (1080 × 1350) — Facebook, Instagram</option><option value="story">Statut / Story (1080 × 1920) — WhatsApp, Instagram</option><option value="square">Carré (1080 × 1080)</option></select></label></p>
      <p><label><strong>Titre</strong><br><input name="title" class="regular-text" value="Promos de la semaine"></label></p>
      <p><label><strong>Sous-titre</strong><br><input name="subtitle" class="regular-text" value="Dans la limite des stocks"></label></p>
      <p><label><strong>Rayon</strong><br><select name="dept"><option value="">Tous</option><option value="epicerie">Épicerie</option><option value="beaute-cheveux">Beauté et cheveux</option><option value="soins-corporels">Soins corporels</option><option value="deal">Offres groupées</option></select></label></p>
      <p><label><strong>Nombre de produits</strong><br><select name="count"><option>4</option><option selected>6</option><option>9</option></select></label></p>
      <p><strong>Produits</strong> <span class="description">(décochez pour retirer)</span></p>
      <div class="fl-list"></div>
      <p class="fl-actions">
        <button type="button" class="button button-primary" data-act="download">Télécharger l’image</button>
        <button type="button" class="button" data-act="share">Partager…</button>
      </p>
      <p class="description fl-status" aria-live="polite"></p>
    </form>
    <div class="fl-preview-wrap"><div class="fl-scale"><div class="fl" id="mcr-flyer"></div></div></div>
  </div>
  <?php endif; ?>
</div>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&family=Plus+Jakarta+Sans:wght@500;700;800&display=swap">
<style>
.fl-grid{display:grid;grid-template-columns:minmax(280px,360px) 1fr;gap:24px;align-items:start;margin-top:12px}
@media(max-width:900px){.fl-grid{grid-template-columns:1fr}}
.fl-opts{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 16px}
.fl-opts select,.fl-opts input.regular-text{width:100%;max-width:100%}
.fl-list{max-height:260px;overflow:auto;border:1px solid #eee;border-radius:6px;padding:6px 8px}
.fl-list label{display:flex;gap:6px;align-items:flex-start;font-size:12px;padding:3px 0}
.fl-actions{display:flex;gap:8px;flex-wrap:wrap}
.fl-preview-wrap{background:#e9e6dc;border-radius:10px;padding:16px;overflow:hidden}
.fl-scale{transform-origin:top left}
.fl{width:1080px;height:1350px;position:relative;overflow:hidden;font-family:'Plus Jakarta Sans',sans-serif;background:#f8f6ef;color:#1d1d1b;display:flex;flex-direction:column}
.fl--story{height:1920px}.fl--square{height:1080px}
.fl *{line-height:1.2;box-sizing:border-box}
.fl__band{height:22px;flex:none;background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='116' height='22'%3E%3Crect width='44' height='22' fill='%23e6b74f'/%3E%3Crect x='44' width='16' height='22' fill='%23174b35'/%3E%3Crect x='60' width='44' height='22' fill='%23c96b43'/%3E%3Crect x='104' width='12' height='22' fill='%231d1d1b'/%3E%3C/svg%3E") repeat-x}
.fl__head{flex:none;background:#174b35;color:#fff;padding:44px 60px 40px;position:relative;overflow:hidden}
.fl__head::before{content:"";position:absolute;inset:0;opacity:.5;background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cg fill='none' stroke-width='6'%3E%3Ccircle cx='60' cy='60' r='34' stroke='%23e6b74f' opacity='.3'/%3E%3Ccircle cx='60' cy='60' r='18' stroke='%23c96b43' opacity='.45'/%3E%3C/g%3E%3C/svg%3E")}
.fl__top{position:relative;display:flex;justify-content:space-between;align-items:center}
.fl__logo{height:92px}
.fl__badge{background:#e6b74f;color:#0f3324;font-weight:800;font-size:30px;padding:12px 26px;border-radius:99px}
.fl__title{position:relative;font-family:Fraunces,serif;font-size:96px;line-height:1.05;margin:30px 0 10px}
.fl__sub{position:relative;font-size:34px;color:#e6efe8;font-weight:700}
.fl__grid{flex:1;min-height:0;display:grid;gap:22px;padding:30px 46px 26px;align-content:stretch;overflow:hidden}
.fl__grid--4{grid-template-columns:1fr 1fr}.fl__grid--6{grid-template-columns:1fr 1fr 1fr}.fl__grid--9{grid-template-columns:1fr 1fr 1fr}
.fl__card{background:#fff;border-radius:28px;border:2px solid #ebe6d8;position:relative;display:flex;flex-direction:column;overflow:hidden;min-height:0}
.fl__img{flex:1;min-height:0;display:flex;align-items:center;justify-content:center;padding:18px}
.fl__img img{max-width:100%;max-height:100%;object-fit:contain}
.fl__pct{position:absolute;z-index:1;left:14px;top:14px;background:#c96b43;color:#fff;font-family:Fraunces,serif;font-size:38px;line-height:1.15;padding:8px 16px 10px;border-radius:16px}
.fl__b{padding:0 20px 18px}
.fl__n{font-size:24px;font-weight:700;line-height:1.2;height:58px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.fl__p{display:flex;align-items:baseline;gap:12px;margin-top:8px;flex-wrap:wrap}
.fl__p del{color:#6b6a63;font-size:26px}
.fl__p strong{color:#d9631e;font-size:42px;font-weight:800;line-height:1.1}
.fl__p strong.is-long{font-size:32px}
.fl__p small{font-size:22px;color:#6b6a63;font-weight:700}
.fl__end{font-size:20px;color:#a9532f;font-weight:700;margin-top:4px}
.fl__grid--9 .fl__n{font-size:21px;height:52px}.fl__grid--9 .fl__p strong{font-size:36px}.fl__grid--9 .fl__pct{font-size:32px}
.fl__foot{flex:none;background:#0f3324;color:#fff;padding:30px 50px 38px;display:flex;justify-content:space-between;align-items:center;gap:20px}
.fl__foot b{display:block;font-size:34px;line-height:1.2}
.fl__foot span{display:block;margin-top:6px;font-size:24px;line-height:1.3;color:#cfe0d5}
.fl__foot .fl__wa{display:inline-block;margin:0;color:#fff;font-size:28px}
.fl__wa{background:#1fa855;border-radius:99px;padding:16px 28px;font-weight:800;font-size:30px;white-space:nowrap}
.fl__foot>div{flex:1;min-width:0}
.fl__foot .fl__wa{flex:none}
.fl--post .fl__head,.fl--square .fl__head{padding:30px 50px 28px}
.fl--post .fl__logo,.fl--square .fl__logo{height:74px}
.fl--post .fl__title,.fl--square .fl__title{font-size:76px;margin:18px 0 6px}
.fl--post .fl__sub,.fl--square .fl__sub{font-size:28px}
.fl--post .fl__grid,.fl--square .fl__grid{gap:16px;padding:22px 34px 18px}
.fl--post .fl__free,.fl--square .fl__free{font-size:26px;padding:12px}
.fl--post .fl__foot,.fl--square .fl__foot{padding:22px 40px 26px}
.fl__free{flex:none;text-align:center;background:#faf0d4;color:#123d2c;font-weight:800;font-size:30px;padding:16px}
</style>
<script src="<?php echo esc_url(MCR_URL . 'assets/vendor/html2canvas.min.js?ver=1.4.1'); ?>"></script>
<script>
(function () {
  var D = <?php echo wp_json_encode($data); ?>;
  var form = document.querySelector('.fl-opts');
  var el = document.getElementById('mcr-flyer');
  if (!form || !el) return;
  var list = form.querySelector('.fl-list');
  var off = {};
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
  function pool() { var dept = form.dept.value; return D.items.map(function (it, i) { it.i = i; return it; }).filter(function (it) { return !dept || it.dept.split(',').indexOf(dept) > -1; }); }
  function renderList() {
    list.innerHTML = pool().map(function (it) { return '<label><input type="checkbox" data-i="' + it.i + '"' + (off[it.i] ? '' : ' checked') + '> ' + esc(it.name) + ' <b>' + esc(it.pct ? '-' + it.pct + ' %' : it.sale) + '</b></label>'; }).join('') || '<em>Aucun produit dans ce rayon.</em>';
  }
  function render() {
    var fmt = form.format.value, n = +form.count.value;
    var chosen = pool().filter(function (it) { return !off[it.i]; }).slice(0, n);
    var cols = chosen.length <= 4 ? 4 : (chosen.length <= 6 ? 6 : 9);
    var best = chosen.reduce(function (m, it) { return Math.max(m, it.pct); }, 0);
    el.className = 'fl fl--' + fmt;
    el.innerHTML = '<div class="fl__band"></div>' +
      '<div class="fl__head"><div class="fl__top"><img class="fl__logo" src="' + esc(D.logo) + '" crossorigin="anonymous" alt="">' + (best ? '<span class="fl__badge">Jusqu’à -' + best + ' %</span>' : '') + '</div>' +
      '<div class="fl__title">' + esc(form.title.value) + '</div><div class="fl__sub">' + esc(form.subtitle.value) + '</div></div>' +
      '<div class="fl__grid fl__grid--' + cols + '" style="grid-template-rows:repeat(' + Math.ceil(chosen.length / (cols === 4 ? 2 : 3)) + ',minmax(0,1fr))">' + chosen.map(function (it) {
        return '<div class="fl__card">' + (it.pct ? '<span class="fl__pct">-' + it.pct + ' %</span>' : '') +
          '<div class="fl__img"><img src="' + esc(it.img) + '" crossorigin="anonymous" alt=""></div>' +
          '<div class="fl__b"><div class="fl__n">' + esc(it.name) + '</div><div class="fl__p">' + (it.reg ? '<del>' + esc(it.reg) + '</del>' : '') + (it.from ? '<small>dès</small>' : '') + '<strong>' + esc(it.sale) + '</strong></div>' + (it.end ? '<div class="fl__end">Jusqu’au ' + esc(it.end) + '</div>' : '') + '</div></div>';
      }).join('') + '</div>' +
      '<div class="fl__free">🚚 Livraison gratuite dès ' + esc(D.free) + ' · Cueillette gratuite</div>' +
      '<div class="fl__foot"><div><b>' + esc(D.site) + '</b><span>' + esc(D.address) + ' · ' + esc(D.phone) + '</span></div>' + (D.wa ? '<span class="fl__wa">WhatsApp ' + esc(D.wa) + '</span>' : '') + '</div>';
    fit();
  }
  function fit() {
    var wrap = document.querySelector('.fl-preview-wrap'), sc = el.parentNode;
    var s = Math.min(1, (wrap.clientWidth - 32) / 1080);
    sc.style.transform = 'scale(' + s + ')';
    sc.style.height = (el.offsetHeight * s) + 'px';
  }
  function canvas() {
    var status = form.querySelector('.fl-status');
    status.textContent = 'Création de l’image…';
    var sc = el.parentNode, t = sc.style.transform;
    sc.style.transform = 'none';
    return (document.fonts ? document.fonts.ready : Promise.resolve()).then(function () {
      return html2canvas(el, { scale: 1, useCORS: true, backgroundColor: '#f8f6ef', width: 1080, height: el.offsetHeight });
    }).then(function (c) { sc.style.transform = t; status.textContent = ''; return c; }, function (e) { sc.style.transform = t; status.textContent = 'Impossible de créer l’image : ' + e; throw e; });
  }
  function fileName() { return 'promos-marche-chemin-du-roy-' + new Date().toISOString().slice(0, 10) + '.png'; }
  form.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]');
    if (!b) return;
    canvas().then(function (c) {
      c.toBlob(function (blob) {
        var file = new File([blob], fileName(), { type: 'image/png' });
        if (b.getAttribute('data-act') === 'share' && navigator.canShare && navigator.canShare({ files: [file] })) {
          navigator.share({ files: [file], title: form.title.value }).catch(function () {});
          return;
        }
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = fileName();
        document.body.appendChild(a); a.click(); a.remove();
        if (b.getAttribute('data-act') === 'share') form.querySelector('.fl-status').textContent = 'Le partage direct marche sur téléphone. Ici, l’image a été téléchargée.';
      }, 'image/png');
    });
  });
  form.addEventListener('change', function (e) {
    if (e.target.matches('[data-i]')) off[e.target.getAttribute('data-i')] = !e.target.checked;
    if (e.target.name === 'dept') renderList();
    render();
  });
  form.addEventListener('input', function (e) { if (e.target.name === 'title' || e.target.name === 'subtitle') render(); });
  window.addEventListener('resize', fit);
  renderList(); render();
})();
</script>
