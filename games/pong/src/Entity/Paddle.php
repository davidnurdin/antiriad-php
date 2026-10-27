<?php

namespace App\Entity;

use JumPHP\Entity\Entity;
use JumPHP\Renderer\Renderer;
use JumPHP\Util\Math;

/**
 * A paddle: moves up and down inside the court, at most SPEED pixels per second.
 */
final class Paddle extends Entity
{
    public const WIDTH = 4;
    public const HEIGHT = 28;
    public const SPEED = 190.0;

    public function __construct(float $x, float $y, private float $top, private float $bottom)
    {
        $this->x = $x;
        $this->y = $y;
        $this->width = self::WIDTH;
        $this->height = self::HEIGHT;
    }

    /** $dir: -1 up, 0, 1 down (keys). */
    public function move(float $dir, float $dt): void
    {
        $this->setPosition($this->y + $dir * self::SPEED * $dt);
    }

    /** Towards a target y of the centre (computer, touch, mouse), at the paddle speed. */
    public function moveTowards(float $centerY, float $dt, float $speedFactor = 1.0): void
    {
        $this->setPosition(Math::approach($this->y, $centerY - $this->height / 2, self::SPEED * $speedFactor * $dt));
    }

    /** Top y, kept inside the court (network: the remote paddle). */
    public function setPosition(float $y): void
    {
        $this->y = Math::clamp($y, $this->top, $this->bottom - $this->height);
    }

    public function render(Renderer $r, float $camX = 0, float $camY = 0): void
    {
        $r->fillRect($this->x, $this->y, $this->width, $this->height, [255, 255, 255]);
    }
}
