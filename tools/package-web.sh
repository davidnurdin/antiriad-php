#!/bin/sh
# Browser build, step 2 (runs in the emscripten image, called by bin/jumphp build): packs the staged game into
# php-web.data (mounted at /game in the browser), injects its loader into the engine, writes version.json.
set -eu
GAME_DIR="$1"
GAME_ID="$(basename "$GAME_DIR")"
STAGE="/workspace/.build/$GAME_ID"
ENGINE=/workspace/build/engine
OUT="$GAME_DIR/dist/web"

rm -rf "$OUT"
mkdir -p "$OUT"
cp "$STAGE".web/* "$OUT/"
cp "$ENGINE/php-web.mjs" "$ENGINE/php-web.wasm" "$ENGINE/php-wasm-network.js" "$ENGINE/engine.json" "$OUT/"

cd "$OUT"
python3 /emsdk/upstream/emscripten/tools/file_packager.py php-web.data \
    --preload "$STAGE@/game" --lz4 --use-preload-cache --no-node \
    --exclude '*/.git' --exclude '*/.git*' --exclude '*/.DS_Store' --js-output=php-web.data.js --export-name=createPhpModule >/dev/null

# at -O3 emcc drops the --pre-js comment: the data loader goes right after the module's run()
MARKER='Module\["preInit"\]\.pop()()}}run();'
grep -q "$MARKER" php-web.mjs || { echo "injection marker not found in php-web.mjs (engine built with another -O?)" >&2; exit 1; }
sed "/$MARKER/r php-web.data.js" php-web.mjs > php-web.mjs.tmp && mv php-web.mjs.tmp php-web.mjs
rm php-web.data.js

hash() { sha256sum "$1" | cut -c1-12; }
cat > version.json <<JSON
{
  "build": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "php-web.mjs": "$(hash php-web.mjs)",
  "php-web.wasm": "$(hash php-web.wasm)",
  "php-web.data": "$(hash php-web.data)"
}
JSON
ls -la "$OUT"
