#!/usr/bin/env bash
#
# build-assets.sh
#
# Regenerates the minified .min.js / .min.css variants for every frontend
# asset in the plugin. Run this before committing source changes so the
# .min.* files (and their content-hash cache-buster) stay in sync.
#
# Requirements (install once, globally):
#   npm install -g terser clean-css-cli
#
# Usage:
#   bin/build-assets.sh              # minify everything
#   bin/build-assets.sh --check      # fail if any .min.* is stale vs its source
#
# @license GPL-2.0-or-later
#

set -euo pipefail

PLUGIN_ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$PLUGIN_ROOT"

CHECK_MODE=0
if [[ "${1:-}" == "--check" ]]; then
    CHECK_MODE=1
fi

# Assets to (re)minify. Admin assets are included so both surfaces benefit.
JS_SOURCES=(
    "assets/js/chat-launcher.js"
    "assets/js/chat-widget.js"
    "assets/js/admin.js"
    "assets/js/lite-upsell.js"
)

CSS_SOURCES=(
    "assets/css/chat-launcher.css"
    "assets/css/chat-widget.css"
    "assets/css/admin.css"
    "assets/css/lite-upsell.css"
)

require_bin() {
    local bin="$1"
    if ! command -v "$bin" >/dev/null 2>&1; then
        echo "ERROR: '$bin' not found in PATH." >&2
        echo "Install with:  npm install -g terser clean-css-cli" >&2
        exit 1
    fi
}

require_bin terser
require_bin cleancss

stale=0
built=0

minify_js() {
    local src="$1"
    local min="${src%.js}.min.js"

    if [[ ! -f "$src" ]]; then
        echo "  skip (missing) $src"
        return
    fi

    if [[ $CHECK_MODE -eq 1 ]]; then
        if [[ ! -f "$min" || "$src" -nt "$min" ]]; then
            echo "  STALE $min (source is newer)"
            stale=$((stale + 1))
        fi
        return
    fi

    terser "$src" \
        --compress \
        --mangle \
        --comments "/^!|@preserve|@license|@cc_on/i" \
        --output "$min"
    echo "  built $min  ($(wc -c < "$src") → $(wc -c < "$min") bytes)"
    built=$((built + 1))
}

minify_css() {
    local src="$1"
    local min="${src%.css}.min.css"

    if [[ ! -f "$src" ]]; then
        echo "  skip (missing) $src"
        return
    fi

    if [[ $CHECK_MODE -eq 1 ]]; then
        if [[ ! -f "$min" || "$src" -nt "$min" ]]; then
            echo "  STALE $min (source is newer)"
            stale=$((stale + 1))
        fi
        return
    fi

    cleancss -o "$min" "$src"
    echo "  built $min  ($(wc -c < "$src") → $(wc -c < "$min") bytes)"
    built=$((built + 1))
}

echo "JavaScript:"
for src in "${JS_SOURCES[@]}"; do
    minify_js "$src"
done

echo
echo "CSS:"
for src in "${CSS_SOURCES[@]}"; do
    minify_css "$src"
done

if [[ $CHECK_MODE -eq 1 ]]; then
    echo
    if [[ $stale -gt 0 ]]; then
        echo "FAIL: $stale asset(s) stale. Run bin/build-assets.sh to rebuild." >&2
        exit 1
    fi
    echo "OK: all minified assets are up to date."
    exit 0
fi

echo
echo "Done. $built asset(s) rebuilt."
