<?php

namespace App\Game;

/**
 * One run: the ball on its track. Plain logic (no drawing), stepped at a fixed rate by the scenes.
 * Units: 1 = one tile = one metre; x = -2..2 (lane centres), z = forward, y = up (0 = floor top);
 * the ball's position is the bottom of the ball.
 */
final class Run
{
    public const RADIUS = 0.35;
    public const GRAVITY = 24.0;
    public const JUMP = 9.0;
    public const START_SPEED = 6.0;
    public const MAX_SPEED = 14.0;
    /** Speed gained per second. */
    public const ACCEL = 0.1;
    public const GEM_POINTS = 10;

    public Track $track;
    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 1.0;
    public float $vx = 0.0;
    public float $vy = 0.0;
    public float $speed = self::START_SPEED;
    /** Rotation of the ball (radians), for the drawing. */
    public float $roll = 0.0;
    public float $time = 0.0;
    /** Height followed by the camera (smoothed, kept while falling). */
    public float $camY = 0.0;
    /** Seconds since the fall / crash. */
    public float $endTime = 0.0;
    public bool $grounded = true;
    /** run, fall, crash, over */
    public string $state = 'run';
    public int $gems = 0;
    /** @var string[] what happened since the scene last read it: jump, land, gem, fall, crash, milestone */
    public array $events = [];

    public function __construct(int $seed)
    {
        $this->track = new Track($seed);
    }

    public function row(): int
    {
        return (int)floor($this->z);
    }

    /** Lane index 0..4 under the ball (-1 / 5: beside the track). */
    public function lane(): int
    {
        return max(-1, min(Track::LANES, (int)floor($this->x + 0.5) + 2));
    }

    public function distance(): int
    {
        return max(0, (int)floor($this->z));
    }

    public function score(): int
    {
        return $this->distance() + $this->gems * self::GEM_POINTS;
    }

    /** Height under the ball, null over a hole. */
    public function ground(): ?float
    {
        return $this->track->height($this->row(), $this->lane());
    }

    /**
     * @param float $steer -1..1
     * @param bool $jump jump when on the ground (held: jumps again at landing)
     */
    public function step(float $h, float $steer, bool $jump): void
    {
        $this->time += $h;
        if ($this->state === 'over') {
            return;
        }
        if ($this->state !== 'run') {
            $this->endTime += $h;
            $this->vy -= self::GRAVITY * $h;
            $this->y += $this->vy * $h;
            $this->x += $this->vx * $h;
            $this->z += $this->speed * $h;
            $this->roll += $this->speed * $h / self::RADIUS;
            if ($this->state === 'crash') {
                $this->speed = max(-2.0, $this->speed - 30 * $h);
                $this->y = max($this->ground() ?? -50.0, $this->y);
            }
            if ($this->endTime > ($this->state === 'fall' ? 1.1 : 1.0)) {
                $this->state = 'over';
            }
            return;
        }

        $this->speed = min(self::MAX_SPEED, self::START_SPEED + self::ACCEL * $this->time);
        $max = 5 + $this->speed * 0.35;
        $this->vx += (max(-1.0, min(1.0, $steer)) * $max - $this->vx) * min(1.0, 14 * $h);
        $this->x += $this->vx * $h;
        $before = $this->distance();
        $this->z += $this->speed * $h;
        $this->roll += $this->speed * $h / self::RADIUS;
        if ($jump && $this->grounded) {
            $this->vy = self::JUMP;
            $this->grounded = false;
            $this->events[] = 'jump';
        }
        $this->vy -= self::GRAVITY * $h;
        $this->y += $this->vy * $h;

        $row = $this->row();
        $lane = $this->lane();
        if ($this->track->cell($row, $lane) === Track::BLOCK && $this->y < 0.7) {
            $dx = $this->x - ($lane - 2);
            if (abs($dx) > 0.25 && $this->track->cell($row, $lane + ($dx > 0 ? 1 : -1)) !== Track::BLOCK) {
                // grazing the side of a block: pushed back into the lane beside it
                $this->x = $lane - 2 + ($dx > 0 ? 0.52 : -0.52);
                $this->vx = 0.0;
                $lane = $this->lane();
            }
        }
        if ($this->track->cell($row, $lane) === Track::BLOCK && $this->y < 0.7) {
            // into the front of a block
            $this->state = 'crash';
            $this->speed = -3.0;
            $this->vy = 5.0;
            $this->vx *= -0.5;
            $this->events[] = 'crash';
            return;
        }
        $ground = $this->track->height($row, $lane);
        if ($ground !== null && $this->vy <= 0 && $this->y <= $ground && $this->y > $ground - 0.3) {
            if (!$this->grounded) {
                $this->events[] = 'land';
            }
            $this->y = $ground;
            $this->vy = 0.0;
            $this->grounded = true;
        } else {
            $this->grounded = false;
        }
        if ($this->y < -0.4) {
            $this->state = 'fall';
            $this->events[] = 'fall';
            return;
        }

        $gemY = ($ground ?? 0.0) + 0.6;
        foreach ([$row, $row - 1] as $r) {
            if (abs($this->y + self::RADIUS - $gemY) < 0.75 && $this->track->takeGem($r, $lane)) {
                $this->gems++;
                $this->events[] = 'gem';
            }
        }
        if (intdiv($this->distance(), 100) > intdiv($before, 100)) {
            $this->events[] = 'milestone';
        }
        $this->camY += (max(0.0, min(1.0, $this->y)) - $this->camY) * min(1.0, 5 * $h);
        $this->track->forget($row - 40);
    }

    /**
     * A simple bot (title screen, demo, smoke test): picks the lane that stays safe the longest and jumps
     * over holes and blocks. @return array{0: float, 1: bool} steer, jump
     */
    public function autopilot(): array
    {
        $row = $this->row();
        $best = 2;
        $bestScore = -INF;
        for ($lane = 0; $lane < Track::LANES; $lane++) {
            $safe = 0;
            for ($i = 1; $i <= 20 && $this->track->cell($row + $i, $lane) === Track::FLOOR; $i++) {
                $safe++;
            }
            $score = $safe - abs($lane - 2 - $this->x) * 1.2 + ($this->track->hasGem($row + 3, $lane) ? 0.6 : 0.0);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$lane, $score];
            }
        }
        $steer = max(-1.0, min(1.0, ($best - 2 - $this->x) * 3));
        $lane = $this->lane();
        $hole = $this->track->cell((int)floor($this->z + $this->speed * 0.07 + 0.2), $lane) === Track::HOLE;
        $block = $this->y < 0.5 && $this->track->cell((int)floor($this->z + $this->speed * 0.2 + 0.3), $lane) === Track::BLOCK;
        return [$steer, $hole || $block];
    }
}
