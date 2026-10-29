<?php

namespace App\Antiriad;

/**
 * The original game's data, as tools/extract/export.py took it from the disc (assets/data/world.json):
 * rooms (exits, palette, ground, objects), animations, movement programs, object types, items, texts, font.
 *
 * Units are the original's: x in screen bytes (0-79, 4 virtual pixels each), y in the game's lines
 * (50 = the top of the playfield, 177 = its bottom line).
 */
final class World
{
    public const TOP = 50;              // y of the playfield's first line
    public const BOTTOM = 178;          // y just under its last line
    public const ROOMS = 69;
    public const PIXELS_PER_BYTE = 4;

    private static ?self $instance = null;

    /** @var array<int, array{exits: array<string, ?int>, palette: int, ground: list<string>, walls: list<string>, ceiling: list<string>, top: string, objects: list<list<int>>}> */
    public array $rooms;
    /** @var list<array{x: int, y: int, w: int, h: int, colour: int}> */
    public array $sprites;
    /** @var list<array{ticks: int, frames: list<int>, end: string, next?: int}> */
    public array $anims;
    /** @var list<list<int>> 4 bytes per object type */
    public array $types;
    /** @var list<array{steps: list<list<int>>, end: string}> */
    public array $programs;
    /** @var list<int> lines per tick while falling */
    public array $fall;
    /** @var list<list<int>> (room, x, y) of the 4 suit modules */
    public array $modules;
    /** @var list<list<int>> (room, x, y) of the recharge cells */
    public array $cells;
    /** @var list<int> (room, x, y) of the suit at the start */
    public array $armour;
    /** @var list<list<int>> (room, x, y) start places */
    public array $start;
    /** @var list<string> the original's messages (Spanish edition) */
    public array $messages;
    /** @var list<list<int>> 14 palettes: 16 pens -> hardware colour */
    public array $palettes;
    /** @var list<list<int>> hardware colour -> RGB */
    public array $hw;
    /** @var array{glyphs: list<list<string>>, chars: array<string, int>} */
    public array $font;

    /** height of a sprite sheet: the masked sheets (dissolve-PP.png) repeat it for masks 1-11 */
    public int $sheetH = 0;

    public static function load(string $path): self
    {
        if (self::$instance === null) {
            $data = json_decode((string)file_get_contents($path), true);
            if (!is_array($data)) {
                throw new \RuntimeException("$path: not found or not JSON (run tools/extract/extract.sh)");
            }
            $w = new self();
            foreach (['rooms', 'sprites', 'anims', 'types', 'programs', 'fall', 'modules', 'cells', 'armour', 'start',
                         'messages', 'palettes', 'hw', 'font'] as $key) {
                $w->$key = $data[$key];
            }
            $w->sheetH = (int)($data['sheet_h'] ?? 0);
            self::$instance = $w;
        }
        return self::$instance;
    }

    public function exit(int $room, string $side): ?int
    {
        return $this->rooms[$room]['exits'][$side] ?? null;
    }

    /** Pen 1 or 5 on a line of the playfield's first 8 (x0..x1 in mode 0 pixels): the ceiling test of #6714. */
    public function ceilingAt(int $room, int $line, int $x0, int $x1): bool
    {
        $screen = $line - self::TOP;
        if ($screen < 0 || $screen >= 8) {
            return false;
        }
        $row = $this->rooms[$room]['ceiling'][$screen];
        for ($x = max(0, $x0); $x <= min(159, $x1); $x++) {
            if ($row[$x] === '1') {
                return true;
            }
        }
        return false;
    }

    /** Pen 3 or 7 on the line under (x0..x1 in mode 0 pixels, 0-159): the ground test of #68B4. */
    public function groundAt(int $room, int $line, int $x0, int $x1): bool
    {
        $screen = $line - self::TOP;
        if ($screen < 0 || $screen >= 128 || ($screen & 7) !== 0) {
            return false;
        }
        $row = $this->rooms[$room]['ground'][$screen >> 3];
        for ($x = max(0, $x0); $x <= min(159, $x1); $x++) {
            if ($row[$x] === '1') {
                return true;
            }
        }
        return false;
    }

    /**
     * A pixel of pen 7 in screen byte $byte (0-79) between lines $from and $to: a wall (#67B6 scans the
     * column Tal walks into). The rooms' walls are hex strings of 80 bits per line.
     */
    /** #6848: a pixel that is not black in the playfield's first 4 lines of screen byte $byte. */
    public function topAt(int $room, int $byte): bool
    {
        if ($byte < 0 || $byte > 79) {
            return false;
        }
        return (hexdec($this->rooms[$room]['top'][$byte >> 2]) & (8 >> ($byte & 3))) !== 0;
    }

    public function wallAt(int $room, int $byte, int $from, int $to): bool
    {
        if ($byte < 0 || $byte > 79) {
            return false;
        }
        $rows = $this->rooms[$room]['walls'];
        $digit = $byte >> 2;
        $mask = 8 >> ($byte & 3);
        for ($y = max(0, $from - self::TOP), $last = min(127, $to - self::TOP); $y <= $last; $y++) {
            if (hexdec($rows[$y][$digit]) & $mask) {
                return true;
            }
        }
        return false;
    }

    /** RGB of a pen in a room palette. */
    public function rgb(int $palette, int $pen): array
    {
        return $this->hw[$this->palettes[$palette][$pen]];
    }

    /** The type's 4 bytes (byte 0: bit 7 object, bit 6 enemy, bit 5 + low bits a handler; byte 1 bits 7-5 hits). */
    public function type(int $type): array
    {
        return $this->types[$type] ?? [0, 0, 0, 0];
    }
}
