# antiriad-php — notes for agents

This game is built with JumPHP. **Read `../../AGENTS.md` first** (rules, commands, API).

## Checklist

```bash
games/antiriad-php/tools/extract/extract.sh <disk.dsk> <dsk2jumphp> <cpc6128.rom> <amsdos.rom>   # assets/ (not in git)
bin/jumphp test antiriad-php                 # lint + phpstan + headless smoke test (must pass)
bin/jumphp shot antiriad-php 90              # title screen -> games/antiriad-php/var/screenshots/shot.png
JUMPHP_SCENE='App\Scene\PlayScene' JUMPHP_SCENE_PARAMS='{"room":63,"x":12,"y":130,"modules":[0,1]}' bin/jumphp shot antiriad-php 150
games/antiriad-php/tools/site/shots.sh       # site pictures + assets/thumbnail.png
bin/jumphp build:site antiriad-php && bin/jumphp siteshot antiriad-php
bin/jumphp build:remote antiriad-php && JUMPHP_WEB=remote bin/jumphp webshot antiriad-php 8 '[[1,"Enter"]]'
```

## This game

- **What it is**: a remake of *The Sacred Armour of Antiriad* (Palace Software, 1986), Amstrad CPC, from its
  disc with [dsk2jumphp](https://github.com/davidnurdin/dsk2jumphp). 69 flip screens, the original rooms,
  sprites, palette, dashboard, font, texts (Spanish edition), title music and sounds. Every address is in
  `tools/extract/README.md`.
- **Assets** (`assets/`, ignored by git: the original's data) are rebuilt by `tools/extract/extract.sh`:
  `rooms/room-NN.png` (320 x 128 with the neighbours' edge columns), `sprites/sprites-PP.png` (one sheet per
  room palette), `hud/hud.png`, `data/world.json` (exits, ground and wall masks, objects, animations,
  programs, types, items, messages, font), `title/title.png`, `hud/hud-lit.png`, `sprites/sprites-PP-lit.png`, `music/*.ogg`, `sfx/*.ogg`. On the playground they are private files.
- **Units**: the original's. x in screen bytes (0-79, 4 view pixels), y in lines (50 = top of the
  playfield, 178 = under it). View 320 x 200: playfield 0-127, dashboard 128-199.
- **Scenes**: `TitleScene` (the original's title screen, `assets/title/title.png`, credits rewritten translated except in Spanish; fire starts, Escape = options) → `PlayScene` (the game; `GAME OVER` /
  `VICTOIRE` then fire → title) ; overlay `PauseScene` ; `OptionsScene` (pushed: sounds, music, language).
- **Model** (`src/Antiriad/`, created with `new`, excluded from the container): `World` (world.json),
  `Game` (rules, 15 ticks a second: the original's walking pace), `Hero` (Tal / the suit: walk, jump, fall table, walls), `Actor` (objects
  running the original's movement programs and animations), `Gfx`, `CpcFont` (the original font, also the
  engine's font), `Ticker` (message line), `Messages` (fr / en through config/lang.yaml, es = the disc's texts).
- **Rules**: see the doc block of `Game`. Modules: 0 boots (room 43), 1 pulse beam (33), 2 mine (20),
  3 negator (6). Suit in room 63. The core is in room 0.
- **Tal on foot** is the original's automaton (`Hero::NEXT` = #8F4B): standing sideways, turning round
  through the pose facing the player (#01), walking, crouching (= picking up), throwing (the stone flies
  an arc: programs 1 / 2, silent), jumping (#6659: up standing: straight up; fire walking, or fire + the way he
  faces standing: forward; up walking only stops him), landing. The ceiling stops
  a jump only at the top of the screen (#6714). Standing facing the player on the suit gets him in: he dissolves in front of it
  (#5D9E / #6E30: the masks of #8E22), then the suit lights up.
- **Masks** (#6E30, `sprites/dissolve-PP.png`: the sheet repeated for masks 1-11): every object of a
  room materialises (`Actor::appear`), a killed enemy fades away (`vanish`); the shot bursts (#2D).
- **Teleporters** (#29, a room's first object, #7192 / #6235): the objects after it wait 40 ticks, it fades
  away, then they come (materialising); shot down before, it brings none. It hums (#0D, a loop) while it is there.
- **Hits** (#5937): two objects touch when closer than the left one's width and the upper one's height.
  The stone is tested where it leaves the hand (x + 6, y + 6) before it moves, with the size the shot's
  slot kept from its last sprite for its first 4 ticks (none at first, a stone's, a burst's 6 x 18): after a
  burst, a monster coming within 12 bytes is hit at once (the original's close kills).
- **Homing** (#6CDE, programs 31-33): monsters aim at Tal's x - 3, y + 12, 4 lines of slack (they sit at
  his middle, under a standing throw).
- **Kept bugs**: throwing again and again with the way held against a wall moves Tal a byte each
  throw, no wall test (`Hero::control`): he goes through walls, as in the original.
- **Death** (#5816): 32 ticks (107 frames), a jump or fall goes on, the room keeps moving; then #15 (he gets
  up) with the regeneration tune (#10-#12, `sfx/regen.ogg`); the death itself only has the bite's sound.
- **The suit**: #17 (just in / come down facing the player: left / right steps out), #16, #18-#1B; with
  the boots it hangs in the air, up / down move it. The first time in, the dashboard starts (#8E12
  flicker, then score, dial, bars a unit a tick); it is lit (`hud-lit.png`, `sprites-PP-lit.png`: pens
  13-15 of the suit) while Tal is inside.
- **Dashboard** (#5DFC / #6FBE): bars of v + 1 pixels, 4 lines, red then cyan past 16; the signs ('z', the
  dial's 'x'...'r') go through #8B3D like the texts; the scanner is noise; messages in x 120-208.
- **Phones**: a floating stick (`touch: pad: stick`), A = fire, B = suit.
- **Controls** (`config/input.yaml`): arrows, up / Z = jump (thrust in the suit), Space = throw / fire,
  down = crouch / pick up, E = in / out of the suit (also), Escape = pause.
- **Test params** of `PlayScene`: `room`, `x`, `y`, `suit`, `energy`, `modules` (list), `lives`, `demo`.
- **Sprites** face left in the sheets; bit 7 mirrors them (facing right). #73F0 mirrors sprite bytes in place (state in #93B2): export.py turns them back.
- **Sounds** where the original plays them (`tools/extract/extract.sh`): steps on walk frames 0 and 4
  and landings, enemy hit / killed, suit in / out, the suit's tune (5 s, cut from a real game), a teleporter's room (its hum loops while Tal is there), a saucer's whoosh
  (when an animation reaches sprite #6E). Each is played through #752A from room 61 with the game
  started as a player would (else envelopes are lost) and rendered from its own channels only.
- **Smoke test**: `PlayScene` with `demo: true` (a bot walks, jumps and throws; nothing hurts it).
