<?php

namespace App\Antiriad;

use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\Sdl;

/**
 * The original's pictures: rooms (320 x 128, the neighbours' edge columns included), sprite sheets (one per
 * room palette: some sprites take the room's pens) and the dashboard. Loaded when first needed.
 */
final class Gfx
{
    public function __construct(private Sdl $sdl, private World $world)
    {
    }

    private function texture(string $name, string $path)
    {
        if (!$this->sdl->hasTexture($name)) {
            $this->sdl->loadTexture($name, $path);
        }
        return $this->sdl->getTexture($name)['texture'];
    }

    public function room(Renderer $r, int $room, float $y = 0): void
    {
        $r->copy($this->texture(sprintf('room-%02d', $room), sprintf('assets/rooms/room-%02d.png', $room)), null, 0, $y);
    }

    /** The dashboard, dark, or lit by the suit (pens 9-11: #61E9 / #61F8). */
    public function hud(Renderer $r, float $y = 128, bool $lit = false): void
    {
        $name = $lit ? 'hud-lit' : 'hud';
        $r->copy($this->texture($name, "assets/hud/$name.png"), null, 0, $y);
    }

    /**
     * Sprite $id at view pixels ($x, $y). Bit 7 of the id = mirrored (#73F0): the sheet faces left.
     * $clipBottom: lines under it are not drawn (the dashboard). $lit: pens 13-15 lit (the suit with Tal, #61BB).
     */
    public function sprite(Renderer $r, int $palette, int $id, float $x, float $y, float $clipBottom = 128, bool $lit = false): void
    {
        $s = $this->world->sprites[$id & 0x7F];
        $h = min($s['h'], (int)floor($clipBottom - $y));
        if ($h <= 0 || $s['w'] === 0) {
            return;
        }
        $name = sprintf('sprites-%02d', $palette) . ($lit ? '-lit' : '');
        $tex = $this->texture($name, "assets/sprites/$name.png");
        $r->copy($tex, [$s['x'], $s['y'], $s['w'], $h], (int)round($x), (int)round($y), $s['w'], $h, ($id & 0x80) !== 0);
    }

    /** Sprite $id through mask $step of #6E30 (12 = whole, 0 = nothing): appearing or going (#5D9E). */
    public function masked(Renderer $r, int $palette, int $id, int $step, float $x, float $y, float $clipBottom = 128): void
    {
        if ($step >= 12) {
            $this->sprite($r, $palette, $id, $x, $y, $clipBottom);
            return;
        }
        $s = $this->world->sprites[$id & 0x7F];
        $h = min($s['h'], (int)floor($clipBottom - $y));
        if ($step <= 0 || $h <= 0 || $s['w'] === 0) {
            return;
        }
        $name = sprintf('dissolve-%02d', $palette);
        $tex = $this->texture($name, "assets/sprites/$name.png");
        $top = ($step - 1) * $this->world->sheetH + $s['y'];
        $r->copy($tex, [$s['x'], $top, $s['w'], $h], (int)round($x), (int)round($y), $s['w'], $h, ($id & 0x80) !== 0);
    }

    /** Size in view pixels of sprite $id. */
    public function size(int $id): array
    {
        $s = $this->world->sprites[$id & 0x7F];
        return [$s['w'], $s['h']];
    }
}
