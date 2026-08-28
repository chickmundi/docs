# Design system

Ported from the source artifact. The palette, type scale, spacing, radii and
component shapes are carried over deliberately unchanged — the artifact was the
approved design, and this is its implementation rather than a reinterpretation.

## Tokens

Defined once in `assets/css/01-tokens.css` and consumed everywhere.

| Role | Token | Value |
|---|---|---|
| Page ground | `--paper` | `#F7F3EA` |
| Raised surface | `--surface` | `#FFFFFF` |
| Ink | `--ink` | `#221A15` |
| Secondary ink | `--ink-2` | `#6B5F55` |
| Hairline | `--line` | `#E4DCCC` |
| Accent (red) | `--accent` | `#C5233A` |
| Gold | `--gold` | `#E8A317` |
| Green | `--green` | `#1E6B4F` |
| Beauty catalogue | `--indigo` | `#23304F` |
| Deep (header/footer) | `--deep` | `#1E1611` |

**Type.** Bricolage Grotesque for display (800 weight, tight tracking), Karla
for text, IBM Plex Mono for labels, codes and numerics. Prices use
`font-variant-numeric: tabular-nums` so columns of figures align.

**Radii** `--r-sm: 9px`, `--r: 14px`, `--r-lg: 22px`. Pills use `99px`.

**Two shadow levels** only: `--shadow` at rest, `--shadow-lift` on hover.

## Stylesheet layers

Loaded in cascade order; each depends on the one before it.

```
01-tokens        custom properties
02-base          reset, typography, focus rings
03-header        top bar, masthead, catalogue tabs
04-hero          home hero and promos
05-components    sections, aisles, cards, footer
06-overlays      cart drawer, mobile tab bar, nudge
07-shop          listing, PDP, cart and checkout components
08-woocommerce   adapter: Woo's markup onto the components above
09-woo-blocks    adapter: the block Cart and Checkout
```

Layers 01–07 are the design system and carry no WooCommerce selectors, so they
stay portable. Everything Woo-specific is quarantined in 08 and 09.

## Changes from the artifact

The design is reproduced as-is. These are the behavioural corrections, each one
a defect in the artifact rather than a design choice:

**Variant pricing.** All 58 variant rows in the artifact had a blank price, so
every size and length charged the base price — a 1 kg bag of gari cost the same
as 5 kg, an 18" wig the same as 30". WooCommerce variations carry real prices,
and the card now shows a genuine range. The word "from" appears only when the
range is actually a range; the artifact printed "dès" on every variable product
including ones where nothing was cheaper.

**Quick-add price mismatch.** The artifact's card Add button recorded the first
option of each attribute but charged the *cheapest* variant's price. Products
needing a choice now link to the product page instead of guessing.

**Price formatting.** The artifact printed `12,99 $` in both languages. French
now gets `12,99 $` and English `$12.99`, applied wherever WooCommerce formats a
price.

**Cart persistence.** The artifact's basket was in-memory and emptied on reload.
WooCommerce sessions handle this natively.

**Free-delivery threshold.** Read from the WooCommerce free-shipping method when
one is configured, so the progress bar cannot promise what checkout will not
honour.

**Filters.** Rebuilt as a plain GET form. Filter state is linkable, shareable,
survives a reload and works with JavaScript off; the artifact's was JS-only.

**Breakpoints.** The artifact used eleven ad-hoc breakpoints (560, 620, 640,
700, 760, 820, 860, 900, 960, 1000, 1100). The port keeps the artifact's own
values where they carry layout, rather than reflowing a design that was already
approved — but new components added here use a four-step scale of 640 / 900 /
960 / 1180.

**Accessibility.** Added a skip link, a polite live region announcing cart
changes, focus trapping and Escape handling in the drawer, `aria-expanded` on
the filter toggle, and language-aware `aria-label`s — the artifact hardcoded
several in French. `<html lang>` now tracks the displayed language, which is
what screen readers switch pronunciation on.

**Escaping.** Every interpolated value is escaped at output. The artifact was
inconsistent — `esc()` in 75 places, but not on the confirmation heading or
image `src`.
