#!/usr/bin/env bash
# Builds a ready-to-install plugin ZIP into release/.
#
# The ZIP contains a single top-level folder whose name MUST stay identical to the
# folder on the site, otherwise WordPress treats it as a second, separate plugin:
#
#   C:\xampp\htdocs\wordpress\wp-content\plugins\infinite-scroll-gallery-V1-patched-fBtdMsMxW4Lo4qe4
#
# Usage:  ./build.sh
set -euo pipefail

SLUG="infinite-scroll-gallery-V1-patched-fBtdMsMxW4Lo4qe4"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="$ROOT/release"
STAGE="$OUT/.stage"

rm -rf "$STAGE"
mkdir -p "$STAGE/$SLUG"

# Everything that ships with the plugin. Keep this list in sync with the repo root.
FILES=(
  infinite-scroll-gallery-for-elementor.php
  includes/class-gallery-widget.php
  assets/js/ifg.js
  assets/css/ifg.css
  assets/js/vendor/gsap.min.js
  assets/js/vendor/Flip.min.js
  assets/js/vendor/SplitText.min.js
  assets/js/vendor/Observer.min.js
)

for f in "${FILES[@]}"; do
  if [ ! -f "$ROOT/$f" ]; then
    echo "missing: $f" >&2
    exit 1
  fi
  mkdir -p "$STAGE/$SLUG/$(dirname "$f")"
  cp "$ROOT/$f" "$STAGE/$SLUG/$f"
done

mkdir -p "$STAGE/$SLUG/languages"

rm -f "$OUT/$SLUG.zip"
( cd "$STAGE" && zip -qr "$OUT/$SLUG.zip" "$SLUG" -x '*.DS_Store' )
rm -rf "$STAGE"

echo "built: release/$SLUG.zip"
unzip -l "$OUT/$SLUG.zip"
