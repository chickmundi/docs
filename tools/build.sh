#!/usr/bin/env bash
#
# Build installable .zip packages for the theme and the companion plugin.
#
# Usage: ./tools/build.sh [output-dir]
# Output: dist/marche-chemin-du-roy-<version>.zip
#         dist/mcr-bilingue-<version>.zip

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
STAGE="$(mktemp -d)"

trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$OUT"

version_of() {
  grep -m1 -oE '^\s*\*?\s*Version:\s*[0-9][0-9.]*' "$1" | grep -oE '[0-9][0-9.]*'
}

package() {
  local src="$1" slug="$2" header="$3" version
  version="$(version_of "$header")"

  if [[ -z "$version" ]]; then
    echo "error: no Version header in $header" >&2
    exit 1
  fi

  echo "  $slug $version"

  rm -rf "${STAGE:?}/$slug"
  cp -R "$src" "$STAGE/$slug"

  # Development-only files never ship to a production server.
  find "$STAGE/$slug" \( \
       -name '.*' -o -name '*.po' -o -name 'node_modules' -o \
       -name 'tests' -o -name 'phpcs.xml*' -o -name 'composer.*' -o \
       -name '*.map' -o -name 'README.dev.md' \) -prune -exec rm -rf {} + 2>/dev/null || true

  rm -f "$OUT/$slug-$version.zip"
  ( cd "$STAGE" && zip -qr "$OUT/$slug-$version.zip" "$slug" -x '*.DS_Store' )
}

echo "Building into $OUT"
package "$ROOT/theme/marche-chemin-du-roy"  "marche-chemin-du-roy" "$ROOT/theme/marche-chemin-du-roy/style.css"
package "$ROOT/plugin/mcr-bilingue"         "mcr-bilingue"         "$ROOT/plugin/mcr-bilingue/mcr-bilingue.php"

echo
echo "Packages:"
ls -lh "$OUT"/*.zip | awk '{printf "  %-46s %s\n", $9, $5}'
