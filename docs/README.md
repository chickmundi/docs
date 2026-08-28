# Marché Chemin-du-Roy — WooCommerce theme

A custom WordPress/WooCommerce storefront for
[marchecheminduroy.com](https://marchecheminduroy.com), an African and Caribbean
grocery in Trois-Rivières, built from the interface design in the source
artifact.

```
theme/marche-chemin-du-roy/   The theme
plugin/mcr-bilingue/          FR/EN companion plugin
tools/build.sh                Builds installable .zip packages
tools/smoke-test.sh           End-to-end test suite (48 checks)
docs/DEPLOY.md                Deployment runbook
docs/DESIGN.md                Design system and what changed from the artifact
```

## Architecture

**Classic theme, not a block theme.** The product page, listing, cart and
checkout are custom enough that WooCommerce's block templates would have forced
visible compromises. Design tokens are still exposed to the block editor, so
marketing pages can be built in the editor and inherit the palette.

**Presentation in the theme, content in the plugin.** Anything that must survive
a future theme change — translations, URL routing, price formats — lives in
`mcr-bilingue`. The theme degrades to a working French-only storefront if the
plugin is deactivated.

**Hooks over template overrides.** WooCommerce builds pages from hooks, so the
theme unhooks what it does not want and hooks its own partials in. Only five
Woo templates are overridden, each carrying the `@version` it was forked at.
Fewer overrides means fewer "template out of date" warnings after a Woo update.

### The bilingual model

One product record, translated fields beside it — not one product per language.

The deciding reason is inventory: the shop has one physical shelf of gari.
Duplicating products would mean two stock counts to reconcile, and grocery stock
moves daily. Prices, SKUs and stock stay singular; only text is translated.

French owns the bare URLs, English is served under `/en/`. The prefix is
stripped from the request before WordPress routes it, so every permalink
structure, custom post type and plugin endpoint keeps working without the plugin
knowing about any of them.

## Testing

```bash
# against a local WordPress with WooCommerce and both packages active
BASE=http://127.0.0.1:8080 WP_DIR=/path/to/wp ./tools/smoke-test.sh
```

48 checks: every customer-facing page in both languages, the layout components,
accessibility scaffolding, `hreflang`, per-locale price formatting, the
add-to-cart round trip through WooCommerce's own AJAX endpoint, and a scan of
`debug.log` for PHP errors from theme or plugin code.

Verified against WordPress 6.8.2 and WooCommerce 9.4.3, with cart and checkout
in **both** the classic shortcode and the newer block form.

## Requirements

WordPress 6.4+, WooCommerce 8.0+, PHP 8.0+. Below those the theme shows an
admin notice instead of loading, so a version mismatch cannot take the
storefront down.
