# Extraction: from the CPC disc to `assets/`

```bash
games/antiriad-php/tools/extract/extract.sh <your disk.dsk> <dsk2jumphp checkout> <cpc6128.rom> <amsdos.rom>
games/antiriad-php/tools/site/shots.sh      # then the site's pictures and assets/thumbnail.png
```

The disc used: *Sacred Armour of Antiriad, The (1986)(Palace Software)(es)[t]* — the Spanish edition, with a
trainer in its BASIC loader (`Invulnerable (S/N)`). Its files: `ANTIRIAD.BAS` (loader), `ANTI1.CM`
(#8014), `ANTI2.CM` (#43E1), `ANTI3.CM` (#0200, exec #0200). It needs the CPC 6128 firmware (ROMs of an
emulator installed on your machine). The .dsk, the ROMs, the dumps and everything `extract.sh` writes in
`assets/` are the original game's: **never commit them** (`.gitignore`).

How it runs: [dsk2jumphp](https://github.com/davidnurdin/dsk2jumphp)'s emulator boots from BASIC
(`RUN"ANTIRIAD`, `S`), fire on the title screen at frame 800, a dump in game at frame 1290; `export.py` reads
the tables of that dump; the music and sounds come from the AY log (`psg.py render`, mono Ogg).

## What the game does, and where (all found by watching it run, then reading the code around)

| Address | What | How it was found / used |
|---|---|---|
| `#7A4C` | joystick row 9 (complemented), read by `#7900` | `key row 9 pc=7944`; `#7A4D` is meant to be row 8, but the code ORs the previous row in and reads row 9 again: `#5571 AND #08` then sends "right" to the title screen. `extract.sh` pokes `#5572=00`. |
| `#54FA` | main loop (`#542C` = title screen, `#5851` = new game) | disassembly |
| `#B4ED` | current room (0-68); start `#38` (56) or `#44` (68) at random (`LD A,R`, `#58DF`) | dumps diffed over 3 rooms |
| `#B400` | Tal's object; 8 objects of 19 bytes from `#B400`: +0 program, +1 step, +2 timer, +3 x (screen bytes), +4 y (lines, 50 = top of the playfield), +7 directions, +8 dx, +9 dy, +10 animation (bit 7 = held), +11 frame, +13 sprite, +15/+16 size, +18 flags | `-w b403-b404` while walking / falling |
| `#9632` | exits, 4 bytes per room: right, left, down, up (`#FF` = none) | `#6A42` |
| `#7051` | draws a room: `#4840 + room*32` = 8x4 blocks; block = 16 sub-tiles at `#3930`; sub-tile = 8 bytes at `#2358`, each byte expanded to 4 mode 0 pixels by the tables `#0200` / `#0300`, ORed with a colour mask (`#50E0`: 2 bits per sub-tile -> `#932E`). The left neighbour's last block column and the right one's first are drawn on the sides. | `#7088`, `#7335`, `#7399`, `#73B7` |
| `#9746` / `#978B` | per room: ranges of sub-tile numbers shifted by a 16-bit offset (the areas' tile sets) | `#7255`, `#7335` |
| `#518F` / `#51D7` | room -> palette (14 of them), 7 pens each (1-7); `#8DF3` = the 16 pens | `#7139` |
| `#68B4` | ground: on a line multiple of 8 (screen line), a pixel of pen 3 or 7 under the feet (6 pixels from x*2+3) | `#65D9` |
| `#67B6` | walls: a byte with a pixel of pen 7 in the column ahead (x+7 going right, x-1 going left), 35 lines from y+4 on foot, 38 in the suit; the suit with y < #33: anything drawn in the playfield's first 4 lines (#6848) | `#5755`, `#5795` |
| `#69A7` | room edges: x < #05 left (x = #44), x >= #45 right (x = 6), y < #19 up (y = #99), y >= #9B down (y = #1B) | emulator: room 63 -> 49 -> 48 -> 62 |
| `#8EC2` | fall table: 1,1,2,2,3,3,4,4,5,6,7,8,8,9,9,10,10 lines per tick; a jump goes up it backwards from 8 | `#65B8`, `-w b404` during a jump (8,7,6,5,4,4,3...) |
| `#9432` | sprites: 4 bytes each (address, width in source bytes, height); colour mask `#9332`; bit 7 of a sprite number = mirrored (`#73F0`). A screen byte whose source is 0 keeps the background. | `#6D3C` |
| `#9021` | animations: [ticks, frames..., `#FC` loop / `#FD` hold / `#FE n` next / `#FF` kill] | `#6B45` |
| `#97F8` | movement programs: steps [directions, ticks, dx, dy], `#0F` = towards Tal (`#6CDE`: his x - 3, y + 12, 4 lines of slack) | `#6BC8` |
| `#5937` | contacts of slots 0 (Tal) and 1 (the shot) with slots 2-7: closer than the left one's width (`+15` x 2 bytes) and the upper one's height (`+16`); sizes are those of the last sprite drawn in the slot; results in `#B502` / `#B508` | `#5CD7`, `#5D06` |
| `#922A` | object types, 4 bytes (type 1 first): byte 0 bit 6 = enemy, bit 5 + bits 4-0 = handler (`#9216`: 0 cell, 1-4 modules, 5 nothing, 6 suit, 7 teleporter, 8 energy beam, 9 the core); byte 1 bits 7-5 = hits, bits 4-0 = bite; byte 2 = score | `#59C8`, `#5B97` |
| `#9BC7` | objects of each room: [count, (x, y, program, type)...] | `#7160` |
| `#8F14` | the 4 modules: (room, x, y) of the boots, pulse beam, implosion mine, particle negator; flags `#B519-#B51C` | `#71CD`, handlers `#5A2D`... |
| `#A066` | 15 recharge cells (room, x, y), bit 7 of the room = taken | `#7202` |
| `#B4F4` | where the empty suit stands: room 63, y #40, x from `#8F13` | `#723A` |
| `#B4FB` / `#B4FD` | Tal's life / the suit's energy (31 each); `#B4F1` = 0 in the suit | `#5B9B`, `#6F14` (every 100 frames: heals in the suit, the base drains it, caves zones `#8B37` irradiate Tal) |
| `#7113` | rooms 0-1 without the negator: life = 0, message 18 | |
| `#5EA0` | messages (`#A093`, '$' ends) scrolled in the dashboard; font `#0400` (mode 1, 16 bytes a glyph, codes: A-Z = 1-26, digits = `#22`.., signs `#8B3D` -> `#8B50`) | `#6FBE`, `#701E` |
| `#752A` | sound player: A = channel, L = sound. `extract.sh` hooks a stub at `#A600` on the main loop's `CALL #69A7` to call it once. 1-3 title music, 4 suit, 7 message, `#0B` shot, `#0C` pickup, `#10-#12` regeneration (Tal gets up), `#13` hurt, `#15` step, `#16` beam, `#17-#19` victory, `#1E` enemy hit | xref of `CALL #752A` |
| `#7A5D` | the title tune's order pointer: back to its start every 5780 frames (115.6 s), from frame 665 | `-w 7a5b-7a88` |
