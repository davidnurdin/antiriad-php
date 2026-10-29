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
  programs, types, items, messages, font), `music/*.ogg`, `sfx/*.ogg`. On the playground they are private files.
- **Units**: the original's. x in screen bytes (0-79, 4 view pixels), y in lines (50 = top of the
  playfield, 178 = under it). View 320 x 200: playfield 0-127, dashboard 128-199.
- **Scenes**: `TitleScene` (menu, title music, credits ticker) → `PlayScene` (the game; `GAME OVER` /
  `VICTOIRE` then fire → title) ; overlay `PauseScene` ; `OptionsScene` (pushed: sounds, music, language).
- **Model** (`src/Antiriad/`, created with `new`, excluded from the container): `World` (world.json),
  `Game` (rules, 12 ticks a second), `Hero` (Tal / the suit: walk, jump, fall table, walls), `Actor` (objects
  running the original's movement programs and animations), `Gfx`, `CpcFont` (the original font, also the
  engine's font), `Ticker` (message line), `Messages` (fr / en through config/lang.yaml, es = the disc's texts).
- **Rules**: see the doc block of `Game`. Modules: 0 boots (room 43), 1 pulse beam (33), 2 mine (20),
  3 negator (6). Suit in room 63. The core is in room 0.
- **Controls** (`config/input.yaml`): arrows, up / Z = jump (thrust in the suit), Space = throw / fire,
  down = crouch / pick up / enter the suit, E = in / out of the suit, Escape = pause.
- **Test params** of `PlayScene`: `room`, `x`, `y`, `suit`, `energy`, `modules` (list), `lives`, `demo`.
- **Smoke test**: `PlayScene` with `demo: true` (a bot walks, jumps and throws; nothing hurts it).
