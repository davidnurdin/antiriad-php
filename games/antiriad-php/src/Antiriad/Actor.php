<?php

namespace App\Antiriad;

/**
 * An object of a room: enemy, hazard, item, the empty suit, a shot, an explosion. It runs the original's
 * two little scripts, one tick at a time:
 *  - its movement program (#6BB0): steps [directions, ticks, dx, dy], directions bit 0 up, 1 down, 2 left,
 *    3 right, #0F = towards Tal (#6CDE); a step lasts ticks + 1; the end loops, holds the last step or stops;
 *  - its animation (#6B45): frames (sprite ids, bit 7 = mirrored), the end loops, holds, chains or kills.
 */
final class Actor
{
    public const ENEMY = 'enemy';      // type byte 0 bit 6: shot at, hits = byte 1 bits 7-5
    public const HAZARD = 'hazard';    // handler 5: hurts, cannot be shot
    public const ITEM = 'item';        // handlers 0-4: recharge cell, the 4 modules
    public const SUIT = 'suit';        // handler 6: the empty suit
    public const TELEPORT = 'teleport';// handler 7
    public const BEAM = 'beam';        // handler 8: energy barrier
    public const CORE = 'core';        // handler 9: the base's heart
    public const DECOR = 'decor';
    public const SHOT = 'shot';
    public const FX = 'fx';

    public string $kind = self::DECOR;
    public int $handler = -1;
    public int $hits = 0;
    /** what it bites off Tal's life when it touches him (type byte 1, bits 4-0: #5B9E); shots: hits taken */
    public int $damage = 0;
    /** score when shot down (type byte 2) */
    public int $points = 0;
    /** type byte 0 bit 7: what cannot be hurt (#5D1A) bites in silence and stays (#5BC4: the hanging beast) */
    public bool $armoured = false;
    public bool $dead = false;
    /** index in the world's item list (cells / modules) when it is one */
    public int $itemIndex = -1;
    public int $flash = 0;
    /** shots: ticks left, owner damage */
    public int $life = 0;
    /** shots: ticks lived; a stone spends its first tick where it was thrown (#6905: tested there, then moved) */
    public int $age = 0;
    /**
     * A stone's size for the hits (bytes, lines): for its first 4 ticks, the size the shot's slot had
     * (#B413 + 15 / 16, set when a sprite is drawn there: none at first, a stone's, or a burst's 6 x 18).
     */
    public ?array $hitSize = null;

    private ?array $program = null;
    private int $step = 0;
    private int $timer = 0;
    private int $dirs = 0;
    private int $dx = 0;
    private int $dy = 0;

    private array $anim;
    private int $animId;
    private int $frameIndex = 0;
    private int $frameTimer = 0;
    public int $frame = 0;
    /** #B412: 0 = there; 1 = appearing, -1 = going (then dead), through mask $mask (#B411, 0-12) */
    public int $fade = 0;
    public int $mask = 12;

    public function __construct(private World $world, public int $type, public int $x, public int $y, int $program = 0)
    {
        $t = $world->type($type);
        $this->damage = $t[1] & 0x1F;
        $this->points = $t[2];
        $this->armoured = ($t[0] & 0x80) !== 0;
        if ($t[0] & 0x40) {
            $this->kind = self::ENEMY;
            $this->hits = max(1, $t[1] >> 5);
        } elseif ($t[0] & 0x20) {
            $this->handler = $t[0] & 0x1F;
            $this->kind = match ($this->handler) {
                0, 1, 2, 3, 4 => self::ITEM,
                6 => self::SUIT,
                7 => self::TELEPORT,
                8 => self::BEAM,
                9 => self::CORE,
                default => self::HAZARD,
            };
        }
        if ($program > 0) {
            $this->program = $world->programs[$program] ?? null;
        }
        $this->setAnim($type);
    }

    public function setAnim(int $id): void
    {
        $this->animId = $id;
        $this->anim = $this->world->anims[$id];
        $this->frameIndex = 0;
        $this->frameTimer = $this->anim['ticks'];
        $this->frame = $this->anim['frames'][0] ?? 0;
    }

    /** Moves in a straight line (shots), instead of a program. */
    public function setMotion(int $dirs, int $dx, int $dy): void
    {
        $this->program = null;
        $this->dirs = $dirs;
        $this->dx = $dx;
        $this->dy = $dy;
    }

    public function width(): int
    {
        return intdiv($this->world->sprites[$this->frame & 0x7F]['w'], World::PIXELS_PER_BYTE);
    }

    public function height(): int
    {
        return $this->world->sprites[$this->frame & 0x7F]['h'];
    }

    /** [x, y, w, h] in view pixels. */
    public function box(): array
    {
        $s = $this->world->sprites[$this->frame & 0x7F];
        return [$this->x * World::PIXELS_PER_BYTE, $this->y - World::TOP, $s['w'], $s['h']];
    }

    /** #6A50: it materialises (mask 0 up to 12). */
    public function appear(): void
    {
        $this->fade = 1;
        $this->mask = 0;
    }

    /** #5D9E: it fades away (mask 12 down to 0), then is gone. */
    public function vanish(): void
    {
        if ($this->fade !== -1) {
            $this->fade = -1;
            $this->mask = 12;
        }
    }

    /** Appearing or going: it neither touches nor can be touched (#5AE6, #5B1D, #5D06 skip #B412). */
    public function fading(): bool
    {
        return $this->fade !== 0;
    }

    public function tick(int $heroX, int $heroY): void
    {
        if ($this->fade > 0) {                    // #5704: a mask a turn; it moves once there (#6BB7)
            if (++$this->mask >= 12) {
                $this->fade = 0;
            }
            return;
        }
        if ($this->fade < 0) {
            if (--$this->mask <= 0) {
                $this->dead = true;
            }
            return;
        }
        if ($this->flash > 0) {
            $this->flash--;
        }
        if ($this->kind === self::SHOT && $this->hitSize !== null && $this->age++ === 0) {
            return;
        }
        $this->runProgram();
        $this->move($heroX, $heroY);
        $this->runAnim();
        if ($this->kind === self::SHOT && --$this->life <= 0) {
            $this->dead = true;
        }
    }

    private function runProgram(): void
    {
        if ($this->program === null) {
            return;
        }
        if ($this->timer > 0) {
            $this->timer--;
            return;
        }
        $steps = $this->program['steps'];
        if ($this->step >= count($steps)) {
            if ($this->program['end'] === 'loop' && $steps) {
                $this->step = 0;
            } else {
                if ($this->program['end'] === 'kill') {
                    $this->dirs = 0;
                }
                return;                         // 'hold': the last step goes on
            }
        }
        [$this->dirs, $this->timer, $this->dx, $this->dy] = $steps[$this->step++];
    }

    private function move(int $heroX, int $heroY): void
    {
        $dirs = $this->dirs;
        if ($dirs === 0x0F) {                   // #6CDE: towards Tal's middle (x - 3, y + 12), 4 lines of slack
            $tx = $heroX - 3;
            $ty = $heroY + 12;
            $dirs = ($tx > $this->x ? 8 : ($tx < $this->x ? 4 : 0))
                | (abs($ty - $this->y) < 4 ? 0 : ($ty > $this->y ? 2 : 1));
        }
        $w = $this->width();
        $h = $this->height();
        if ($dirs & 1) {
            $this->y -= $this->dy;
        }
        if ($dirs & 2) {
            $this->y += $this->dy;
        }
        if ($dirs & 4) {
            $this->x -= $this->dx;
        }
        if ($dirs & 8) {
            $this->x += $this->dx;
        }
        if ($this->kind === self::SHOT) {
            if ($this->x < 0 || $this->x + $w > 80 || $this->y >= World::BOTTOM) {
                $this->dead = true;
            }
            return;
        }
        // #6C43...: the screen's bytes and the 0-255 lines bound them; what falls off the playfield is gone
        $this->x = max(0, min(80 - $w, $this->x));
        $this->y = max(0, min(255 - $h, $this->y));
    }

    /** [w, h] the hits use (#5937): a stone's slot size for its first ticks, else its sprite's (bytes, lines). */
    public function hitSize(): array
    {
        return $this->hitSize !== null && $this->age <= 4 ? $this->hitSize : [$this->width(), $this->height()];
    }

    /** On the playfield (objects above or under it are out of play). */
    public function visible(): bool
    {
        return $this->y + $this->height() > World::TOP && $this->y < World::BOTTOM;
    }

    private function runAnim(): void
    {
        if (--$this->frameTimer > 0) {
            return;
        }
        $this->frameTimer = $this->anim['ticks'];
        $frames = $this->anim['frames'];
        $this->frameIndex++;
        if ($this->frameIndex >= count($frames)) {
            switch ($this->anim['end']) {
                case 'loop':
                    $this->frameIndex = 0;
                    break;
                case 'next':
                    $this->setAnim($this->anim['next']);
                    return;
                case 'kill':
                    $this->dead = true;
                    $this->frameIndex = count($frames) - 1;
                    break;
                default:
                    $this->frameIndex = count($frames) - 1;
            }
        }
        $this->frame = $frames[$this->frameIndex] ?? $this->frame;
    }

    public function animId(): int
    {
        return $this->animId;
    }
}
