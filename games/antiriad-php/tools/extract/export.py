"""Turns a memory dump of the original game (taken in game, see extract.sh) into the remake's assets.

    python3 -I export.py <dsk2jumphp>/tools dump.bin <game>/assets

Every address below is explained in README.md. Nothing here is guessed from pictures: the rooms, sprites,
font, tables and texts are read where the game's own code reads them.
"""
import json
import os
import sys

sys.path.insert(0, sys.argv[1])
from cpc import Dump, HW, mode0_pixels, mode1_pixels   # noqa: E402
from PIL import Image                                   # noqa: E402

dump = Dump(sys.argv[2])
m = dump.mem
OUT = sys.argv[3]

ROOMS = 69
EXITS = 0x9632          # 4 bytes per room: right, left, down, up (#FF = none)            #6A42
LAYOUT = 0x4840         # 32 bytes per room: 8 x 4 blocks                                 #7265
BLOCKS = 0x3930         # 16 bytes per block: 4 x 4 sub-tiles                             #709F
SUBGFX = 0x2358         # 8 bytes per sub-tile: one byte per line, expanded by #0200/#0300  #737D
COLBITS = 0x50E0        # 2 bits per sub-tile: which colour group                          #73B7
COLMASK = 0x932E        # the 4 colour groups: OR masks (pens +0, +4, +8, +12)
REMAP_OF_ROOM = 0x9746  # room -> remap table (ranges of sub-tiles shifted per area)       #7255
REMAP_TABLES = 0x978B
PAL_OF_ROOM = 0x518F    # room -> palette (pens 1-7)                                       #7139
PALETTES = 0x51D7       # 10 bytes per palette, 7 used
FIXED_PALETTE = 0x8DF3  # the 16 pens before a room changes 1-7
SPRITES = 0x9432        # 4 bytes per sprite: address, width (bytes), height              #6D63
SPRITE_COLOUR = 0x9332  # OR mask per sprite
ANIMS = 0x9021          # word per animation: [ticks per frame, frames..., command]        #6B45
TYPES = 0x922A          # 4 bytes per object type (type 1 first)                           #59E0
PROGRAMS = 0x97F8       # word per movement program: 4-byte steps [dirs, ticks, dx, dy]   #6BC8
ENEMIES = 0x9BC7        # word per room: [count, (x, y, program, type) * count]            #7160
MODULES = 0x8F14        # 4 x (room, x, y): boots, pulse beam, implosion mine, negator    #71CD
CELLS = 0xA066          # 15 x (room, x, y): recharge cells                                #7202
FALL = 0x8EC2           # lines per tick while falling (index grows each tick)             #65B8
FONT = 0x0400           # 16 bytes per glyph, mode 1, 8 x 8                                #701E
FONT_PUNCT = 0x8B3D     # 19 characters -> glyph codes at #8B50                            #6FFC
MESSAGES = 0xA093       # word per message, '$' ends, 13 = new line                        #5EEA


def w16(a):
    return m[a & 0xFFFF] | m[(a + 1) & 0xFFFF] << 8


def remap(table, t):
    """#7335: sub-tile t, shifted when it falls in one of the area's ranges (16-bit, wraps)."""
    for k in range(m[table]):
        lo, hi, off = m[table + 1 + 4 * k], m[table + 2 + 4 * k], w16(table + 3 + 4 * k)
        if lo <= t <= hi:
            return (t + off) & 0xFFFF
    return t


def subtile_colour(t):
    """#73B7: the OR mask of sub-tile t."""
    return m[COLMASK + ((m[(COLBITS + (t >> 2)) & 0xFFFF] >> (2 * (3 - (t & 3)))) & 3)]


def subtile_pens(t):
    c = subtile_colour(t)
    rows = []
    for y in range(8):
        e = m[(SUBGFX + t * 8 + y) & 0xFFFF]
        rows.append(list(mode0_pixels(m[0x200 + e] | c)) + list(mode0_pixels(m[0x300 + e] | c)))
    return rows


def palette_index(room):
    return m[PAL_OF_ROOM + room]


def palette(index):
    pal = list(m[FIXED_PALETTE:FIXED_PALETTE + 16])
    pal[1:8] = m[PALETTES + index * 10:PALETTES + index * 10 + 7]
    return [p & 31 for p in pal]


def block_column(room, bx, table):
    """4 x 16 sub-tiles of one block column of a room (with the remap table of the room drawing it)."""
    col = []
    for by in range(4):
        blk = m[LAYOUT + room * 32 + by * 8 + bx]
        for sy in range(4):
            col.append([remap(table, m[BLOCKS + blk * 16 + sy * 4 + sx]) for sx in range(4)])
    return col


def room_pens(room):
    """The 160 x 128 pens of the playfield, as #7051 draws it: the left neighbour's last block column,
    the room's 8 columns, the right neighbour's first one (each with its own remap table)."""
    right, left = m[EXITS + room * 4], m[EXITS + room * 4 + 1]
    cols = []
    for src, bx in [(left, 7)] + [(room, i) for i in range(8)] + [(right, 0)]:
        if src == 0xFF:
            cols.append(None)
        else:
            cols.append(block_column(src, bx, w16(REMAP_TABLES + m[REMAP_OF_ROOM + src] * 2)))
    pens = [[0] * 160 for _ in range(128)]
    for c, col in enumerate(cols):
        if col is None:
            continue
        for ty in range(16):
            for sx in range(4):
                for y, row in enumerate(subtile_pens(col[ty][sx])):
                    for x, pen in enumerate(row):
                        pens[ty * 8 + y][c * 16 + sx * 4 + x] = pen
    return pens


def ground_rows(pens):
    """#68B4: Tal stands on a line whose number is a multiple of 8 when a pixel under him has pen 3 or 7
    (the test masks pen bit 2). One string of 160 '0' / '1' per sub-tile row."""
    return [''.join('1' if (pens[ty * 8][x] & 0b1011) == 3 else '0' for x in range(160)) for ty in range(16)]


def ceiling_rows(pens):
    """#66CE / #6714: rising, Tal's head (y+7) is tested only above line #3A, the playfield's first 8 lines:
    a pixel of pen 1 or 5 there stops him (the test masks pen bit 2). One string of 160 per line 0-7."""
    return [''.join('1' if (pens[y][x] & 0b1011) == 1 else '0' for x in range(160)) for y in range(8)]


def wall_rows(pens):
    """#67B6: Tal stops at a screen byte with a pixel of pen 7 in the column he walks into. One hex string
    of 80 bits (the screen bytes, bit 7 of the first digit = byte 0) per line."""
    rows = []
    for y in range(128):
        bits = ''.join('1' if 7 in (pens[y][2 * b], pens[y][2 * b + 1]) else '0' for b in range(80))
        rows.append('%020x' % int(bits, 2))
    return rows


def save_pens(pens, pal, path, width=None):
    h = len(pens)
    w = len(pens[0])
    img = Image.new('RGB', (w * 2, h))
    px = img.load()
    for y in range(h):
        for x in range(w):
            rgb = HW[pal[pens[y][x]]]
            px[x * 2, y] = rgb
            px[x * 2 + 1, y] = rgb
    img.save(path)


MIRRORED = 0x93B2


def sprite_pens(n):
    """#6D3C: sprite n, one source byte = 4 pixels through #0200/#0300, ORed with its colour.
    A screen byte whose two pixels are 0 in the source keeps the background: None there.
    #73F0 mirrors a sprite's bytes in place when it is asked the other way round than last time and keeps
    that way in #93B2 + n (bit 7): such a sprite is turned back here, every sheet faces the bit-7-clear way."""
    a, cw, h = w16(SPRITES + n * 4), m[SPRITES + n * 4 + 2], m[SPRITES + n * 4 + 3]
    c = m[SPRITE_COLOUR + n]
    rows = []
    for y in range(h):
        row = []
        for x in range(cw):
            e = m[(a + y * cw + x) & 0xFFFF]
            for half in (0x200, 0x300):
                raw = m[half + e]
                if raw == 0:
                    row += [None, None]
                else:
                    row += list(mode0_pixels(raw | c))
        rows.append(row[::-1] if m[MIRRORED + n] & 0x80 else row)
    return rows


def anim(i):
    p = w16(ANIMS + 2 * i)
    frames, k = [], p + 1
    while True:
        b = m[k]
        k += 1
        if b == 0xFC:
            return {'ticks': m[p] + 1, 'frames': frames, 'end': 'loop'}
        if b == 0xFD:
            return {'ticks': m[p] + 1, 'frames': frames, 'end': 'hold'}
        if b == 0xFE:
            return {'ticks': m[p] + 1, 'frames': frames, 'end': 'next', 'next': m[k]}
        if b == 0xFF:
            return {'ticks': m[p] + 1, 'frames': frames, 'end': 'kill'}
        frames.append(b)


def program(i):
    p = w16(PROGRAMS + 2 * i)
    steps, k = [], p
    while len(steps) < 40:
        if m[k] >= 0xFC:
            return {'steps': steps, 'end': {0xFC: 'loop', 0xFD: 'hold', 0xFE: 'kill', 0xFF: 'hold'}[m[k]]}
        steps.append(list(m[k:k + 4]))
        k += 4
    return {'steps': steps, 'end': 'hold'}


def text(i):
    p, s = w16(MESSAGES + 2 * i), ''
    while m[p] != 0x24 and len(s) < 300:
        s += '\n' if m[p] == 13 else chr(m[p])
        p += 1
    return s


def main():
    for d in ('rooms', 'sprites', 'data', 'hud'):
        os.makedirs(f'{OUT}/{d}', exist_ok=True)

    world = {'rooms': [], 'palettes': [palette(i) for i in range(14)], 'hw': [list(c) for c in HW]}
    for r in range(ROOMS):
        pens = room_pens(r)
        pi = palette_index(r)
        save_pens(pens, palette(pi), f'{OUT}/rooms/room-{r:02d}.png')
        ex = list(m[EXITS + r * 4:EXITS + r * 4 + 4])
        p = w16(ENEMIES + 2 * r)
        world['rooms'].append({
            'exits': {k: (None if v == 0xFF else v) for k, v in zip(('right', 'left', 'down', 'up'), ex)},
            'palette': pi,
            'ground': ground_rows(pens),
            'walls': wall_rows(pens),
            'ceiling': ceiling_rows(pens),
            'objects': [list(m[p + 1 + 4 * k:p + 5 + 4 * k]) for k in range(m[p])],
        })

    # sprites: one sheet per palette (35 sprites take pens 0-7 of the room), rectangles in sprites.json
    sprites, x, y, rowh = [], 0, 0, 0
    all_pens = [sprite_pens(n) for n in range(128)]
    for n, pens in enumerate(all_pens):
        w, h = len(pens[0]) * 2 if pens else 0, len(pens)
        if x + w > 512:
            x, y, rowh = 0, y + rowh + 1, 0
        sprites.append({'x': x, 'y': y, 'w': w, 'h': h, 'colour': m[SPRITE_COLOUR + n]})
        x += w + 1
        rowh = max(rowh, h)
    sheet_h = y + rowh
    # pens 13-15 are the suit's: #61BB lights them (#8E06) when Tal is inside, #61D2 puts them out (#8E03)
    for pi, lit in [(pi, lit) for pi in range(14) for lit in (False, True)]:
        pal = list(palette(pi))
        if lit:
            pal[13:16] = list(m[0x8E06:0x8E09])
        img = Image.new('RGBA', (512, sheet_h), (0, 0, 0, 0))
        px = img.load()
        for n, pens in enumerate(all_pens):
            s = sprites[n]
            for yy, row in enumerate(pens):
                for xx, pen in enumerate(row):
                    if pen is not None:
                        rgb = HW[pal[pen]]
                        px[s['x'] + xx * 2, s['y'] + yy] = rgb + (255,)
                        px[s['x'] + xx * 2 + 1, s['y'] + yy] = rgb + (255,)
        img.save(f'{OUT}/sprites/sprites-{pi:02d}' + ('-lit' if lit else '') + '.png')
    world['sprites'] = sprites

    world['anims'] = [anim(i) for i in range(0x40)]
    world['types'] = [list(m[TYPES + 4 * (t - 1):TYPES + 4 * t]) if t else [0, 0, 0, 0] for t in range(0x40)]
    world['programs'] = [program(i) for i in range(46)]
    world['fall'] = list(m[FALL:FALL + 17])
    world['modules'] = [list(m[MODULES + 3 * i:MODULES + 3 * i + 3]) for i in range(4)]
    world['cells'] = [list(m[CELLS + 3 * i:CELLS + 3 * i + 3]) for i in range(15)]
    world['armour'] = [m[0xB4F4], m[0x8F13], 0x40]   # room, x, y (#58D9, #723A)
    world['start'] = [[0x38, 0x14, 0x57], [0x44, 0x24, 0x57]]   # #58DF / #7506: room 56 or 68
    world['messages'] = [text(i) for i in range(22)]

    # font: glyph codes 0-127, mode 1 (pen 1 = ink); characters -> codes as #6FBE does
    glyphs = []
    for code in range(128):        # 0-63: letters, digits, signs; 64-127: the dashboard's graphics (#6FBE)
        rows = []
        for yy in range(8):
            a = FONT + code * 16 + yy * 2
            rows.append(''.join('#' if p else '.' for p in mode1_pixels(m[a]) + mode1_pixels(m[a + 1])))
        glyphs.append(rows)
    chars = {chr(ord('A') + i): i + 1 for i in range(26)}
    chars.update({chr(ord('0') + i): 0x22 + i for i in range(10)})
    for i in range(19):
        chars[chr(m[FONT_PUNCT + i])] = m[FONT_PUNCT + 19 + i]
    world['font'] = {'glyphs': glyphs, 'chars': chars}

    with open(f'{OUT}/data/world.json', 'w') as f:
        json.dump(world, f, separators=(',', ':'))

    # the dashboard (mode 1, its own palette): the screen under the playfield
    hud = dump.screen().crop((0, 128, 320, 200)).convert('RGB')
    hud.save(f'{OUT}/hud/hud.png')
    # lit up by the suit (seen in the emulator): blue -> sky blue, bright blue -> bright cyan
    lit = {(0, 0, 128): (0, 128, 255), (0, 0, 255): (0, 255, 255)}
    on = hud.copy()
    px = on.load()
    for yy in range(on.height):
        for xx in range(on.width):
            px[xx, yy] = lit.get(px[xx, yy], px[xx, yy])
    on.save(f'{OUT}/hud/hud-lit.png')


main()
