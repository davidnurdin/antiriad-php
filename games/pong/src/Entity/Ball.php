<?php

namespace App\Entity;

use JumPHP\Entity\Entity;
use JumPHP\Renderer\Renderer;

/**
 * The ball: moves straight, bounces on the top and bottom walls; Court::hitPaddle() sends it back.
 * Faster after each paddle hit, up to MAX_SPEED.
 */
final class Ball extends Entity
{
    public const SIZE = 4;
    public const START_SPEED = 150.0;
    public const MAX_SPEED = 380.0;
    public const SPEED_UP = 1.07;

    public float $speed = self::START_SPEED;
    /** @var array<int, array{0: float, 1: float}> last positions, for the trail */
    private array $trail = [];

    public function __construct(float $x, float $y)
    {
        $this->x = $x;
        $this->y = $y;
        $this->width = self::SIZE;
        $this->height = self::SIZE;
    }

    /** Launches from ($x, $y) towards $dir (-1 left, 1 right) with a small random angle. */
    public function serve(float $x, float $y, int $dir): void
    {
        $this->x = $x - self::SIZE / 2;
        $this->y = $y - self::SIZE / 2;
        $this->speed = self::START_SPEED;
        $angle = deg2rad(random_int(-30, 30));
        $this->vx = cos($angle) * $this->speed * $dir;
        $this->vy = sin($angle) * $this->speed;
        $this->trail = [];
    }

    /** Sends the ball back at an angle that depends on where it hit ($offset -1 top .. 1 bottom of the paddle). */
    public function bounceFromPaddle(float $offset, int $dir): void
    {
        $this->speed = min(self::MAX_SPEED, $this->speed * self::SPEED_UP);
        $angle = deg2rad(55) * max(-1.0, min(1.0, $offset));
        $this->vx = cos($angle) * $this->speed * $dir;
        $this->vy = sin($angle) * $this->speed;
    }

    public function update(float $deltaTime): void
    {
        $this->trail[] = [$this->x, $this->y];
        if (count($this->trail) > 6) {
            array_shift($this->trail);
        }
        $this->x += $this->vx * $deltaTime;
        $this->y += $this->vy * $deltaTime;
    }

    public function bounceY(): void
    {
        $this->vy = -$this->vy;
    }

    public function render(Renderer $r, float $camX = 0, float $camY = 0): void
    {
        foreach ($this->trail as $i => [$x, $y]) {
            $r->fillRect($x + 1, $y + 1, 2, 2, [255, 255, 255, 30 + $i * 25]);
        }
        $r->fillRect($this->x, $this->y, self::SIZE, self::SIZE, [255, 255, 255]);
    }
}
