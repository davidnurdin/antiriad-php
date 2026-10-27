<?php

namespace App\Ai;

use App\Entity\Ball;
use App\Entity\Paddle;

/**
 * The computer: predicts where the ball will cross its side (bounces included) and goes there, with a
 * reaction time, a speed limit and an aiming error that depend on the level.
 */
final class CpuPlayer
{
    public const LEVELS = [
        'easy' => ['reaction' => 0.30, 'speed' => 0.55, 'error' => 18.0],
        'normal' => ['reaction' => 0.16, 'speed' => 0.80, 'error' => 9.0],
        'hard' => ['reaction' => 0.06, 'speed' => 1.00, 'error' => 3.0],
    ];

    private array $level;
    private float $think = 0.0;
    private float $target;
    private float $error = 0.0;

    public function __construct(private Paddle $paddle, private int $side, private float $top, private float $bottom, string $level = 'normal')
    {
        $this->level = self::LEVELS[$level] ?? self::LEVELS['normal'];
        $this->target = ($top + $bottom) / 2;
    }

    public function update(Ball $ball, float $dt): void
    {
        $this->think -= $dt;
        if ($this->think <= 0) {
            $this->think = $this->level['reaction'];
            [$vx] = $ball->getVelocity();
            $coming = $this->side < 0 ? $vx < 0 : $vx > 0;
            if ($coming) {
                $this->target = $this->predict($ball) + $this->error;
            } else {
                $this->target = ($this->top + $this->bottom) / 2;   // back to the middle
                $this->error = (mt_rand() / mt_getrandmax() * 2 - 1) * $this->level['error'];
            }
        }
        $this->paddle->moveTowards($this->target, $dt, $this->level['speed']);
    }

    /** y of the ball centre when it reaches the paddle, walls bounces unfolded. */
    private function predict(Ball $ball): float
    {
        [$vx, $vy] = $ball->getVelocity();
        if ($vx == 0.0) {
            return $ball->centerY();
        }
        $time = ($this->paddle->centerX() - $ball->centerX()) / $vx;
        $height = $this->bottom - $this->top - Ball::SIZE;
        $y = $ball->getFloatY() - $this->top + $vy * $time;
        // reflect into [0, height]
        $period = 2 * $height;
        $y = fmod(fmod($y, $period) + $period, $period);
        if ($y > $height) {
            $y = $period - $y;
        }
        return $this->top + $y + Ball::SIZE / 2;
    }
}
