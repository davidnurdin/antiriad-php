<?php

namespace App\Work;

/**
 * The heavy part, written to run in a worker thread: pure computation, plain values in and out
 * (no engine, no SDL, no $this). One row of a Mandelbrot set as a string of iteration bytes (0 = inside).
 */
final class Fractal
{
    /** Places to look at: [centre x, centre y, width of the view]. */
    public const SPOTS = [
        [-0.6, 0.0, 3.2],
        [-1.3, 0.0, 0.5],
        [-0.745, 0.113, 0.03],
        [0.2549, 0.0005, 0.0045],
        [0.3, 0.02, 0.06],
    ];

    public static function row(int $y, int $width, int $height, float $cx, float $cy, float $span, int $maxIter): string
    {
        $scale = $span / $width;
        $ci = $cy + ($y - $height / 2) * $scale;
        $row = '';
        for ($x = 0; $x < $width; $x++) {
            $cr = $cx + ($x - $width / 2) * $scale;
            $zr = $zi = 0.0;
            $i = 0;
            while ($i < $maxIter && $zr * $zr + $zi * $zi <= 4.0) {
                $t = $zr * $zr - $zi * $zi + $cr;
                $zi = 2.0 * $zr * $zi + $ci;
                $zr = $t;
                $i++;
            }
            $row .= chr($i >= $maxIter ? 0 : 1 + $i % 255);
        }
        return $row;
    }
}
