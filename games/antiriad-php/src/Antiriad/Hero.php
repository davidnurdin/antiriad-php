<?php

namespace App\Antiriad;

/**
 * Tal, on foot or in the suit: the player's object of the original (#B400), its moves and poses.
 * x in screen bytes, y in lines (its top), 6 bytes x 42 lines like every frame of Tal and of the suit.
 *
 * On foot Tal is the original's animation automaton (#675C): his animation (#9021, ids 1-#13: #02-#0A face
 * left, #0B-#13 the same mirrored, #01 faces the player) and the joystick give the next animation
 * (#8F4B) and the directions he moves. Turning round goes through #01, facing the player: let go then and
 * he stays so (that is how he gets into the suit).
 *
 * Falling (#65A2): each tick the speed is the fall table at an index that grows by one per tick; the lines
 * under the feet are scanned and the first ground line stops it. A jump climbs the same table backwards
 * (#66CE), the head stopped by a ceiling at the top of the screen.
 */
final class Hero
{
    public const W = 6;
    public const H = 42;
    private const JUMP_FROM = 11;       // #66C8: fall table index a jump starts from: 8, 7, 6, 5, 4, 4, 3...

    public const FRONT = 0x01;
    private const STAND_L = 0x02;
    private const WALK_L = 0x03;
    private const RISE_L = 0x07;
    private const FALL_L = 0x08;
    private const LAND_L = 0x09;
    private const THROW_L = 0x0A;
    private const STAND_R = 0x0B;
    private const WALK_R = 0x0C;
    private const RISE_R = 0x10;
    private const FALL_R = 0x11;
    private const LAND_R = 0x12;
    private const THROW_R = 0x13;
    private const DYING = 0x14;
    private const GETTING_UP = 0x15;
    /** crouching, crouched (#5B36: the hanging beast misses him), #04 / #0D: picking something up (#5D85) */
    public const CROUCHED = [0x04, 0x05, 0x0D, 0x0E];

    /**
     * #8F4B: per animation, the next one for each joystick column (#8F3B: none, up, right, down, left,
     * fire; 0 = no change), then the directions it moves (low nibble: 4 left, 8 right) and #10: the
     * animation must have ended before the joystick counts.
     */
    private const NEXT = [
        0x01 => [0, 0, 0x0B, 0, 0x02, 0, 0x10],
        0x02 => [0, 0, 0x01, 0x04, 0x03, 0x0A, 0x04],
        0x03 => [0x02, 0x02, 0x02, 0x04, 0, 0, 0x04],
        0x04 => [0x05, 0x06, 0x06, 0x05, 0x06, 0, 0x10],
        0x05 => [0x06, 0x06, 0, 0, 0, 0, 0],
        0x06 => [0x02, 0x02, 0x02, 0x05, 0x02, 0, 0x10],
        0x09 => [0x06, 0x06, 0x06, 0x05, 0x06, 0x06, 0x10],
        0x0B => [0, 0, 0x0C, 0x0D, 0x01, 0x13, 0x08],
        0x0C => [0x0B, 0x0B, 0, 0x0D, 0x0B, 0, 0x08],
        0x0D => [0x0E, 0x0F, 0x0F, 0x0E, 0x0F, 0, 0x10],
        0x0E => [0x0F, 0x0F, 0, 0, 0, 0, 0],
        0x0F => [0x0B, 0x0B, 0x0B, 0x0E, 0x0B, 0, 0x10],
        0x12 => [0x0F, 0x0F, 0x0F, 0x0E, 0x0F, 0x0F, 0x10],
    ];

    // the suit: #17 just in / landed facing the player (left / right then: Tal steps out, #6150), #16 facing
    // the player, #18 / #1A half turned, #19 / #1B walking left / right; up / down move it in every one
    public const SUIT_IN = 0x17;
    private const SUIT_FRONT = 0x16;
    private const SUIT_NEXT = [
        0x16 => [0, 0, 0x1A, 0, 0x18, 0, 0x13],
        0x17 => [0, 0x16, 0, 0, 0, 0, 0x01],
        0x18 => [0, 0, 0x16, 0, 0x19, 0, 0x13],
        0x19 => [0, 0, 0x18, 0, 0, 0, 0x07],
        0x1A => [0, 0, 0x1B, 0, 0x16, 0, 0x13],
        0x1B => [0, 0, 0, 0, 0x1A, 0, 0x0B],
    ];

    public int $x = 20;
    public int $y = 87;
    public int $facing = 1;             // 1 right, -1 left
    public bool $inSuit = false;
    public bool $onGround = true;
    public int $fallIndex = 0;
    public int $rise = -1;
    /** horizontal move while in the air (#B407 bits 2-3): -1, 0, 1 */
    public int $drift = 0;
    public int $frame = 9;
    public int $invulnerable = 0;
    public bool $flying = false;

    /** Tal's animation (the original's id) and its clock */
    public int $anim = self::FRONT;
    private int $animIndex = 0;
    private int $animTimer = 0;
    /** the animation reached its end (#B40A bit 7) */
    public bool $held = false;

    public function __construct(private World $world)
    {
        $this->setAnim(self::FRONT);
    }

    public function box(): array
    {
        return [$this->x * World::PIXELS_PER_BYTE + 4, $this->y - World::TOP + 2, self::W * World::PIXELS_PER_BYTE - 8, self::H - 4];
    }

    public function setAnim(int $id): void
    {
        $this->anim = $id;
        $this->animIndex = 0;
        $this->animTimer = $this->world->anims[$id]['ticks'];
        $this->frame = $this->world->anims[$id]['frames'][0];
        $this->held = false;
        if ($id >= self::STAND_R && $id <= self::THROW_R || $id === 0x1A || $id === 0x1B) {
            $this->facing = 1;
        } elseif ($id >= self::STAND_L && $id <= self::THROW_L || $id === 0x18 || $id === 0x19) {
            $this->facing = -1;
        }
    }

    /** #6B45: one tick of the animation. */
    private function runAnim(): void
    {
        if (--$this->animTimer > 0) {
            return;
        }
        $a = $this->world->anims[$this->anim];
        $this->animTimer = $a['ticks'];
        if ($this->animIndex + 1 < count($a['frames'])) {
            $this->frame = $a['frames'][++$this->animIndex];
            return;
        }
        if ($a['end'] === 'loop') {
            $this->animIndex = 0;
            $this->frame = $a['frames'][0];
        } elseif ($a['end'] === 'next') {
            $this->setAnim($a['next']);
        } else {
            $this->held = true;
        }
    }

    public function dying(): bool
    {
        return $this->anim === self::DYING || $this->anim === self::GETTING_UP;
    }

    public function die(): void
    {
        $this->rise = -1;
        $this->drift = 0;
        $this->setAnim(self::DYING);
    }

    /** #15: he gets up where he fell, then stands facing the player. */
    public function getUp(): void
    {
        $this->setAnim(self::GETTING_UP);
    }

    /** The dying animation and the lying time (2.7 s as in the emulator, frames 1110-1244): true when over. */
    public function dyingTick(int $ticks): bool
    {
        $this->runAnim();
        return $this->anim === self::DYING && $ticks >= 40;
    }

    public function crouched(): bool
    {
        return in_array($this->anim, self::CROUCHED, true);
    }

    /** Picking up (#5D85): Tal going down on his knees. */
    public function pickingUp(): bool
    {
        return $this->anim === 0x04 || $this->anim === 0x0D;
    }

    public function facingPlayer(): bool
    {
        return $this->anim === self::FRONT;
    }

    public function walking(): bool
    {
        return $this->inSuit ? $this->anim === 0x19 || $this->anim === 0x1B : $this->anim === self::WALK_L || $this->anim === self::WALK_R;
    }

    /** The throw's frame where the stone leaves the hand (#68EF: frame 5 of #0A / #13). */
    public function releasing(): bool
    {
        return ($this->anim === self::THROW_L || $this->anim === self::THROW_R) && $this->animIndex === 5 && $this->animTimer === $this->world->anims[$this->anim]['ticks'];
    }

    /** A footstep (#554E: the walking frames 0 and 4). */
    public function footstep(): bool
    {
        return $this->walking() && !$this->inSuit && in_array($this->frame & 0x7F, [0, 4], true);
    }

    /**
     * One tick on foot. $in: held left right up down, pressed fire / jump. Returns the events of the tick:
     * 'land' (a landing), 'down' (fell out through the bottom).
     *
     * @return list<string>
     */
    public function footTick(int $room, array $in): array
    {
        $events = [];
        $joyDir = ($in['right'] ? 8 : 0) | ($in['left'] ? 4 : 0);
        $grounded = $this->onGround && $this->rise < 0;
        if ($grounded) {
            // #6659: jumps. Up standing sideways: straight up; fire (or up / jump) walking: forward.
            $standing = $this->anim === self::STAND_L || $this->anim === self::STAND_R;
            $walking = $this->anim === self::WALK_L || $this->anim === self::WALK_R;
            if ($walking && ($in['fire'] || $in['jump'] || $in['up'])) {
                $this->jump($this->facing);
            } elseif ($standing && ($in['up'] || $in['jump'])) {
                $this->jump($in['right'] && $this->facing > 0 || $in['left'] && $this->facing < 0 ? $this->facing : 0);
            } else {
                $this->control($room, $in, $joyDir);
            }
        }
        if ($this->rise >= 0 || !$this->onGround) {
            if ($this->drift !== 0) {
                $this->step($room, $this->drift);
            }
        }
        $wasUp = $this->rise >= 0 || !$this->onGround;
        $fell = $this->fall($room);
        if ($fell !== null) {
            $events[] = $fell;
        }
        if ($this->rise < 0 && !$this->onGround && in_array($this->anim, [self::WALK_L, self::WALK_R, self::RISE_L, self::RISE_R, 0x06, 0x0F], true)) {
            $this->setAnim($this->facing > 0 ? self::FALL_R : self::FALL_L);     // #65F1
        }
        if ($wasUp && $this->onGround) {        // #6610: landing, the step sound
            $this->drift = 0;
            $this->setAnim($this->facing > 0 ? self::LAND_R : self::LAND_L);
            $events[] = 'land';
        }
        $this->runAnim();
        return $events;
    }

    /** #675C: the automaton. */
    private function control(int $room, array $in, int $joyDir): void
    {
        $row = self::NEXT[$this->anim] ?? null;
        if ($row === null || ($row[6] & 0x10) && !$this->held) {
            return;
        }
        $column = self::column($in, $joyDir);
        $moves = $joyDir & $row[6] & 0x0F;
        $next = $row[$column];
        if ($next !== 0) {
            $dir = $next === self::WALK_L ? -1 : ($next === self::WALK_R ? 1 : 0);
            if ($dir === 0 || !$this->blocked($room, $dir)) {      // #67A4: no walking into a wall
                $this->setAnim($next);
            }
        }
        if ($moves & 4) {
            $this->step($room, -1);
        } elseif ($moves & 8) {
            $this->step($room, 1);
        }
    }

    /** #656F / #8F3B: the joystick's column in #8F4B. */
    private static function column(array $in, int $joyDir): int
    {
        return match (true) {
            $in['fire'] => 5,
            $joyDir === 4 => 4,
            $joyDir === 8 => 2,
            $in['down'] && !$in['up'] => 3,
            $in['up'] && !$in['down'] => 1,
            default => 0,
        };
    }

    /** #67B6: a wall in the column ahead (7 bytes on the right, Tal's own on the left). */
    private function blocked(int $room, int $dir): bool
    {
        $column = $dir > 0 ? $this->x + 7 : $this->x;
        return $this->world->wallAt($room, $column, $this->y + 4, $this->y + 4 + ($this->inSuit ? 34 : 37));
    }

    /** The ground test under the feet (#68B4: 6 mode 0 pixels from the right half of the first byte). */
    private function groundAt(int $room, int $line): bool
    {
        return $this->world->groundAt($room, $line, 2 * $this->x + 3, 2 * $this->x + 8);
    }

    /**
     * Gravity and landing for one tick. Returns 'down' when it fell out of the room through a down exit.
     */
    public function fall(int $room): ?string
    {
        if ($this->rise >= 0) {                 // going up: the fall table backwards
            $speed = $this->world->fall[$this->rise];
            $moved = $this->up($room, $speed);
            $this->onGround = false;
            if ($moved === 0) {                 // #6707: the head hit the ceiling, the fall begins
                $this->rise = -1;
            } elseif ($moved === $speed) {
                $this->rise--;
            }
            if ($this->rise < 0) {
                $this->fallIndex = 0;
            }
            return null;
        }
        if ($this->flying) {
            return null;
        }
        $speed = $this->world->fall[min($this->fallIndex, 16)];
        $c = 0;
        while ($c < $speed && !$this->groundAt($room, $this->y + self::H + 1 + $c)) {
            $c++;
        }
        $this->y += $c;
        if ($c < $speed) {
            $this->onGround = true;
            $this->fallIndex = 0;
        } else {
            $this->onGround = false;
            $this->fallIndex = min($this->fallIndex + 1, 16);
        }
        if ($this->y + self::H >= World::BOTTOM) {
            if ($this->world->exit($room, 'down') !== null) {
                return 'down';
            }
            $this->y = World::BOTTOM - self::H - 1;
            $this->onGround = true;
            $this->fallIndex = 0;
        }
        return null;
    }

    /**
     * #66CE: up to $lines lines up, one at a time, the head line (y+7) tested against the ceiling (only
     * above the playfield's 8th line). Returns how many lines it went up.
     */
    public function up(int $room, int $lines): int
    {
        $c = 0;
        while ($c < $lines && !$this->world->ceilingAt($room, $this->y + 7 - $c, 2 * ($this->x + 1), 2 * ($this->x + 4) + 1)) {
            $c++;
        }
        $this->y -= $c;
        return $c;
    }

    /** Up to $lines lines down onto the ground (the suit coming down): true when it stands on it. */
    public function descend(int $room, int $lines): bool
    {
        for ($c = 0; $c < $lines; $c++) {
            if ($this->groundAt($room, $this->y + self::H + 1)) {
                $this->onGround = true;
                return true;
            }
            $this->y++;
        }
        $this->onGround = $this->groundAt($room, $this->y + self::H + 1);
        return $this->onGround;
    }

    /** One byte sideways unless a wall is there. */
    public function step(int $room, int $dir): void
    {
        $this->facing = $dir;
        if (!$this->blocked($room, $dir)) {
            $this->x += $dir;
        }
    }

    /** #66B1: the jump, straight up or forward ($drift); #07 / #10 going up. */
    public function jump(int $drift = 0): void
    {
        if ($this->onGround) {
            $this->rise = self::JUMP_FROM;
            $this->onGround = false;
            $this->drift = $drift;
            $this->setAnim($this->facing > 0 ? self::RISE_R : self::RISE_L);
        }
    }

    /** Back on foot (out of the suit, a new game): standing, facing the player. */
    public function stand(): void
    {
        $this->rise = -1;
        $this->drift = 0;
        $this->setAnim(self::FRONT);
    }

    /** Into the suit (#60F2): #17, standing still. */
    public function enterSuit(): void
    {
        $this->inSuit = true;
        $this->rise = -1;
        $this->drift = 0;
        $this->setAnim(self::SUIT_IN);
    }

    /**
     * One tick of the suit's automaton (#63A9, the same tables): returns the directions it may move
     * (1 up, 2 down, 4 left, 8 right) with this joystick.
     */
    public function suitControl(array $in): int
    {
        $joyDir = ($in['right'] ? 8 : 0) | ($in['left'] ? 4 : 0);
        $row = self::SUIT_NEXT[$this->anim] ?? self::SUIT_NEXT[self::SUIT_FRONT];
        if (($row[6] & 0x10) && !$this->held) {
            $this->runAnim();
            return 0;
        }
        $next = $row[self::column($in, $joyDir)];
        $moves = ($joyDir | ($in['up'] || $in['jump'] ? 1 : 0) | ($in['down'] ? 2 : 0)) & $row[6] & 0x0F;
        if ($next !== 0) {
            $this->setAnim($next);
        } else {
            $this->runAnim();
        }
        return $moves;
    }

    /** #639F: the suit comes down on the ground facing the player: #17, Tal may step out. */
    public function suitLanded(): void
    {
        if ($this->anim === self::SUIT_FRONT) {
            $this->setAnim(self::SUIT_IN);
        }
    }

    /** Lines between the top of Tal's box and the top of his sprite: lying, the feet stay on the ground. */
    public function drawOffset(): int
    {
        return self::H - $this->world->sprites[$this->frame & 0x7F]['h'];
    }
}
