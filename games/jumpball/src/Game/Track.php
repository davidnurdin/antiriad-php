<?php

namespace App\Game;

/**
 * The endless track: 5 lanes, one row per metre, generated ahead of the ball from a seed (same seed = same
 * track). A cell is a hole, a floor tile or a block (a raised tile: jump on it or go around). Obstacles get
 * harder with the distance, and the straight parts between them grow with the expected speed so that a
 * jump always lands somewhere.
 */
final class Track
{
    public const LANES = 5;
    public const HOLE = 0;
    public const FLOOR = 1;
    public const BLOCK = 2;
    /** Rows without obstacles at the start. */
    public const SAFE_ROWS = 24;

    private static int $serials = 0;

    /** Changes for each new track (the 3D view rebuilds its meshes). */
    public readonly int $serial;
    /** @var array<int, int[]> row => lane => cell */
    private array $rows = [];
    /** @var array<int, array<int, bool>> row => lane => gem */
    private array $gems = [];
    private int $generated = 0;
    private float $seed;

    public function __construct(int $seed)
    {
        $this->serial = ++self::$serials;
        $this->seed = (float)(abs($seed) % 2147483646 + 1);
    }

    /** Speed of the ball when it reaches $row (v² = v0² + 2·a·d, see Run). */
    public static function speedAt(float $row): float
    {
        return min(Run::MAX_SPEED, sqrt(Run::START_SPEED ** 2 + 2 * Run::ACCEL * max(0.0, $row)));
    }

    public function cell(int $row, int $lane): int
    {
        if ($row < 0 || $lane < 0 || $lane >= self::LANES) {
            return self::HOLE;
        }
        $this->ensure($row);
        return $this->rows[$row][$lane] ?? self::HOLE;
    }

    /** Height of the top of a cell: null for a hole. */
    public function height(int $row, int $lane): ?float
    {
        return match ($this->cell($row, $lane)) {
            self::FLOOR => 0.0,
            self::BLOCK => 1.0,
            default => null,
        };
    }

    public function hasGem(int $row, int $lane): bool
    {
        $this->ensure($row);
        return isset($this->gems[$row][$lane]);
    }

    public function takeGem(int $row, int $lane): bool
    {
        if (!$this->hasGem($row, $lane)) {
            return false;
        }
        unset($this->gems[$row][$lane]);
        return true;
    }

    /** Frees the rows behind the ball. */
    public function forget(int $below): void
    {
        foreach (array_keys($this->rows) as $row) {
            if ($row >= $below) {
                break;
            }
            unset($this->rows[$row], $this->gems[$row]);
        }
    }

    // --- generation -----------------------------------------------------------------------------

    private function ensure(int $row): void
    {
        while ($this->generated <= $row) {
            $this->segment();
        }
    }

    private function segment(): void
    {
        $n = $this->generated;
        if ($n < self::SAFE_ROWS) {
            for ($i = 0; $i < self::SAFE_ROWS; $i++) {
                $this->push([1, 1, 1, 1, 1], $i >= 10 && $i % 2 === 0 ? 2 : -1);
            }
            return;
        }
        $d = min(1.0, ($n - self::SAFE_ROWS) / 900);
        // room to land after the previous obstacle: one jump covers ~0.75 s of travel
        $this->straight((int)ceil(0.75 * self::speedAt($n)) + 1 + $this->int(0, 2));
        $pick = $this->rand();
        match (true) {
            $pick < 0.2 => $this->gap($d),
            $pick < 0.38 => $this->missingLanes($d),
            $pick < 0.56 => $this->blocks($d),
            $pick < 0.72 => $this->path($d),
            $pick < 0.86 => $this->islands($d),
            default => $this->platform($d),
        };
    }

    private function straight(int $rows): void
    {
        $gemLane = $this->rand() < 0.5 ? $this->int(0, 4) : -1;
        for ($i = 0; $i < $rows; $i++) {
            $this->push([1, 1, 1, 1, 1], $i % 2 === 1 ? $gemLane : -1);
        }
    }

    /** Holes across the whole track: jump. */
    private function gap(float $d): void
    {
        $length = $this->int(1, 1 + (int)round(2 * $d));
        $gemLane = $this->int(0, 4);
        for ($i = 0; $i < $length; $i++) {
            $this->push([0, 0, 0, 0, 0], $gemLane);
        }
    }

    /** Some lanes missing for a while: steer. */
    private function missingLanes(float $d): void
    {
        $lanes = [0, 1, 2, 3, 4];
        $this->shuffle($lanes);
        $missing = array_slice($lanes, 0, $this->int(1, 2 + (int)round($d)));
        $cells = [1, 1, 1, 1, 1];
        foreach ($missing as $lane) {
            $cells[$lane] = 0;
        }
        $gemLane = array_values(array_diff([0, 1, 2, 3, 4], $missing))[0];
        $rows = $this->int(4, 8 + (int)round(6 * $d));
        for ($i = 0; $i < $rows; $i++) {
            $this->push($cells, $i % 2 ? $gemLane : -1);
        }
    }

    /** Rows of blocks with at least one free lane: go around, or jump on them. */
    private function blocks(float $d): void
    {
        $count = $this->int(1, 2 + (int)round($d));
        for ($r = 0; $r < $count; $r++) {
            $lanes = [0, 1, 2, 3, 4];
            $this->shuffle($lanes);
            $cells = [1, 1, 1, 1, 1];
            foreach (array_slice($lanes, 0, $this->int(2, 3 + (int)round($d))) as $lane) {
                $cells[$lane] = 2;
            }
            $gem = $cells[$lanes[0]] === 2 && $this->rand() < 0.6 ? $lanes[0] : -1;
            $length = $this->rand() < 0.3 ? 2 : 1;
            for ($i = 0; $i < $length; $i++) {
                $this->push($cells, $gem);
            }
            for ($i = 0, $gap = $this->int(3, 5); $i < $gap; $i++) {
                $this->push([1, 1, 1, 1, 1]);
            }
        }
    }

    /** A narrow path that turns, after a funnel from the full width. */
    private function path(float $d): void
    {
        $this->push([1, 1, 1, 1, 1]);
        for ($i = 0; $i < 3; $i++) {
            $this->push([0, 1, 1, 1, 0]);
        }
        $lane = 2;
        $wide = $d < 0.25;                  // early: two lanes wide
        $rows = $this->int(10, 14 + (int)round(10 * $d));
        $next = $this->int(3, 5);
        for ($i = 0; $i < $rows; $i++) {
            $cells = [0, 0, 0, 0, 0];
            $cells[$lane] = 1;
            if ($wide) {
                $cells[$lane < 4 ? $lane + 1 : $lane - 1] = 1;
            }
            if (--$next === 0) {
                $to = max(0, min(4, $lane + ($this->rand() < 0.5 ? -1 : 1)));
                $cells[$to] = 1;
                $lane = $to;
                $next = $this->int(3, 5);
            }
            $this->push($cells, $i % 3 === 1 ? $lane : -1);
        }
    }

    /** Islands: a hole, a floor long enough to land and jump again, again... */
    private function islands(float $d): void
    {
        $count = $this->int(2, 3 + (int)round(2 * $d));
        for ($k = 0; $k < $count; $k++) {
            $hole = $this->int(1, 2);
            for ($i = 0; $i < $hole; $i++) {
                $this->push([0, 0, 0, 0, 0]);
            }
            $flight = 0.75 * self::speedAt($this->generated);
            $floor = max(2, (int)ceil($flight - $hole) + 1);
            $gemLane = $this->int(0, 4);
            for ($i = 0; $i < $floor; $i++) {
                $this->push([1, 1, 1, 1, 1], $i === 0 ? $gemLane : -1);
            }
        }
    }

    /** A raised platform (blocks across the track) then a hole: jump up, then jump over. */
    private function platform(float $d): void
    {
        // long enough to land on it: a jump up to it stays ~0.6 s in the air
        $length = (int)ceil(0.65 * self::speedAt($this->generated)) + $this->int(2, 3);
        for ($i = 0; $i < $length; $i++) {
            $this->push([2, 2, 2, 2, 2], $i === 1 ? $this->int(0, 4) : -1);
        }
        $hole = $this->int(1, 2 + (int)round($d));
        for ($i = 0; $i < $hole; $i++) {
            $this->push([0, 0, 0, 0, 0]);
        }
    }

    /** @param int[] $cells */
    private function push(array $cells, int $gemLane = -1): void
    {
        $row = $this->generated++;
        $this->rows[$row] = $cells;
        if ($gemLane >= 0) {
            $this->gems[$row][$gemLane] = true;
        }
    }

    /** Park-Miller: exact with floats, so the same on 32-bit (browser) and 64-bit PHP. */
    private function rand(): float
    {
        $this->seed = fmod($this->seed * 16807.0, 2147483647.0);
        return $this->seed / 2147483647.0;
    }

    private function int(int $min, int $max): int
    {
        return min($max, $min + (int)floor($this->rand() * ($max - $min + 1)));
    }

    /** @param int[] $items */
    private function shuffle(array &$items): void
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
    }
}
