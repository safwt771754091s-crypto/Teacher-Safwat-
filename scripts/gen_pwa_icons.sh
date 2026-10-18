#!/usr/bin/env bash
# Generate the PWA icons (real PNGs) for the web app.
#
# Renders the brand mark (the 📚 book on the platform green) with headless
# Chromium at each required size, so the icons are pixel-accurate PNGs rather
# than SVG data URIs (Android/Chrome only mask a real PNG reliably).
#
#   bash scripts/gen_pwa_icons.sh
#
# Output (web/assets/icons/):
#   icon-192.png            any purpose, 192x192
#   icon-512.png            any purpose, 512x512
#   icon-maskable-512.png   maskable (full bleed, art inside the safe zone)
#   apple-touch-icon-180.png  iOS home-screen icon
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/web/assets/icons"
CHROME="${CHROME:-chromium}"
BG="#2f6a43"
mkdir -p "$OUT"

render() { # size radius_ratio emoji_ratio outfile
    local size="$1" radius_ratio="$2" emoji_ratio="$3" out="$4"
    local radius emoji
    radius=$(python3 -c "print(round($size*$radius_ratio))")
    emoji=$(python3 -c "print(round($size*$emoji_ratio))")
    local html
    html="$(mktemp --suffix=.html)"
    cat > "$html" <<HTML
<!doctype html><meta charset="utf-8"><style>
  *{margin:0;padding:0;box-sizing:border-box}
  html,body{width:${size}px;height:${size}px;background:transparent}
  .icon{width:${size}px;height:${size}px;background:${BG};border-radius:${radius}px;
        display:flex;align-items:center;justify-content:center}
  .icon span{font-family:'Noto Color Emoji',sans-serif;font-size:${emoji}px;line-height:1}
</style><div class="icon"><span>📚</span></div>
HTML
    "$CHROME" --headless --no-sandbox --disable-gpu --hide-scrollbars \
        --default-background-color=00000000 --force-device-scale-factor=1 \
        --window-size="${size},${size}" --screenshot="$out" "file://$html" >/dev/null 2>&1
    rm -f "$html"
    [ -s "$out" ] || { echo "failed to render $out"; exit 1; }
    echo "  $(basename "$out")  $(du -h "$out" | cut -f1)"
}

echo "generating PWA icons in web/assets/icons/ ..."
# Any purpose: rounded square, art ~56% of the canvas.
render 192 0.22 0.56 "$OUT/icon-192.png"
render 512 0.22 0.56 "$OUT/icon-512.png"
# Maskable: full bleed (the launcher applies its own mask); keep the art inside
# the safe zone (inner ~80% circle) so nothing important is cropped.
render 512 0.00 0.44 "$OUT/icon-maskable-512.png"
# iOS home screen: must be opaque (iOS fills transparency with black) and full
# bleed - the OS applies its own rounded mask.
render 180 0.00 0.56 "$OUT/apple-touch-icon-180.png"
echo "done."
