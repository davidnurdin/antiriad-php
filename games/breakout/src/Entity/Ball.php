<?php

namespace App\Entity;

use JumPHP\Entity\Entity;
use JumPHP\Renderer\Renderer;

/**
 * The ball. The scene moves it one axis at a time (moveX / moveY) so a brick hit knows which side it hit.
 */
final class Ball extends Entity
{
    public const SIZE = 4;
    public const MAX_SPEED = 330.0;

    public float $speed = 150.0;

    public function __construct(float $x, float $y)
    {
        $this->x = $x;
        $this->y = $y;
        $this->width = self::SIZE;
        $this->height = self::SIZE;
    }

    /** Launches upwards at $speed, a little to the left or right. */
    public function launch(float $speed): void
    {
        $this->speed = $speed;
        $this->aim(random_int(0, 1) ? 0.4 : -0.4);
    }

    /** Points the ball upwards; $offset -1 (far left) .. 1 (far right) of the paddle. */
    public function aim(float $offset): void
    {
        $angle = deg2rad(60) * max(-1.0, min(1.0, $offset));
        $this->vx = sin($angle) * $this->speed;
        $this->vy = -cos($angle) * $this->speed;
    }

    public function speedUp(float $factor): void
    {
        $this->speed = min(self::MAX_SPEED, $this->speed * $factor);
        $length = sqrt($this->vx * $this->vx + $this->vy * $this->vy);
        if ($length > 0) {
            $this->vx *= $this->speed / $length;
            $this->vy *= $this->speed / $length;
        }
    }

    public function render(Renderer $r, float $camX = 0, float $camY = 0): void
    {
        $r->fillRect($this->x + 1, $this->y, 2, self::SIZE, [255, 255, 255]);
        $r->fillRect($this->x, $this->y + 1, self::SIZE, 2, [255, 255, 255]);
    }
}
