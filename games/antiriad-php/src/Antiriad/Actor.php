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
    public bool $dead = false;
    /** index in the world's item list (cells / modules) when it is one */
    public int $itemIndex = -1;
    public int $flash = 0;
    /** shots: ticks left, owner damage */
    public int $life = 0;

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

    public function __construct(private World $world, public int $type, public int $x, public int $y, int $program = 0)
    {
        $t = $world->type($type);
        $this->damage = $t[1] & 0x1F;
        $this->points = $t[2];
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

    public function tick(int $heroX, int $heroY): void
    {
        if ($this->flash > 0) {
            $this->flash--;
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
        if ($dirs === 0x0F) {                   // #6CDE: towards Tal
            $dirs = ($heroX > $this->x ? 8 : ($heroX < $this->x ? 4 : 0)) | ($heroY > $this->y ? 2 : ($heroY < $this->y ? 1 : 0));
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
            if ($this->x < 0 || $this->x + $w > 80) {
                $this->dead = true;
            }
            return;
        }
        // #6C43...: the screen's bytes and the 0-255 lines bound them; what falls off the playfield is gone
        $this->x = max(0, min(80 - $w, $this->x));
        $this->y = max(0, min(255 - $h, $this->y));
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
