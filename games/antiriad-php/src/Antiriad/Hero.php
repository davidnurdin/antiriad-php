<?php

namespace App\Antiriad;

/**
 * Tal, on foot or in the suit: the player's object of the original (#B400), its moves and poses.
 * x in screen bytes, y in lines (its top), 6 bytes x 42 lines like every frame of Tal and of the suit.
 *
 * Falling (#65A2): each tick the speed is the fall table at an index that grows by one per tick; the lines
 * under the feet are scanned and the first ground line stops it. A jump climbs the same table backwards.
 */
final class Hero
{
    public const W = 6;
    public const H = 42;
    private const JUMP_FROM = 11;       // fall table index a jump starts from: 8, 7, 6, 5, 4, 4, 3... = 46 lines

    // sprites of the original (facing left; bit 7 = mirrored), animations #9021 1-#15
    private const WALK = [3, 4, 5, 6, 7, 0, 1, 2];
    private const THROW = [17, 17, 18, 18, 19, 19, 19, 17, 17, 8];
    private const IDLE = 9;
    private const RISE = 15;
    private const FALL = 16;
    private const LAND = [13, 11];
    private const CROUCH = [10, 11];
    private const DIE = [123, 124, 124, 124, 124, 124, 124, 124];
    private const SUIT_IDLE = 22;
    private const SUIT_FLY = 23;
    private const SUIT_WALK = [20, 21];

    public int $x = 20;
    public int $y = 87;
    public int $facing = 1;             // 1 right, -1 left
    public bool $inSuit = false;
    public bool $onGround = true;
    public int $fallIndex = 0;
    public int $rise = -1;
    public string $pose = 'idle';       // idle walk air land crouch throw die
    public int $poseTick = 0;
    public int $frame = self::IDLE;
    public int $invulnerable = 0;
    public bool $flying = false;

    public function __construct(private World $world)
    {
    }

    public function box(): array
    {
        return [$this->x * World::PIXELS_PER_BYTE + 4, $this->y - World::TOP + 2, self::W * World::PIXELS_PER_BYTE - 8, self::H - 4];
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
            $this->y -= $this->world->fall[$this->rise--];
            $this->onGround = false;
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
            if (!$this->onGround && $this->fallIndex > 2 && $this->pose === 'air') {
                $this->setPose('land');
            }
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

    /** One byte sideways unless a wall is there (#67B6: the column 7 bytes ahead, or Tal's own on the left). */
    public function step(int $room, int $dir): void
    {
        $this->facing = $dir;
        $column = $dir > 0 ? $this->x + 7 : $this->x;
        if (!$this->world->wallAt($room, $column, $this->y + 4, $this->y + 4 + ($this->inSuit ? 34 : 37))) {
            $this->x += $dir;
        }
    }

    public function jump(): void
    {
        if ($this->onGround) {
            $this->rise = self::JUMP_FROM;
            $this->onGround = false;
            $this->setPose('air');
        }
    }

    public function setPose(string $pose): void
    {
        if ($this->pose !== $pose) {
            $this->pose = $pose;
            $this->poseTick = 0;
        }
    }

    public function poseDone(): bool
    {
        return match ($this->pose) {
            'throw' => $this->poseTick >= count(self::THROW),
            'land' => $this->poseTick >= count(self::LAND),
            'die' => $this->poseTick >= count(self::DIE) + 6,
            default => true,
        };
    }

    /** The sprite of this tick (and the pose's clock). */
    public function animate(): void
    {
        $t = $this->poseTick++;
        $mirror = $this->facing > 0 ? 0x80 : 0;
        if ($this->inSuit) {
            $this->frame = match (true) {
                $this->flying || !$this->onGround => self::SUIT_FLY,
                $this->pose === 'walk' => self::SUIT_WALK[intdiv($t, 2) % 2] | $mirror,
                default => self::SUIT_IDLE,
            };
            return;
        }
        $this->frame = match ($this->pose) {
            'walk' => self::WALK[$t % 8],
            'air' => $this->rise >= 0 ? self::RISE : self::FALL,
            'land' => self::LAND[min($t, 1)],
            'crouch' => self::CROUCH[min($t, 1)],
            'throw' => self::THROW[min($t, count(self::THROW) - 1)],
            'die' => self::DIE[min($t, count(self::DIE) - 1)],
            default => self::IDLE,
        } | ($this->pose === 'die' ? 0 : $mirror);
    }

    /** The throw's frame where the stone leaves the hand. */
    public function releasing(): bool
    {
        return $this->pose === 'throw' && $this->poseTick === 5;
    }
}
