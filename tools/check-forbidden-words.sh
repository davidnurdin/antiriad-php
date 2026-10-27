#!/usr/bin/env bash
# Fails when the repository contains a word that must never appear (brand names of other games...).
# The words are stored rot13-encoded so that this file does not contain them itself.
# Add one: echo "word" | tr 'A-Za-z' 'N-ZA-Mn-za-m'  -> append to WORDS.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORDS=(fbavp frtn ebobgavx rttzna xahpxyrf zvzvpung)
status=0
for encoded in "${WORDS[@]}"; do
  word="$(echo "$encoded" | tr 'A-Za-z' 'N-ZA-Mn-za-m')"
  if hits="$(cd "$ROOT" && grep -rniI --exclude-dir={vendor,.git,.build,.cache,build,dist,node_modules} -e "$word" . 2>/dev/null)"; then
    echo "forbidden word found ($encoded, rot13):"; echo "$hits" | head -20; status=1
  fi
  if paths="$(cd "$ROOT" && find . -path ./.git -prune -o \( -path '*/vendor' -o -path ./build -o -path ./.build -o -path ./.cache \) -prune -o -iname "*$word*" -print)" && [ -n "$paths" ]; then
    echo "forbidden word in a file name ($encoded, rot13):"; echo "$paths"; status=1
  fi
done
[ $status -eq 0 ] && echo "no forbidden word"
exit $status
