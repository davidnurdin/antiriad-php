<?php

namespace App\Entity;

use JumPHP\Entity\Entity;
use JumPHP\Renderer\Renderer;
use JumPHP\Util\Math;

/**
 * The player's paddle: slides left and right between the side walls, at most SPEED px/s.
 */
final class Paddle extends Entity
{
    public const WIDTH = 36;
    public const HEIGHT = 6;
    public const SPEED = 240.0;

    public function __construct(float $x, float $y, private float $left, private float $right)
    {
        $this->x = $x;
        $this->y = $y;
        $this->width = self::WIDTH;
        $this->height = self::HEIGHT;
    }

    /** $dir: -1 left, 0, 1 right (keys). */
    public function move(float $dir, float $dt): void
    {
        $this->setPosition($this->x + $dir * self::SPEED * $dt);
    }

    /** Towards a target x of the centre (computer, touch), at the paddle speed. */
    public function moveTowards(float $centerX, float $dt): void
    {
        $this->setPosition(Math::approach($this->x, $centerX - $this->width / 2, self::SPEED * $dt));
    }

    public function setPosition(float $x): void
    {
        $this->x = Math::clamp($x, $this->left, $this->right - $this->width);
    }

    public function render(Renderer $r, float $camX = 0, float $camY = 0): void
    {
        $r->fillRect($this->x, $this->y, $this->width, $this->height, [200, 200, 220]);
        $r->fillRect($this->x, $this->y, 4, $this->height, [230, 70, 70]);
        $r->fillRect($this->x + $this->width - 4, $this->y, 4, $this->height, [230, 70, 70]);
        $r->fillRect($this->x, $this->y, $this->width, 1, [255, 255, 255]);
    }
}
