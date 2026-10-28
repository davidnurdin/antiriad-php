<?php

namespace App\Gfx;

use App\Game\Run;
use App\Game\Track;
use JumPHP\Gl\Mat4;
use JumPHP\Renderer\Renderer;

/**
 * The 3D view without WebGL (desktop): the same camera, tiles projected with Mat4::project() and filled
 * line by line, drawn far to near (painter's order), the ball after the row it is on.
 */
final class SoftwareView
{
    private const AHEAD = 34;

    /** @param float[] $viewProj @param array{0: int[], 1: int[], 2: int[]} $sky */
    public static function draw(Renderer $r, Run $run, array $viewProj, array $sky): void
    {
        [$top, $bottom, $sun] = $sky;
        [$w, $h] = [$r->getWidth(), $r->getHeight()];
        $bands = 18;
        for ($i = 0; $i < $bands; $i++) {
            $t = min(1.0, $i / ($bands * 0.6));
            $r->fillRect(0, (int)($i * $h / $bands), $w, (int)ceil($h / $bands) + 1, self::mix($top, $bottom, $t));
        }
        $horizon = Mat4::project($viewProj, [0, 0, $run->z + 400], $w, $h);
        if ($horizon !== null) {
            $r->fillCircle($w / 2, $horizon[1] - 6, 26, $sun);
            $r->fillRect(0, (int)$horizon[1], $w, $h, $bottom);
        }

        $row = $run->row();
        for ($i = $row + self::AHEAD; $i >= $row - 3; $i--) {
            $fog = max(0.0, min(1.0, ($i - $row - 12) / 22));
            self::row($r, $run, $i, $viewProj, $bottom, $fog);
            if ($i === $row) {
                self::ball($r, $run, $viewProj);
            }
        }
    }

    /** @param float[] $vp @param int[] $fogColor */
    private static function row(Renderer $r, Run $run, int $row, array $vp, array $fogColor, float $fog): void
    {
        $track = $run->track;
        $zone = Meshes::zone($row);
        // outer lanes first, the one under the camera last: nearer sides cover farther ones
        foreach ([0, 4, 1, 3, 2] as $lane) {
            $h = $track->height($row, $lane);
            if ($h === null) {
                continue;
            }
            $base = $h > 0 ? $zone['block'] : (($row + $lane) % 2 ? $zone['a'] : $zone['b']);
            $topColor = self::mix($base, $fogColor, $fog);
            $sideColor = self::mix(self::scale($base, 0.55), $fogColor, $fog);
            [$x0, $x1, $z0, $z1, $y0] = [$lane - 2.47, $lane - 1.53, $row + 0.03, $row + 0.97, -0.5];
            if (($track->height($row, $lane - 1) ?? -9.0) < $h && $x0 > $run->x * 0.6) {
                self::fill($r, $vp, [[$x0, $y0, $z0], [$x0, $h, $z0], [$x0, $h, $z1], [$x0, $y0, $z1]], $sideColor);
            }
            if (($track->height($row, $lane + 1) ?? -9.0) < $h && $x1 < $run->x * 0.6) {
                self::fill($r, $vp, [[$x1, $y0, $z0], [$x1, $h, $z0], [$x1, $h, $z1], [$x1, $y0, $z1]], $sideColor);
            }
            if (($track->height($row - 1, $lane) ?? -9.0) < $h) {
                self::fill($r, $vp, [[$x0, $y0, $z0], [$x1, $y0, $z0], [$x1, $h, $z0], [$x0, $h, $z0]], $sideColor);
            }
            self::fill($r, $vp, [[$x0, $h, $z0], [$x1, $h, $z0], [$x1, $h, $z1], [$x0, $h, $z1]], $topColor);
            if ($track->hasGem($row, $lane)) {
                $p = Mat4::project($vp, [$lane - 2, $h + 0.6 + sin($run->time * 4 + $row) * 0.08, $row + 0.5], $r->getWidth(), $r->getHeight());
                $q = Mat4::project($vp, [$lane - 2 + 0.17, $h + 0.6, $row + 0.5], $r->getWidth(), $r->getHeight());
                if ($p !== null && $q !== null) {
                    $r->fillCircle($p[0], $p[1], max(1.0, abs($q[0] - $p[0])), self::mix([255, 220, 60], $fogColor, $fog));
                }
            }
        }
        // gems over holes
        for ($lane = 0; $lane < Track::LANES; $lane++) {
            if ($track->height($row, $lane) === null && $track->hasGem($row, $lane)) {
                $p = Mat4::project($vp, [$lane - 2, 1.0, $row + 0.5], $r->getWidth(), $r->getHeight());
                if ($p !== null) {
                    $r->fillCircle($p[0], $p[1], 2, [255, 220, 60]);
                }
            }
        }
    }

    /** @param float[] $vp */
    private static function ball(Renderer $r, Run $run, array $vp): void
    {
        [$w, $h] = [$r->getWidth(), $r->getHeight()];
        $center = [$run->x, $run->y + Run::RADIUS, $run->z];
        $p = Mat4::project($vp, $center, $w, $h);
        $q = Mat4::project($vp, [$run->x + Run::RADIUS, $run->y + Run::RADIUS, $run->z], $w, $h);
        if ($p === null || $q === null) {
            return;
        }
        $radius = max(2.0, abs($q[0] - $p[0]));
        $ground = $run->ground();
        if ($ground !== null && $run->state === 'run') {
            $s = Mat4::project($vp, [$run->x, $ground, $run->z], $w, $h);
            if ($s !== null) {
                $r->fillRect($s[0] - $radius * 0.9, $s[1] - 1, $radius * 1.8, 2, [0, 0, 0, 110]);
            }
        }
        $r->fillCircle($p[0], $p[1], $radius, [250, 250, 255]);
        // the red stripe goes round with the roll
        $c = cos($run->roll);
        $r->fillCircle($p[0], $p[1] - $c * $radius * 0.45, $radius * (0.35 + 0.25 * abs(sin($run->roll))), [255, 70, 90]);
        $r->fillCircle($p[0] - $radius * 0.35, $p[1] - $radius * 0.4, max(1.0, $radius * 0.22), [255, 255, 255]);
    }

    /** A convex polygon (3D points), filled one pixel row at a time. @param float[] $vp @param float[][] $points @param int[] $color */
    private static function fill(Renderer $r, array $vp, array $points, array $color): void
    {
        [$w, $h] = [$r->getWidth(), $r->getHeight()];
        $pts = [];
        foreach ($points as $p) {
            $q = Mat4::project($vp, $p, $w, $h);
            if ($q === null) {
                return;
            }
            $pts[] = $q;
        }
        $ys = array_column($pts, 1);
        $y0 = max(0, (int)floor(min($ys)));
        $y1 = min($h - 1, (int)floor(max($ys)));
        $n = count($pts);
        for ($y = $y0; $y <= $y1; $y++) {
            $sy = $y + 0.5;
            $xa = INF;
            $xb = -INF;
            for ($i = 0; $i < $n; $i++) {
                [$ax, $ay] = $pts[$i];
                [$bx, $by] = $pts[($i + 1) % $n];
                if (($ay <= $sy && $by >= $sy) || ($by <= $sy && $ay >= $sy)) {
                    $x = $ay == $by ? $ax : $ax + ($sy - $ay) * ($bx - $ax) / ($by - $ay);
                    $xa = min($xa, $x, $ay == $by ? $bx : $x);
                    $xb = max($xb, $x, $ay == $by ? $bx : $x);
                }
            }
            if ($xa <= $xb) {
                $r->fillRect((int)round($xa), $y, max(1, (int)round($xb) - (int)round($xa)), 1, $color);
            } elseif ($y0 === $y1) {
                $r->fillRect((int)round(min(array_column($pts, 0))), $y, 1, 1, $color);
            }
        }
    }

    /** @param int[] $a @param int[] $b @return int[] */
    private static function mix(array $a, array $b, float $t): array
    {
        return [(int)($a[0] + ($b[0] - $a[0]) * $t), (int)($a[1] + ($b[1] - $a[1]) * $t), (int)($a[2] + ($b[2] - $a[2]) * $t)];
    }

    /** @param int[] $c @return int[] */
    private static function scale(array $c, float $f): array
    {
        return [(int)($c[0] * $f), (int)($c[1] * $f), (int)($c[2] * $f)];
    }
}
