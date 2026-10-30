#!/usr/bin/env bash
# A game as one executable (bin/jumphp export:standalone), run in docker/standalone:
#   1. PHP's micro SAPI with JumPHP's extensions (StaticPHP + docker/standalone/registry), one per set of
#      extensions and registry (tools/standalone-key.sh): downloaded or built once, kept in /cache (~/.jumphp/standalone)
#   2. the game (games/<game>, its vendor/ with the engine) in a PHAR whose stub unpacks it once and boots it
#      (tools/standalone-stub.php)
#   3. micro + PHAR = games/<game>/dist/standalone/<game>-linux-x86_64 (or -windows-x86_64.exe)
# Environment: GAME, TARGET (linux-x86_64, windows-x86_64), JUMPHP_STANDALONE_BUILD=1 (build, never download). /workspace: the project; /cache: StaticPHP's downloads, builds and the micro binaries.
set -euo pipefail

say() { printf '\033[1;36m==> %s\033[0m\n' "$*" >&2; }
die() { printf '\033[1;31m[x] %s\033[0m\n' "$*" >&2; exit 1; }

TARGET="${TARGET:-linux-x86_64}"
PHP_VERSION="${JUMPHP_STANDALONE_PHP:-8.5}"
GAME_DIR="/workspace/games/$GAME"
OUT="$GAME_DIR/dist/standalone"
case "$TARGET" in
  linux-x86_64) FILE="$GAME-$TARGET" ;;
  windows-x86_64) FILE="$GAME-$TARGET.exe" ;;
  *) die "unknown target $TARGET (linux-x86_64, windows-x86_64)" ;;
esac

# 1. the micro (PHP + JumPHP's extensions) for this target: kept, else JumPHP's GitHub release of it, else built here
#    (Linux only: Windows' is built by .github/workflows/standalone.yml on a Windows machine)
key="$(cd /workspace && bash tools/standalone-key.sh | sha256sum | cut -c1-16)"
micro="/cache/micro-$TARGET-$key.sfx"
release="${JUMPHP_STANDALONE_RELEASES:-https://github.com/davidnurdin/jumPhp/releases/download}/standalone-$key/micro-$TARGET.sfx"
if [ ! -s "$micro" ] && [ -z "${JUMPHP_STANDALONE_BUILD:-}" ]; then
  say "PHP for $TARGET: $release"
  curl -fsSL -A jumphp-standalone -o "$micro.part" "$release" && mv "$micro.part" "$micro" || rm -f "$micro.part"
fi
if [ ! -s "$micro" ]; then
  [ "$TARGET" = linux-x86_64 ] || die "no PHP for $TARGET (standalone-$key) on JumPHP's GitHub releases: it is built on Windows by the workflow .github/workflows/standalone.yml (Actions > standalone > Run workflow), see docs/standalone.md"
  say "PHP $PHP_VERSION with SDL, OpenGL, OpenAL, built here (StaticPHP: ~15 min the first time, then kept)"
  for dir in downloads source buildroot pkgroot log; do mkdir -p "/cache/spc/$dir"; rm -rf "/opt/spc/$dir"; ln -s "/cache/spc/$dir" "/opt/spc/$dir"; done
  printf 'php-version: %s\nextensions: %s\nsapi:\n  - micro\n' "$PHP_VERSION" "$(cat /workspace/tools/standalone-extensions.txt)" > /opt/spc/craft.yml
  (cd /opt/spc && SPC_TARGET=native-native-gnu.2.17 SPC_REGISTRIES=/workspace/docker/standalone/registry/spc.registry.yml spc craft) \
    || die "StaticPHP failed: its logs are in ~/.jumphp/standalone/spc/log"
  cp /opt/spc/buildroot/bin/micro.sfx "$micro"
fi

# 2. the game, as the desktop runs it (tools/stage-game.sh)
say "the game: $GAME"
stage=/tmp/stage/game
build="$(bash /workspace/tools/stage-game.sh "$GAME_DIR" "$stage")" || die "games/$GAME has no vendor/ (bin/jumphp install $GAME)"
id="$(sed -nE 's/^ *id: *["'\'']?([a-z0-9-]+).*/\1/p' "$GAME_DIR/config/game.yaml" | head -1)"
id="${id:-$GAME}"

php -d phar.readonly=0 -r '
    [, $stage, $stub, $out, $id, $build] = $argv;
    @unlink($out);
    $phar = new Phar($out, 0, "jumphp-game.phar");
    $phar->startBuffering();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $phar->addFile($file->getPathname(), "game/" . substr($file->getPathname(), strlen($stage) + 1));
    }
    $phar->addEmptyDir("game/var");
    $phar->setStub(strtr(file_get_contents($stub), ["{{ID}}" => $id, "{{BUILD}}" => $build]));
    $phar->stopBuffering();
    echo $phar->count(), " files\n";
' "$stage" /workspace/tools/standalone-stub.php /tmp/stage/game.phar "$id" "$build" >&2

# 3. one file
mkdir -p "$OUT"
cat "$micro" /tmp/stage/game.phar > "$OUT/$FILE"
chmod 755 "$OUT/$FILE"
echo "OUT=$OUT/$FILE"
