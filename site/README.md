# Marché Chemin-du-Roy: new website

Fast static rebuild of marchecheminduroy.com (French) built with [Astro](https://astro.build).
All 1,128 products, 28 categories and 1,348 product images are imported from the current
WooCommerce store.

## Commands

```bash
npm install
npm run dev       # local preview at http://localhost:4321
npm run build     # production site in dist/
npm run import    # re-import products, categories and images from the live store
```

`npm run import -- --no-images` refreshes prices, stock and text without re-downloading images
(already-downloaded images are skipped anyway).

## Where things live

| What | File |
|---|---|
| Phone, email, address, hours, delivery threshold, WhatsApp number | `src/config.ts` |
| Product and category data (generated) | `src/data/products.json`, `src/data/categories.json` |
| About and policy page text (from the old site) | `src/data/pages.json` |
| Product images (WebP, max 800 px) | `public/images/products/` |
| Colors and fonts | `src/styles/global.css` |
| Redirects from old WooCommerce addresses (generated) | `public/.htaccess` |

## Pages

- `/`: homepage with categories, promotions, new arrivals and category rows
- `/boutique/`: all products (paginated)
- `/categorie/<slug>/`: categories and sub-categories
- `/produit/<slug>/`: product page with gallery, options (colour or size) and quantity
- `/promotions/`, `/recherche/?q=…`, `/panier/`
- `/a-propos/`, `/contact/`, `/conditions-generales/`, `/politique-de-confidentialite/`,
  `/politique-de-remboursement-et-de-retour/`

## Orders

The cart is kept in the shopper's browser. At checkout the customer fills in name, phone and
address (or picks in-store pickup), and the order opens as a ready-to-send email to
`sc@marchecheminduroy.com` or as a WhatsApp message.

WhatsApp (number in `src/config.ts`, `whatsapp`; set it to `''` to hide every WhatsApp button):
- product pages: "Commander via WhatsApp" with the product, options, quantity and price
- cart: "Commander via WhatsApp" with the full order, delivery fee and address
- every other page: a floating "Service client" button

There is **no online card payment yet**. Adding one needs a payment provider (for example Stripe Checkout).

## Deploying to Hostinger

Upload the contents of `dist/` (including the hidden `.htaccess`) to `public_html`. The `.htaccess`
301-redirects old addresses such as `/fr/product/…` and `/fr/product-category/…` to the new pages,
so existing Google results and shared links keep working.
