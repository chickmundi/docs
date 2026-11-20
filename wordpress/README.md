# Marché Chemin-du-Roy on WordPress

Two pieces, both small and without page builders:

- **mcr-theme**: the storefront look (header, categories, product pages, cart, checkout, mobile menu, WhatsApp buttons).
- **mcr-gestion**: the store's own back-office features, in *WordPress admin > MCR Gestion*:
  - French / English (`/fr/` and `/en/` addresses, English fields on products, categories and pages)
  - store details and opening hours
  - delivery by driving distance (price tiers, free from a minimum within a radius, maximum distance) and in-store pickup
  - order steps: *En préparation*, *Prête au retrait*, *En livraison* (customer emails at the last two)
  - grocery and hair details, departments, lots and expiry dates
  - reports by department, dashboard figures, homepage sections, announcement bar
  - copying the English translations from the old site (*Traductions anglaises*)

Test site: https://staging.marchecheminduroy.com/fr/ (hidden from search engines).

## Deploy

```bash
wordpress/deploy.sh plugin wordpress/mcr-gestion staging.marchecheminduroy.com
```

```bash
wordpress/deploy.sh theme wordpress/mcr-theme staging.marchecheminduroy.com
```

The Hostinger API token is read from `HOSTINGER_API_TOKEN` (or injected by the session proxy).

## Data files

- `build-catalog.mjs` makes `mcr-gestion/data/catalog.json` and `pages.json` (used only by the test-site import).
- `collect-english.mjs` reads the English texts shown on the current site's `/en/` pages and makes `mcr-gestion/data/en.json`.

## Going live (only after approval)

1. Take a full backup in hPanel (files and database).
2. Deactivate TranslatePress (both handle `/fr/` and `/en/`; only one may run), then install and activate **mcr-gestion** and **mcr-theme** on marchecheminduroy.com.
3. *MCR Gestion > Traductions anglaises*: import the English texts.
4. *WooCommerce > Réglages > Expédition*: check the "Québec" zone has *Livraison MCR* and *Retrait en magasin MCR*; remove the old distance plugin's method.
5. Check *MCR Gestion > Paramètres* (hours, delivery prices, pickup, homepage).
6. Place a test order (delivery and pickup), then deactivate Elementor, the Grogin theme add-ons and the distance plugin.
7. Clear LiteSpeed Cache.

The test-site import (*Import (essai)*) refuses to run on a site that already has products or orders.

## Design system (theme 1.0)

- Colours (CSS variables in `mcr-theme/assets/theme.css`, section 1): forest green `--green-700` #174b35 (main buttons, header), deep green `--green-900/950` (footer, bands), terracotta `--terra-500` #c96b43 (aisle labels, promotional banners), muted gold `--gold-500` #e6b74f (discounts, search button), warm ivory `--ivory` #f8f6ef (page), sage `--sage` and sand `--sand` (tiles, panels).
- Type: Plus Jakarta Sans (interface and headings), Fraunces (display headlines in the hero, banners and promotions).
- Components: `template-parts/card.php` (one product card everywhere), `template-parts/home/*.php` (homepage sections), `template-parts/sidebar.php` (shop filters), header with mega menu and live search (WooCommerce Store API).
- Homepage text, banners and section order: *MCR Gestion > Paramètres > Page d'accueil*.
