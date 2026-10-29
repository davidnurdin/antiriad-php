#!/usr/bin/env bash
# Screenshots of the game for its web site (site/images/) and its thumbnail (assets/thumbnail.png).
# Run from the JumPHP project's root, after tools/extract/extract.sh:  games/antiriad-php/tools/site/shots.sh
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../../../.." && pwd)
GAME=antiriad-php
DIR="$ROOT/games/$GAME"
OUT="$DIR/var/screenshots/site"
mkdir -p "$OUT" "$DIR/site/images"
cd "$ROOT"

shot() {  # shot <name> <frames> <scene params JSON> [input JSON]
  JUMPHP_SCENE='App\Scene\PlayScene' JUMPHP_SCENE_PARAMS="$3" JUMPHP_INPUT="${4:-[]}" \
    bin/jumphp shot "$GAME" "$2" "var/screenshots/site/$1.png" >/dev/null
}
bin/jumphp shot "$GAME" 150 var/screenshots/site/title.png >/dev/null
shot jungle 110 '{"lives":5}' '[[20,"right","hold"],[75,"right","release"],[80,"fire"]]'
shot suit 150 '{"room":63,"x":12,"y":130,"modules":[0,1]}' '[[10,"right","hold"],[40,"right","release"],[45,"jump"],[70,"right","hold"],[95,"right","release"],[100,"down","hold"],[110,"down","release"],[120,"up","hold"],[140,"fire"]]'
shot caves 90 '{"room":9,"x":40,"y":120,"suit":true,"modules":[0,1],"energy":22}' '[[10,"left","hold"],[50,"up","hold"],[80,"fire"]]'
shot ruins 90 '{"room":45,"x":20,"y":100,"suit":true,"modules":[0,1,3]}' '[[10,"right","hold"],[60,"fire"]]'
shot base 90 '{"room":0,"x":20,"y":120,"suit":true,"modules":[0,1,2,3]}' '[[10,"right","hold"],[40,"up","hold"],[70,"fire"]]'
python3 -I "$DIR/tools/site/images.py" "$OUT" "$DIR"
