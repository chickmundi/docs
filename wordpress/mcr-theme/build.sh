#!/bin/bash
# Minify theme.css and theme.js (needs esbuild: npx esbuild). Run after every change to them.
# assets/min.json records which source each .min file was made from; PHP ignores a stale one.
set -e
cd "$(dirname "$0")/assets"
ESB=${ESBUILD:-npx --yes esbuild@0.24.0}
$ESB theme.css --minify --outfile=theme.min.css --log-level=warning
$ESB theme.js --minify --target=es2017 --outfile=theme.min.js --log-level=warning
printf '{"theme.css":"%s","theme.js":"%s"}\n' "$(md5sum theme.css | cut -d' ' -f1)" "$(md5sum theme.js | cut -d' ' -f1)" > min.json
echo "built: $(wc -c < theme.min.css) B css, $(wc -c < theme.min.js) B js"
