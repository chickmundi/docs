#!/usr/bin/env bash
#
# End-to-end smoke test for the Marché Chemin-du-Roy theme and plugin.
#
# Exercises every page a customer touches, in both languages, plus the
# add-to-cart round trip, and fails on any PHP error the run produced.
#
# Usage: BASE=http://127.0.0.1:8081 WP_DIR=/path/to/wp ./tools/smoke-test.sh

set -uo pipefail

BASE="${BASE:-http://127.0.0.1:8081}"
WP_DIR="${WP_DIR:-}"
CURL=(curl -s --noproxy '*' --max-time 30)
JAR="$(mktemp)"
PASS=0
FAIL=0

trap 'rm -f "$JAR"' EXIT

ok()   { printf '  \033[32mPASS\033[0m  %s\n' "$1"; PASS=$((PASS+1)); }
bad()  { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAIL=$((FAIL+1)); }
head_() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# expect_status <path> <expected>
expect_status() {
  local path="$1" want="$2" got
  got=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE$path")
  [[ "$got" == "$want" ]] && ok "$path -> $got" || bad "$path -> $got (expected $want)"
}

# Fetch a page into a variable.
fetch() {
  "${CURL[@]}" "$BASE$1"
}

# Substring test done in the shell itself.
#
# Never `printf ... | grep -q`: grep exits at the first match, the writer takes
# SIGPIPE, and under `pipefail` the pipeline reports failure even though the
# needle was found. That silently inverts results on large pages, which is
# exactly where it matters.
has() {
  local haystack="$1" needle="$2"
  [[ "$haystack" == *"$needle"* ]]
}

# expect_contains <path> <needle> <label>
expect_contains() {
  local path="$1" needle="$2" label="$3" body
  body=$(fetch "$path")
  if has "$body" "$needle"; then
    ok "$label"
  else
    bad "$label  (missing: $needle  on $path)"
  fi
}

# expect_absent <path> <needle> <label>
expect_absent() {
  local path="$1" needle="$2" label="$3" body
  body=$(fetch "$path")
  if has "$body" "$needle"; then
    bad "$label  (unexpectedly present: $needle)"
  else
    ok "$label"
  fi
}

if [[ -n "$WP_DIR" && -f "$WP_DIR/wp-content/debug.log" ]]; then
  : > "$WP_DIR/wp-content/debug.log"
fi

head_ "Pages respond"
for p in / /shop/ /cart/ /my-account/ /product/gari-5kg/ /product/perruque-aziza/ \
         /product-category/farines/ /en/ /en/shop/ /en/product/gari-5kg/; do
  expect_status "$p" 200
done
# An empty cart sends checkout back to the cart page; that is WooCommerce
# behaving correctly, not a routing fault.
expect_status "/checkout/" 302
expect_status "/this-page-does-not-exist/" 404

head_ "Layout is present"
expect_contains /        'class="hero-main"'      "home: hero"
expect_contains /        'class="trust"'          "home: trust strip"
expect_contains /        'class="aisles"'         "home: aisle grid"
expect_contains /shop/   'class="toolbar"'        "shop: toolbar"
expect_contains /shop/   'id="filters"'           "shop: filter rail"
expect_contains /shop/   'grid products'          "shop: product grid"
expect_contains /shop/   'class="card'            "shop: product cards"
expect_contains /product/gari-5kg/ 'class="pdp"'  "pdp: two-column grid"
expect_contains /product/gari-5kg/ 'pdp-trust'    "pdp: trust row"
expect_contains /product/gari-5kg/ 'class="deflist"' "pdp: meta list"

head_ "Accessibility scaffolding"
expect_contains /       'class="skip-link'        "skip link present"
expect_contains /       'data-cart-announce'      "cart live region"
expect_contains /shop/  'aria-expanded'           "filter toggle state"
expect_contains /       '<symbol id="i-cart"'     "icon sprite inlined"

head_ "Bilingual"
expect_contains /                   'lang="fr-CA"'  "FR: html lang"
expect_contains /en/                'lang="en-CA"'  "EN: html lang"
expect_contains /en/                'hreflang="fr-CA"' "EN: hreflang alternate"
expect_contains /en/                'hreflang="x-default"' "EN: x-default"
expect_contains /product/gari-5kg/    'farine de manioc' "FR: French title"
expect_contains /en/product/gari-5kg/ 'cassava flour'    "EN: translated title"

head_ "Interface language"
# The theme's source strings are English; French comes from languages/fr_CA.mo.
# A theme textdomain loads {locale}.mo, not {domain}-{locale}.mo — getting that
# filename wrong silently leaves the whole French storefront in English.
expect_contains /shop/    'Ajouter'            "FR: buttons translated"
expect_contains /shop/    'Aller au contenu'   "FR: skip link translated"
expect_contains /shop/    'Filtres'            "FR: filters translated"
expect_contains /en/shop/ 'Add'                "EN: buttons in English"
expect_absent   /en/shop/ 'Aller au contenu'   "EN: no French leaking through"

head_ "Price formatting per locale"
# Quebec French: 24,99 $ — comma decimal, trailing sign.
expect_contains /product/gari-5kg/    '24,99'  "FR: comma decimal"
# Canadian English: $24.99 — leading sign, period decimal.
expect_contains /en/product/gari-5kg/ '24.99'  "EN: period decimal"
# A variable product must show a real range, never a single 'from' price.
expect_contains /product/perruque-aziza/ '114,20' "FR: variable price range top"
expect_contains /product/perruque-aziza/ '89,99'  "FR: variable price range base"

head_ "Cart round trip"
COOKIES=(-c "$JAR" -b "$JAR")
SHOP_BODY=$(fetch /shop/)
PID=$(grep -o 'data-add-to-cart="[0-9]*"' <<<"$SHOP_BODY" | head -1 | grep -o '[0-9]*')
if [[ -n "${PID:-}" ]]; then
  ok "found an ajax add-to-cart button (product $PID)"
  ADD=$("${CURL[@]}" "${COOKIES[@]}" -X POST \
        -d "product_id=$PID&quantity=1" "$BASE/?wc-ajax=add_to_cart")
  if has "$ADD" 'fragments'; then
    ok "add_to_cart returned fragments"
  else
    bad "add_to_cart returned no fragments: ${ADD:0:120}"
  fi
  if has "$ADD" 'data-cart-count'; then
    ok "cart count fragment refreshed"
  else
    bad "cart count fragment missing"
  fi
  # The store may use the classic [woocommerce_cart] shortcode or the newer
  # Cart block, which renders client-side. Both are supported, so accept either.
  CART_BODY=$("${CURL[@]}" "${COOKIES[@]}" "$BASE/cart/")
  if has "$CART_BODY" 'cart-form__cart-item' || has "$CART_BODY" 'wp-block-woocommerce-cart'; then
    ok "cart page renders (classic or block)"
  else
    bad "cart page did not render"
  fi

  CHECKOUT_BODY=$("${CURL[@]}" "${COOKIES[@]}" -L "$BASE/checkout/")
  if has "$CHECKOUT_BODY" 'place_order' || has "$CHECKOUT_BODY" 'woocommerce-checkout' \
     || has "$CHECKOUT_BODY" 'wp-block-woocommerce-checkout'; then
    ok "checkout renders (classic or block)"
  else
    bad "checkout did not render"
  fi

  if has "$CART_BODY" 'prog-wrap'; then
    ok "free-delivery bar present on the cart"
  else
    bad "free-delivery bar missing on the cart"
  fi
else
  bad "no ajax add-to-cart button found on the shop page"
fi

head_ "No PHP errors from our code"
if [[ -n "$WP_DIR" && -f "$WP_DIR/wp-content/debug.log" ]]; then
  HITS=$(grep -E "Fatal|Warning|Notice" "$WP_DIR/wp-content/debug.log" 2>/dev/null \
         | grep -Ei "marche-chemin-du-roy|mcr-bilingue" | grep -v "Deprecated" | head -20)
  if [[ -z "$HITS" ]]; then
    ok "debug.log clean of theme and plugin errors"
  else
    bad "PHP errors logged:"
    echo "$HITS" | sed 's/^/        /'
  fi
else
  printf '  \033[33mSKIP\033[0m  debug.log not checked (set WP_DIR)\n'
fi

printf '\n\033[1mResult: %d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
