#!/usr/bin/env bash
# Rebuilds games/antiriad-php/assets/ from YOUR copy of the original disc.
#
#   tools/extract/extract.sh <disk.dsk> <dsk2jumphp checkout> <cpc6128.rom> <amsdos.rom>
#
# dsk2jumphp: https://github.com/davidnurdin/dsk2jumphp (`make` it once). The ROMs are the ones of a CPC
# emulator installed on your machine (32 KB OS + BASIC, and AMSDOS): the game is loaded from BASIC.
# Nothing produced here may be committed (.gitignore): it is the original game's data.
# See README.md for every address used.
set -euo pipefail
DSK=$(readlink -f "$1"); D2J=$(readlink -f "$2"); ROM=$(readlink -f "$3"); AMSDOS=$(readlink -f "$4")
HERE=$(cd "$(dirname "$0")" && pwd)
GAME=$(cd "$HERE/../.." && pwd)
ASSETS="$GAME/assets"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
CPC="$D2J/emulator/cpc"; PSG="$D2J/tools/psg.py"
[ -x "$CPC" ] || make -C "$D2J" >/dev/null

# boot from BASIC: RUN"ANTIRIAD, answer S to the loader's "Invulnerable (S/N)" (Tal never dies while sounds are
# captured), fire on the title screen (frame 800).
# The quit test of the main loop (#5571 AND #08 on a copy of the joystick row) is turned off: in the
# emulator it sees "right" as the quit key.
run() {  # run <dir> <frames> [options...]
  local dir="$WORK/$1" frames=$2; shift 2
  mkdir -p "$dir"
  (cd "$WORK" && "$CPC" "$DSK" "$dir" "$frames" -rom "$ROM" -urom "7:$AMSDOS" \
     -type '100:RUN"ANTIRIAD\n' -type "300:${TRAINER:-S}\n" "$@" >/dev/null 2>&1)
}
GAMEPLAY=(-k '800:joy_fire+,820:joy_fire-' -F 900:5572=00)

echo "graphics, rooms, tables, texts..."
run game 1300 "${GAMEPLAY[@]}" -k '1000:joy_right+,1290:joy_right-' -d 1290
mkdir -p "$ASSETS"
python3 -I "$HERE/export.py" "$D2J/tools" "$WORK/game/dump-01290.bin" "$ASSETS"

ogg() {  # ogg <psg.bin> <from> <to> <out.ogg> [trim]: AY log -> mono Ogg (trim: the silent end cut off)
  # rendered after a full-volume reference (psgcal.py, cut off again): the sounds keep their CPC levels
  python3 -I "$HERE/psgcal.py" "$1" "$2" "$3" "$WORK/cal.bin"
  python3 -I "$PSG" render "$WORK/cal.bin" 0 $(( $3 - $2 + 2 )) "$WORK/tmp.wav" >/dev/null
  local af=atrim=start=0.04,asetpts=PTS-STARTPTS,apad=pad_dur=0.15   # padded: Vorbis drops a lone 80 ms
  [ "${5:-}" = loop ] && af=atrim=start=0.04,asetpts=PTS-STARTPTS   # exact length: played in a loop
  [ "${5:-}" = lead ] && af="atrim=start=0.04,asetpts=PTS-STARTPTS,silenceremove=start_periods=1:start_threshold=-70dB,apad=pad_dur=0.15"
  [ "${5:-}" = trim ] && af="atrim=start=0.04,asetpts=PTS-STARTPTS,areverse,silenceremove=start_periods=1:start_threshold=-70dB,areverse,apad=pad_dur=0.15"
  ffmpeg -loglevel error -y -i "$WORK/tmp.wav" -af "$af" -ac 1 -c:a libvorbis -q:a 2 "$4"
}

echo "title music..."
mkdir -p "$ASSETS/music" "$ASSETS/sfx"
run title 6500 -s 800
# the title screen as it stands once drawn (credits over the dashboard): shown as is by TitleScene
mkdir -p "$ASSETS/title"
python3 -I -c 'import sys; from PIL import Image; Image.open(sys.argv[1]).convert("RGB").save(sys.argv[2])' \
  "$WORK/title/screen-00800.ppm" "$ASSETS/title/title.png"
# the tune starts at frame 665 and its order pointer (#7A5D) is back to its first entry at frame 6445
ogg "$WORK/title/psg.bin" 665 6445 "$ASSETS/music/title.ogg"

# a sound through the game's own player #752A (A = channel, L = sound): a stub at #A600 hooked on the main
# loop (#54FA CALL #69A7) calls it once at frame 1101, then the sound is cut out of the AY log.
sound() {  # sound <name> <frames> "ch:id ..."
  local name=$1 frames=$2 stub=(3a 30 a6 b7 20 00 3c 32 30 a6) args=() p i
  for p in $3; do stub+=(3e "${p%%:*}" 2e "${p##*:}" cd 2a 75); done
  stub+=(c3 a7 69)
  stub[5]=$(printf '%02x' $(( ${#stub[@]} - 9 )))
  for i in "${!stub[@]}"; do args+=(-F "1100:$(printf '%04x' $((0xa600 + i)))=${stub[$i]}"); done
  run "snd-$name" $((1101 + frames + 10)) "${GAMEPLAY[@]}" "${args[@]}" -F 1100:a630=00 -F 1101:54fb=00 -F 1101:54fc=a6
  local out="$ASSETS/sfx/$name.ogg"
  case $name in victory|death) out="$ASSETS/music/$name.ogg";; esac
  ogg "$WORK/snd-$name/psg.bin" 1101 $((1101 + frames)) "$out" "${4:-}"
}
echo "sound effects..."
# Played alone like this, many sounds lose their envelope (a flat tone at volume 7): those are cut out of
# real games below instead (hurt, saucer, leave).
sound shoot 40 "2:0b"          # #6567: a pulse beam leaves; #567E: the turret fires (a stone is silent)
sound pickup 14 "0:0c"         # #5A07: an object is taken
sound hit 4 "0:1e"             # #5D44: an enemy is hit
sound step 3 "1:15"            # #555B: a footstep (walk frames 0 and 4) / #6642: landing
sound beam 26 "0:16"           # #5C06: a separating line is crossed
sound suit 100 "2:04" lead     # #6147: the suit comes to life (it starts 48 frames after the call)
sound message 25 "0:07"        # #5EF7: a message starts
sound enter 75 "1:0e 2:0f" trim     # #60C4: Tal gets into the suit (a hum swelling and dying away, 72 frames)
sound activate 120 "0:05 1:06" trim # #6085: the suit is brought to life (the boots)
sound death 82 "0:10 1:11 2:12"        # #5816: Tal dies
sound victory 1150 "0:17 1:18 2:19"    # #5C3E: the base is destroyed
# #61A6: out of the suit, cut from a real game: start in room 63 on the suit (#58E0 / #58EB, #B403-#B404),
# in at once (#6094), left at frame 1400 steps out: the hum then sounds on channel B alone (frames 1403-1475)
run leave 1480 -k '780:joy_fire+,820:joy_fire-,1400:joy_left+,1420:joy_left-' -F 700:5572=00 \
  -F 700:58e0=3f -F 700:58eb=3f -F 840:b403=17 -F 840:b404=40
ogg "$WORK/leave/psg.bin" 1403 1476 "$ASSETS/sfx/leave.ogg"
# #5BE1: an enemy touches Tal (a falling whistle on channel C, 48 frames): room 2 without the trainer
# ("N"), the enemies come at him
TRAINER=N run hurt 1180 -k '780:joy_fire+,820:joy_fire-' -F 700:5572=00 -F 700:58e0=02 -F 700:58eb=02
ogg "$WORK/hurt/psg.bin" 1126 1175 "$ASSETS/sfx/hurt.ogg"
# #5547: a saucer's whoosh (noise on channel B swelling and dying away, 85 frames): room 3
run saucer 1180 -k '780:joy_fire+,820:joy_fire-' -F 700:5572=00 -F 700:58e0=03 -F 700:58eb=03
ogg "$WORK/saucer/psg.bin" 1089 1173 "$ASSETS/sfx/saucer.ogg"
# #7184: a teleporter's room hums as long as Tal is in it (sound #0D on channel C, a 21-frame cycle):
# 4 cycles from room 61, played in a loop
run teleport 900 -k '780:joy_fire+,820:joy_fire-' -F 700:5572=00 -F 700:58e0=3d -F 700:58eb=3d
ogg "$WORK/teleport/psg.bin" 806 890 "$ASSETS/sfx/teleport.ogg" loop
echo "done: $ASSETS"
