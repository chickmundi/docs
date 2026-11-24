<?php
defined('ABSPATH') || exit;
$v = CHICK_STUDIO_VERSION;
$uri = get_template_directory_uri();
$i = [
    'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
    'tag' => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9-9-9Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
    'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    'plug' => '<path d="M9 2v6M15 2v6M6 8h12v4a6 6 0 0 1-12 0V8ZM12 18v4"/>',
    'chart' => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
    'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
    'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3M14 9l2 2"/>',
    'code' => '<path d="m8 6-6 6 6 6M16 6l6 6-6 6"/>',
    'shield' => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3Z"/><path d="m9 12 2 2 4-4"/>',
    'refresh' => '<path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M8 16H3v5"/>',
    'gauge' => '<path d="M12 14l4-4"/><path d="M3.3 19a9 9 0 1 1 17.4 0"/>',
    'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    'check' => '<path d="m5 12 5 5L20 7"/>',
    'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    'phone' => '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
];
$ic = fn(string $n, int $s = 20) => '<svg class="ic" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $i[$n] . '</svg>';
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#ffffff">
<link rel="preload" href="<?php echo esc_url($uri . '/assets/fonts/bricolage-latin.woff2'); ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?php echo esc_url($uri . '/assets/fonts/instrument-latin.woff2'); ?>" as="font" type="font/woff2" crossorigin>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 26 26'%3E%3Crect width='26' height='26' rx='7' fill='%2314213D'/%3E%3Ccircle cx='13' cy='13' r='5.5' fill='%23FFCF3F'/%3E%3C/svg%3E">
<link rel="stylesheet" href="<?php echo esc_url($uri . '/assets/site.css?ver=' . $v); ?>">
<?php wp_head(); ?>
</head>
<body>
<a class="skip" href="#main" data-i18n="skip">Skip to content</a>

<header class="top">
  <div class="wrap top__in">
    <a class="brand" href="#top" aria-label="CHICK Service">
      <svg width="26" height="26" viewBox="0 0 26 26" aria-hidden="true"><rect width="26" height="26" rx="7" fill="#14213D"/><circle cx="13" cy="13" r="5.5" fill="#FFCF3F"/></svg>
      <span>CHICK Service</span>
    </a>
    <nav class="top__nav" aria-label="Main">
      <a href="#build" data-i18n="nav.build">What we build</a>
      <a href="#process" data-i18n="nav.process">How it works</a>
      <a href="#faq" data-i18n="nav.faq">Questions</a>
    </nav>
    <div class="top__end">
      <button class="lang" type="button" data-lang-toggle aria-label="FR, lire en français" lang="fr">FR</button>
      <a class="btn btn--sm" href="#estimate" data-i18n="cta.short">Free estimate</a>
    </div>
  </div>
</header>

<main id="main">
  <section class="hero" id="top">
    <div class="wrap hero__in">
      <div class="hero__copy">
        <h1 data-i18n="hero.title">The feature your WordPress site is missing, built as a plugin you own.</h1>
        <p class="hero__lead" data-i18n="hero.lead">We design and build custom WordPress and WooCommerce plugins for businesses: bookings, pricing rules, member areas, integrations and dashboards. Clean code, a fixed quote up front, and no lock-in.</p>
        <div class="hero__cta">
          <a class="btn" href="#estimate" data-i18n="cta.estimate">Get a free estimate</a>
          <a class="link" href="#process" data-i18n="cta.how">See how it works</a>
        </div>
        <ul class="hero__facts">
          <li><?php echo $ic('check', 16); ?><span data-i18n="hero.f1">Fixed price before any work starts</span></li>
          <li><?php echo $ic('check', 16); ?><span data-i18n="hero.f2">Tested on a copy of your site</span></li>
          <li><?php echo $ic('check', 16); ?><span data-i18n="hero.f3">English and French</span></li>
        </ul>
      </div>

      <div class="demo" aria-label="Try it: switch on a plugin and watch the product change" data-i18n-aria="demo.aria">
        <div class="demo__admin">
          <div class="demo__bar"><span class="demo__title" data-i18n="demo.plugins">Plugins</span><span class="demo__hint" data-i18n="demo.hint">Try it: switch one on</span></div>
          <ul class="demo__list">
            <li class="plug" data-plug="flash">
              <span class="plug__ic"><?php echo $ic('tag', 18); ?></span>
              <span class="plug__m"><strong data-i18n="demo.flash">Flash sales</strong><small data-i18n="demo.flash.d">Limited-time price with a real stock count</small></span>
              <button class="switch" type="button" role="switch" aria-checked="false" aria-label="Flash sales"></button>
            </li>
            <li class="plug" data-plug="deposit">
              <span class="plug__ic"><?php echo $ic('calendar', 18); ?></span>
              <span class="plug__m"><strong data-i18n="demo.deposit">Booking deposits</strong><small data-i18n="demo.deposit.d">Reserve now, pay 25&nbsp;% today</small></span>
              <button class="switch" type="button" role="switch" aria-checked="false" aria-label="Booking deposits"></button>
            </li>
            <li class="plug" data-plug="member">
              <span class="plug__ic"><?php echo $ic('lock', 18); ?></span>
              <span class="plug__m"><strong data-i18n="demo.member">Member pricing</strong><small data-i18n="demo.member.d">Better prices for approved accounts</small></span>
              <button class="switch" type="button" role="switch" aria-checked="false" aria-label="Member pricing"></button>
            </li>
          </ul>
        </div>
        <div class="demo__site" aria-live="polite">
          <div class="item">
            <div class="item__img" aria-hidden="true">
              <svg viewBox="0 0 120 90" width="100%" height="100%"><rect width="120" height="90" fill="#EEF2F8"/><path d="M40 30h34v34a8 8 0 0 1-8 8H48a8 8 0 0 1-8-8V30Z" fill="#14213D"/><path d="M74 38h6a8 8 0 0 1 0 16h-6" fill="none" stroke="#14213D" stroke-width="5"/><rect x="44" y="36" width="26" height="4" rx="2" fill="#FFCF3F"/></svg>
              <span class="item__badge" data-show="flash" data-i18n="demo.badge">Flash sale</span>
            </div>
            <div class="item__body">
              <strong class="item__name" data-i18n="demo.product">Stoneware mug, 350&nbsp;ml</strong>
              <div class="item__price"><s data-show="flash">32,00&nbsp;$</s> <b data-price>32,00&nbsp;$</b></div>
              <div class="item__line" data-show="flash"><span data-i18n="demo.ends">Ends in</span> <time data-countdown>01:59:43</time><span class="item__left" data-i18n="demo.left">8 left at this price</span></div>
              <div class="item__line item__member" data-show="member"><?php echo $ic('lock', 14); ?><span data-i18n="demo.memberline">Member price</span> <b data-member>27,20&nbsp;$</b></div>
              <span class="item__btn" data-btn><span data-i18n="demo.add">Add to cart</span></span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section" id="build">
    <div class="wrap">
      <div class="head">
        <h2 data-i18n="build.title">If you can describe it, it can be a plugin.</h2>
        <p data-i18n="build.lead">Ready-made plugins cover the common cases. When your business works differently, a plugin made for it saves hours every week and removes the workarounds.</p>
      </div>
      <ul class="feats">
        <li><?php echo $ic('calendar'); ?><div><h3 data-i18n="b1">Bookings and deposits</h3><p data-i18n="b1.d">Time slots, capacity, deposits, reminders and cancellations that follow your rules.</p></div></li>
        <li><?php echo $ic('tag'); ?><div><h3 data-i18n="b2">Pricing and checkout rules</h3><p data-i18n="b2.d">Quantity deals, flash sales, wholesale prices, delivery zones and custom checkout steps.</p></div></li>
        <li><?php echo $ic('lock'); ?><div><h3 data-i18n="b3">Member areas</h3><p data-i18n="b3.d">Accounts, approvals, gated content, downloads and subscriptions.</p></div></li>
        <li><?php echo $ic('plug'); ?><div><h3 data-i18n="b4">Connections to your tools</h3><p data-i18n="b4.d">Sync with your CRM, inventory, accounting, payment or shipping service through their API.</p></div></li>
        <li><?php echo $ic('chart'); ?><div><h3 data-i18n="b5">Dashboards and reports</h3><p data-i18n="b5.d">The numbers you check every morning, on one screen, including on your phone.</p></div></li>
        <li><?php echo $ic('bell'); ?><div><h3 data-i18n="b6">Automations</h3><p data-i18n="b6.d">Emails, follow-ups and alerts that send themselves, with your customers’ consent.</p></div></li>
        <li><?php echo $ic('key'); ?><div><h3 data-i18n="b7">Licensing for your own product</h3><p data-i18n="b7.d">Sell your plugin with licence keys, plans, free trials and renewals.</p></div></li>
        <li><?php echo $ic('refresh'); ?><div><h3 data-i18n="b8">Fixes and rescues</h3><p data-i18n="b8.d">A plugin someone else built that no longer works: we review it and fix or rebuild it.</p></div></li>
      </ul>
    </div>
  </section>

  <section class="section section--tint" id="work">
    <div class="wrap work">
      <div class="work__copy">
        <h2 data-i18n="work.title">A grocery store, managed from a phone.</h2>
        <p data-i18n="work.lead">For an African grocery store in Québec with more than 1,100 products in two languages, we built a set of plugins on top of WooCommerce.</p>
        <ul class="ticks">
          <li><?php echo $ic('check', 18); ?><span data-i18n="w1">Flash sales with real stock counts and per-order limits</span></li>
          <li><?php echo $ic('check', 18); ?><span data-i18n="w2">A phone app for orders, products and promotions, with order alerts</span></li>
          <li><?php echo $ic('check', 18); ?><span data-i18n="w3">A welcome wheel and referrals that block repeat sign-ups</span></li>
          <li><?php echo $ic('check', 18); ?><span data-i18n="w4">Monthly plans with licence keys, a free trial and Interac payments</span></li>
        </ul>
        <dl class="scores">
          <div><dt data-i18n="s1">Mobile speed score</dt><dd>95–99</dd></div>
          <div><dt data-i18n="s2">SEO score</dt><dd>100</dd></div>
          <div><dt data-i18n="s3">Products, French and English</dt><dd>1,128</dd></div>
        </dl>
        <p class="note" data-i18n="work.note">Scores measured with Google Lighthouse on the live store.</p>
      </div>
      <figure class="work__phone">
        <div class="phone"><img src="<?php echo esc_url($uri . '/assets/app-product.jpg'); ?>" width="390" height="655" alt="The store’s phone app: editing a product’s price, promo price and photo" data-i18n-alt="work.alt" loading="lazy"></div>
      </figure>
    </div>
  </section>

  <section class="section" id="process">
    <div class="wrap">
      <div class="head">
        <h2 data-i18n="p.title">From idea to installed, in four steps.</h2>
        <p data-i18n="p.lead">You always know what will be built, what it costs and when it will be ready.</p>
      </div>
      <ol class="steps">
        <li><span class="steps__n">1</span><h3 data-i18n="p1">Tell us what you need</h3><p data-i18n="p1.d">A few lines are enough. We reply with questions, not a sales pitch.</p></li>
        <li><span class="steps__n">2</span><h3 data-i18n="p2">Get a fixed quote</h3><p data-i18n="p2.d">Scope, price and timeline in writing. Nothing starts until you approve it.</p></li>
        <li><span class="steps__n">3</span><h3 data-i18n="p3">Try it on a copy</h3><p data-i18n="p3.d">We build on a staging copy of your site, so customers never see work in progress.</p></li>
        <li><span class="steps__n">4</span><h3 data-i18n="p4">Launch and own it</h3><p data-i18n="p4.d">We install it, show your team how it works and hand over the code. It is yours.</p></li>
      </ol>
    </div>
  </section>

  <section class="section section--line">
    <div class="wrap craft">
      <h2 data-i18n="c.title">Built the WordPress way, so updates don’t break it.</h2>
      <div class="craft__grid">
        <div><?php echo $ic('code'); ?><h3 data-i18n="c1">The code is yours</h3><p data-i18n="c1.d">No subscription needed to keep it running, no hidden licence. Any WordPress developer can read it.</p></div>
        <div><?php echo $ic('refresh'); ?><h3 data-i18n="c2">Survives updates</h3><p data-i18n="c2.d">We never edit WordPress, your theme or other plugins. Everything plugs in through official hooks.</p></div>
        <div><?php echo $ic('shield'); ?><h3 data-i18n="c3">Secure by default</h3><p data-i18n="c3.d">Every action checks who is allowed to do it, and every input is validated before it is saved.</p></div>
        <div><?php echo $ic('gauge'); ?><h3 data-i18n="c4">Light and fast</h3><p data-i18n="c4.d">Code loads only on the pages that need it, so your site stays quick on phones.</p></div>
      </div>
      <p class="care"><span data-i18n="care">Want someone to look after it? An optional monthly care plan covers updates, fixes and small changes. Cancel any time.</span></p>
    </div>
  </section>

  <section class="section" id="faq">
    <div class="wrap faq">
      <div class="faq__aside"><h2 data-i18n="f.title">Questions</h2><p data-i18n="f.lead">Something else on your mind? Ask it in your request: we answer every message.</p><a class="link" href="#estimate" data-i18n="cta.ask">Ask a question</a></div>
      <div class="faq__list">
        <details><summary data-i18n="q1">How much does a custom plugin cost?</summary><p data-i18n="a1">It depends on what it does. A small feature can take a few days, a larger system a few weeks. The estimate is free, and you get a fixed price before any work starts.</p></details>
        <details><summary data-i18n="q2">Will it work with my theme and other plugins?</summary><p data-i18n="a2">We build and test on a copy of your site, with your theme and plugins, before anything goes live.</p></details>
        <details><summary data-i18n="q3">Who owns the code?</summary><p data-i18n="a3">You do. The plugin is delivered with its source code under the GPL, like WordPress itself. You can keep it, change it or hand it to another developer.</p></details>
        <details><summary data-i18n="q4">What happens when WordPress updates?</summary><p data-i18n="a4">The plugin uses official WordPress and WooCommerce interfaces, so routine updates don’t affect it. With a care plan, we test major updates for you.</p></details>
        <details><summary data-i18n="q5">Can you fix a plugin someone else built?</summary><p data-i18n="a5">Yes. We review the code first and tell you plainly whether fixing it or rebuilding it costs less.</p></details>
        <details><summary data-i18n="q6">Do you work in French?</summary><p data-i18n="a6">Yes. We work in English and French, and plugins can be bilingual.</p></details>
      </div>
    </div>
  </section>

  <section class="estimate" id="estimate">
    <div class="wrap estimate__in">
      <div class="estimate__copy">
        <h2 data-i18n="e.title">Tell us what your site should do.</h2>
        <p data-i18n="e.lead">Describe the feature in your own words. We reply by email with questions or a first estimate. No commitment.</p>
        <ol class="next">
          <li data-i18n="e.n1">We read your request and reply by email.</li>
          <li data-i18n="e.n2">If it is a fit, you get a fixed quote.</li>
          <li data-i18n="e.n3">You decide. Nothing is billed before you approve.</li>
        </ol>
      </div>
      <form class="form" data-lead novalidate>
        <div class="form__row">
          <label><span data-i18n="l.name">Your name</span><input name="name" autocomplete="name" required></label>
          <label><span data-i18n="l.email">Email</span><input name="email" type="email" autocomplete="email" required></label>
        </div>
        <label><span data-i18n="l.site">Your website</span> <em data-i18n="l.optional">optional</em><input name="site" type="url" inputmode="url" placeholder="https://"></label>
        <label><span data-i18n="l.need">What should the plugin do?</span><textarea name="need" rows="5" required data-i18n-ph="l.need.ph" placeholder="Example: customers book a cooking class, pay a 25 % deposit online, and get a reminder the day before."></textarea></label>
        <label><span data-i18n="l.budget">Budget</span> <em data-i18n="l.optional">optional</em>
          <select name="budget">
            <option value="" data-i18n="bu0">Not sure yet</option>
            <option data-i18n="bu1">Under 1,000 $</option>
            <option data-i18n="bu2">1,000 to 3,000 $</option>
            <option data-i18n="bu3">3,000 to 10,000 $</option>
            <option data-i18n="bu4">Over 10,000 $</option>
          </select>
        </label>
        <input class="hp" name="website_url_confirm" tabindex="-1" autocomplete="off" aria-hidden="true">
        <button class="btn btn--block" type="submit" data-i18n="cta.send">Send my request</button>
        <p class="form__msg" role="status" aria-live="polite"></p>
      </form>
    </div>
  </section>
</main>

<footer class="foot">
  <div class="wrap foot__in">
    <span class="brand brand--sm"><svg width="18" height="18" viewBox="0 0 26 26" aria-hidden="true"><rect width="26" height="26" rx="7" fill="#14213D"/><circle cx="13" cy="13" r="5.5" fill="#FFCF3F"/></svg> CHICK Service</span>
    <span data-i18n="foot">Custom WordPress plugins</span>
    <span>&copy; <?php echo esc_html(wp_date('Y')); ?></span>
  </div>
</footer>

<script>window.CHICK = { rest: <?php echo wp_json_encode(esc_url_raw(rest_url('chick/v1/lead'))); ?>, t: <?php echo (int) time(); ?> };</script>
<script src="<?php echo esc_url($uri . '/assets/site.js?ver=' . $v); ?>" defer></script>
<?php wp_footer(); ?>
</body>
</html>
