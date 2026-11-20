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
