#!/usr/bin/env bash
# stage-game.sh <games/game folder> <destination>: the game as the desktop runs it, for a package that carries it
# (export:standalone, the Android app): its files and its vendor/ (the engine's symbolic link followed), without
# what only development needs (var/, dist/, tests/, site/, core dumps, the engine's tests / web / own vendor, *.md).
# + cacert.pem: the CA certificates of HTTPS (Debian's, from the build image), for the phones and computers whose own
# are not where PHP's OpenSSL looks (the package points SSL_CERT_FILE at it). Prints the build: a hash of the files.
set -euo pipefail
src="$1"
dest="$2"
[ -f "$src/vendor/autoload.php" ] || { echo "$src has no vendor/ (bin/jumphp install)" >&2; exit 1; }
rm -rf "$dest" && mkdir -p "$dest"
(cd "$src" && tar -ch --exclude=./var --exclude=./dist --exclude=./tests --exclude=./site --exclude=./cache \
    --exclude=./android --exclude=./.phpunit.cache --exclude='./core' --exclude='./core.[0-9]*' \
    --exclude='./vendor/jumphp/engine/tests' --exclude='./vendor/jumphp/engine/web' \
    --exclude='./vendor/jumphp/engine/vendor' --exclude='./vendor/jumphp/engine/.phpunit.cache' --exclude='*.md' . ) \
  | tar -x -C "$dest"
mkdir -p "$dest/var"
if [ -f /etc/ssl/certs/ca-certificates.crt ]; then cp /etc/ssl/certs/ca-certificates.crt "$dest/cacert.pem"
elif [ -f /etc/ssl/cert.pem ]; then cp /etc/ssl/cert.pem "$dest/cacert.pem"; fi # macOS (the Apple apps' build)
sha=(sha256sum); command -v sha256sum >/dev/null || sha=(shasum -a 256) # macOS
(cd "$dest" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 "${sha[@]}" | "${sha[@]}" | cut -c1-12)
