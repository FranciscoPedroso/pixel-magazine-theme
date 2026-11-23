#!/usr/bin/env bash
# Regenerates assets/fonts/material-symbols-outlined.woff2 with only the icons
# the theme uses. The full font weighs 1.1 MB for 18 icons and, on mobile, it
# competes with the main image for bandwidth.
#
# When using a new icon (in PHP or JS), add it to ICONS and run:
#   bash bin/subset-icons.sh
# An icon missing from the list renders as text (its ligature name).
set -euo pipefail

# Alphabetical order: the Google Fonts API requires it.
ICONS="arrow_back,arrow_forward,bookmark,close,contrast,dark_mode,edit,forum,headphones,light_mode,mail,person,search,share,shield,visibility,visibility_off,warning"

DIR="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$DIR/assets/fonts/material-symbols-outlined.woff2"
UA="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/150 Safari/537.36"

CSS=$(curl -fsS -A "$UA" "https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&icon_names=${ICONS}&display=block")
URL=$(printf '%s' "$CSS" | grep -o 'https://fonts.gstatic.com[^)]*' | head -1)
[ -n "$URL" ] || { echo "Não encontrei o URL da fonte na resposta do Google Fonts." >&2; exit 1; }

curl -fsS -o "$OUT.tmp" "$URL"
mv "$OUT.tmp" "$OUT"
echo "OK: $(wc -c < "$OUT" | tr -d ' ') bytes em $OUT"
