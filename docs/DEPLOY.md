# Deployment runbook — marchecheminduroy.com

Two packages, installed in this order:

| Package | Slug | What it does |
|---|---|---|
| `mcr-bilingue-1.0.0.zip` | `mcr-bilingue` | FR/EN content, `/en/` routing, per-locale price formats |
| `marche-chemin-du-roy-1.0.0.zip` | `marche-chemin-du-roy` | The storefront theme |

Build them with `./tools/build.sh`; they land in `dist/`.

The plugin goes first. The theme detects it and falls back to French-only if it
is missing, so the reverse order works too — it just shows a French-only site
until the plugin is active.

---

## Before you touch production

**Do this on a staging copy first.** Everything below is reversible, but a
storefront is not the place to discover a plugin conflict.

1. **Full backup** — database *and* `wp-content`. Verify the backup restores.
2. **Note the current theme name and version.** That is the rollback target.
3. **Export the WooCommerce System Status Report** (WooCommerce → Status → Get
   system report → Copy for support) and keep it. It records the working state.
4. **Check PHP is 8.0+** and WooCommerce 8.0+. The theme refuses to load below
   those and shows an admin notice rather than white-screening.

### What this deployment does *not* touch

Products, orders, customers, coupons, stock, tax rates, shipping zones and
payment gateway settings are all WooCommerce data. A theme change does not read
or write any of it. The plugin only ever *adds* new post meta and term meta with
the `_mcr_t_` prefix; it never modifies existing content.

Deactivating either package leaves all of that intact.

---

## Install

1. **Plugins → Add New → Upload Plugin** → `mcr-bilingue-1.0.0.zip` → Activate.
2. **Appearance → Themes → Add New → Upload Theme** → `marche-chemin-du-roy-1.0.0.zip`.
3. **Do not activate the theme yet** if the site is live — use Live Preview
   (Customize) first to see it against real products.
4. Activate when the preview looks right.
5. **Settings → Permalinks → Save** (no changes needed). This flushes rewrite
   rules so `/en/` URLs resolve.

## Configure

**Appearance → Customize → Marché Chemin-du-Roy**

- **Store details** — address, phone, WhatsApp, contact email, map link. These
  feed the top bar, the footer and the "Find us" panel.
- **Catalogues** — pick the top-level product category holding beauty and hair.
  That category and everything under it renders in the indigo palette. Leave it
  as "None" to keep the whole shop on the paper palette.
- **Delivery** — free-delivery threshold and radius. If a WooCommerce free
  shipping method already has a minimum order amount, *that* value wins, so the
  progress bar can never promise something checkout will not honour.
- **Home hero** — eyebrow, headline, subheading. Wrap a word in `<em>` to print
  it in gold.

**Appearance → Menus** — assign menus to: Main aisles (header), Utility links
(top bar), and the three footer columns. Without a header menu the theme falls
back to listing top-level product categories, which is a reasonable default.

**Products → featured** — the two products flagged Featured fill the hero side
panels. The second one renders in the indigo promo style.

### Delivery by distance (optional)

The theme ships a **Local delivery by distance** shipping method reproducing the
artifact's five distance bands.

WooCommerce → Settings → Shipping → *your zone* → Add shipping method → Local
delivery by distance. Configure bands as `up-to-km | price`, one per line, plus
a maximum radius.

Out of the box it maps postal codes to distances using the forward-sortation-area
table from the artifact (G8T, G9A, …). **Verify those distances against reality
before going live.** To use a real routing service instead:

```php
add_filter( 'mcr_distance_for_postcode', function ( $distance, $postcode ) {
    return my_routing_lookup( $postcode ); // km, or null if unknown
}, 10, 2 );
```

Everything downstream only needs the number of kilometres back.

---

## Bilingual setup

French is the default and owns the bare URLs. English is served under `/en/`.

**Translating a product:** edit it and fill in the *English translation* panel.
Empty fields fall back to French, so a partial translation is safe — never a
blank page. The **EN** column on Products → All Products shows how complete each
one is.

**Translating a category:** Products → Categories → Edit. The *URL slug (EN)*
field controls the English URL, so `/en/product-category/flours/` reads in
English instead of reusing `farines`.

**Attribute values** written as `Vert|Green` are split automatically — French
before the pipe, English after. No migration needed if the catalogue already
uses that convention.

**WooCommerce's own strings** ("Add to cart", "Proceed to checkout", order
emails) come from the official language packs, not from this plugin. Install
them under **Settings → General → Site Language**, and make sure both `fr_CA`
and `en_CA` are downloaded (Dashboard → Updates → Update Translations).

Order emails are sent in the language the order was placed in; the language is
recorded on the order and shown in the admin order screen.

---

## Post-deploy checks

Run through these on the live site. The automated suite in
`tools/smoke-test.sh` covers the same ground against a local install.

- [ ] Home page: hero, aisle grid, product rails, store hours all render
- [ ] Shop page: filters apply, sorting works, pagination works
- [ ] A simple product: Add lands it in the basket without a page reload
- [ ] A variable product: the price shows a **range**, and selecting a size
      changes the price shown
- [ ] Basket drawer opens, removes a line, closes on Escape
- [ ] Cart page and checkout render; place one real test order end to end
- [ ] Order confirmation email arrives, in the right language
- [ ] `/en/` loads; the language switch keeps you on the same page
- [ ] View source on a product: `hreflang` alternates for `fr-CA` and `en-CA`
- [ ] Prices read `24,99 $` in French and `$24.99` in English
- [ ] Mobile: bottom tab bar, filter sheet, drawer
- [ ] Existing plugins still work — payment gateway, SEO, analytics

### If something looks wrong

| Symptom | Cause | Fix |
|---|---|---|
| `/en/` 404s | Rewrite rules stale | Settings → Permalinks → Save |
| French UI shows English | `fr_CA.mo` missing | Confirm `languages/fr_CA.mo` is in the theme folder |
| Cart/checkout unstyled | Block styles dequeued | Confirm the Cart and Checkout pages still contain their WooCommerce block or shortcode |
| Prices unformatted | Plugin inactive | Activate MCR Bilingue |
| Wrong catalogue colour | Beauty category unset | Customize → Catalogues |

---

## Rollback

Activate the previous theme under **Appearance → Themes**. That is the whole
rollback — one click, no data migration, and orders placed in the meantime are
unaffected.

To also remove English: deactivate MCR Bilingue. Translations stay in the
database, so reactivating restores them.

---

## Performance and privacy notes

**Fonts.** By default the theme loads Bricolage Grotesque, Karla and IBM Plex
Mono from Google Fonts. For a Quebec business this is worth changing: Law 25 and
the GDPR both treat the CDN request as a transfer of visitor IP addresses to a
third party. To self-host, drop the font files and a `fonts.css` into
`assets/fonts/` — the theme uses them automatically and stops calling the CDN.
Or disable the CDN outright:

```php
add_filter( 'mcr_use_google_fonts', '__return_false' );
```

**Caching.** If a page cache is in front of the site, it must vary on the URL
path — which it already does, since `/` and `/en/` are different URLs. It must
**not** cache the cart fragment response (`?wc-ajax=`), which every WooCommerce
cache plugin already excludes.

**Images.** Products without a photo fall back to the aisle icon, matching the
artifact. Real photography is better: upload at 1000×1000 or larger and the
theme generates the sizes it needs.
