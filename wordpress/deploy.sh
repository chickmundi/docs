#!/usr/bin/env bash
# Deploy the MCR plugin or theme to a Hostinger WordPress site.
# Usage: wordpress/deploy.sh plugin|theme <directory> <domain>
# Needs the Hostinger API token (sent by the session's network proxy, or HOSTINGER_API_TOKEN).
set -euo pipefail
KIND=$1; DIR=$2; DOMAIN=$3
SLUG=$(basename "$DIR")
API=https://developers.hostinger.com/api/hosting/v1
AUTH=()
[ -n "${HOSTINGER_API_TOKEN:-}" ] && AUTH=(-H "Authorization: Bearer $HOSTINGER_API_TOKEN")
USERNAME=$(curl -sS "${AUTH[@]}" "$API/websites?domain=$DOMAIN" | python3 -I -c "import json,sys;print(json.load(sys.stdin)['data'][0]['username'])")
CREDS=$(curl -sS "${AUTH[@]}" -X POST -H 'Content-Type: application/json' -d "{\"username\":\"$USERNAME\",\"domain\":\"$DOMAIN\"}" "$API/files/upload-urls")
URL=$(echo "$CREDS" | python3 -I -c "import json,sys;print(json.load(sys.stdin)['url'])")
AK=$(echo "$CREDS" | python3 -I -c "import json,sys;print(json.load(sys.stdin)['auth_key'])")
RK=$(echo "$CREDS" | python3 -I -c "import json,sys;print(json.load(sys.stdin)['rest_auth_key'])")
UPDIR="$SLUG-$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
BASE=$([ "$KIND" = plugin ] && echo wp-content/plugins || echo wp-content/themes)
n=0
while IFS= read -r -d '' f; do
  rel=${f#"$DIR"/}
  size=$(stat -c%s "$f")
  target="$BASE/$UPDIR/$rel"
  enc=$(python3 -I -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "$target")
  for attempt in 1 2 3; do
    c=$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$URL/$enc?override=true" -H "X-Auth: $AK" -H "X-Auth-Rest: $RK" -H "Tus-Resumable: 1.0.0" -H "Upload-Length: $size" -H "Upload-Offset: 0" || true)
    off=$(curl -sS -D - -o /dev/null -X PATCH "$URL/$enc?override=true" -H "X-Auth: $AK" -H "X-Auth-Rest: $RK" -H "Tus-Resumable: 1.0.0" -H "Content-Type: application/offset+octet-stream" -H "Upload-Offset: 0" --data-binary "@$f" | tr -d '\r' | awk -F': ' 'tolower($1)=="upload-offset"{print $2}' || true)
    [ "$c" = 201 ] && [ "$off" = "$size" ] && break
    [ $attempt = 3 ] && { echo "upload failed: $rel ($c, $off/$size)"; exit 1; }
    sleep 2
  done
  n=$((n+1))
done < <(find "$DIR" -type f ! -name '.*' -print0)
echo "uploaded $n files to $UPDIR"
if [ "$KIND" = plugin ]; then
  BODY="{\"slug\":\"$SLUG\",\"plugin_path\":\"$UPDIR\"}"; EP=plugins
else
  BODY="{\"slug\":\"$SLUG\",\"theme_path\":\"$UPDIR\",\"is_activated\":true}"; EP=themes
fi
curl -sS "${AUTH[@]}" -w ' HTTP %{http_code}\n' -X POST -H 'Content-Type: application/json' -d "$BODY" "$API/accounts/$USERNAME/websites/$DOMAIN/wordpress/$EP/deploy"
