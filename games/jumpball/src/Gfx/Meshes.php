<?php

namespace App\Gfx;

use App\Game\Track;

/**
 * GLSL sources (WebGL 1 / GLSL ES 1.00), meshes and colours of the 3D view.
 * Vertex layout of the lit meshes: x y z, r g b (0..1), nx ny nz (9 floats).
 */
final class Meshes
{
    public const STRIDE = 9;
    /** Rows of one zone: the colours change every ZONE metres. */
    public const ZONE = 250;

    public const VERTEX = <<<'GLSL'
        attribute vec3 a_pos;
        attribute vec3 a_color;
        attribute vec3 a_normal;
        uniform mat4 u_viewProj;
        uniform mat4 u_model;
        varying vec3 v_color;
        varying float v_light;
        varying float v_depth;
        void main() {
            vec3 n = normalize((u_model * vec4(a_normal, 0.0)).xyz);
            v_light = 0.4 + 0.6 * max(dot(n, normalize(vec3(-0.35, 0.85, -0.45))), 0.0);
            v_color = a_color;
            gl_Position = u_viewProj * u_model * vec4(a_pos, 1.0);
            v_depth = gl_Position.w;
        }
        GLSL;

    // vertex colour x light (+ glow), fog towards the horizon colour, alpha for the shadow
    public const FRAGMENT = <<<'GLSL'
        precision mediump float;
        uniform vec3 u_fog;
        uniform vec3 u_glow;
        uniform float u_alpha;
        varying vec3 v_color;
        varying float v_light;
        varying float v_depth;
        void main() {
            vec3 color = v_color * v_light + u_glow;
            float fog = clamp((v_depth - 14.0) / 32.0, 0.0, 1.0);
            gl_FragColor = vec4(mix(color, u_fog, fog * fog), u_alpha);
        }
        GLSL;

    // the sky: a gradient and a striped sun on the horizon (a full-screen quad, behind everything)
    public const SKY_VERTEX = <<<'GLSL'
        attribute vec2 a_pos;
        varying vec2 v_uv;
        void main() {
            v_uv = a_pos * 0.5 + 0.5;
            gl_Position = vec4(a_pos, 0.999, 1.0);
        }
        GLSL;

    public const SKY_FRAGMENT = <<<'GLSL'
        precision mediump float;
        uniform vec3 u_top;
        uniform vec3 u_bottom;
        uniform vec3 u_sun;
        uniform float u_aspect;
        uniform float u_time;
        varying vec2 v_uv;
        void main() {
            vec3 color = mix(u_bottom, u_top, smoothstep(0.45, 1.0, v_uv.y));
            vec2 d = vec2((v_uv.x - 0.5) * u_aspect, v_uv.y - 0.58);
            float r = length(d);
            float stripes = step(0.45, fract(v_uv.y * 38.0 - u_time * 0.4)) + step(0.0, d.y);
            if (r < 0.2 && stripes > 0.5) {
                color = mix(u_sun, vec3(1.0, 0.95, 0.6), clamp(d.y * 4.0 + 0.4, 0.0, 1.0));
            }
            color += u_sun * 0.25 * exp(-r * 6.0);
            gl_FragColor = vec4(color, 1.0);
        }
        GLSL;

    /** Post-processing of the whole picture: a soft vignette and a light scanline. */
    public const POST = <<<'GLSL'
        void main() {
            vec3 color = texture2D(u_frame, v_uv).rgb;
            vec2 d = v_uv - 0.5;
            color *= 1.0 - dot(d, d) * 0.7;
            float line = sin(v_uv.y * u_view.y * 3.14159 * u_resolution.y / u_viewport.w);
            color *= 0.9 + 0.1 * line * line;
            gl_FragColor = vec4(color * 1.08, 1.0);
        }
        GLSL;

    /** Colours of each zone (0..255): floor a / b, block, sky top, horizon, sun, ball. */
    public const ZONES = [
        ['a' => [40, 190, 220], 'b' => [25, 120, 170], 'block' => [255, 80, 110], 'top' => [8, 6, 34], 'bottom' => [90, 30, 120], 'sun' => [255, 110, 60]],
        ['a' => [220, 80, 190], 'b' => [140, 50, 150], 'block' => [255, 200, 50], 'top' => [20, 4, 28], 'bottom' => [150, 40, 80], 'sun' => [255, 170, 40]],
        ['a' => [130, 220, 70], 'b' => [70, 150, 60], 'block' => [90, 120, 255], 'top' => [4, 18, 28], 'bottom' => [30, 100, 110], 'sun' => [230, 255, 120]],
        ['a' => [240, 160, 60], 'b' => [190, 100, 40], 'block' => [130, 60, 220], 'top' => [30, 8, 10], 'bottom' => [160, 70, 40], 'sun' => [255, 230, 120]],
    ];

    /** @return array<string, int[]> */
    public static function zone(int $row): array
    {
        return self::ZONES[intdiv(max(0, $row), self::ZONE) % count(self::ZONES)];
    }

    /**
     * Sky colours at $z: they blend into the next zone during its last 30 metres.
     * @return array{0: int[], 1: int[], 2: int[]} top, bottom, sun (0..255)
     */
    public static function sky(float $z): array
    {
        $row = (int)floor($z);
        $a = self::zone($row);
        $b = self::zone($row + self::ZONE);
        $t = max(0.0, min(1.0, (fmod(max(0.0, $z), self::ZONE) - (self::ZONE - 30)) / 30));
        $mix = fn (array $p, array $q) => [
            (int)round($p[0] + ($q[0] - $p[0]) * $t), (int)round($p[1] + ($q[1] - $p[1]) * $t), (int)round($p[2] + ($q[2] - $p[2]) * $t),
        ];
        return [$mix($a['top'], $b['top']), $mix($a['bottom'], $b['bottom']), $mix($a['sun'], $b['sun'])];
    }

    /** @param int[] $rgb @return float[] */
    public static function unit(array $rgb, float $factor = 1.0): array
    {
        return [$rgb[0] / 255 * $factor, $rgb[1] / 255 * $factor, $rgb[2] / 255 * $factor];
    }

    /**
     * The tiles of rows [$from, $from + $count) in one mesh: tops, plus the front and side faces that can be
     * seen (beside a hole or a lower cell).
     * @return float[]
     */
    public static function chunk(Track $track, int $from, int $count): array
    {
        $out = [];
        for ($row = $from; $row < $from + $count; $row++) {
            $zone = self::zone($row);
            for ($lane = 0; $lane < Track::LANES; $lane++) {
                $h = $track->height($row, $lane);
                if ($h === null) {
                    continue;
                }
                $block = $h > 0;
                $top = self::unit($block ? $zone['block'] : (($row + $lane) % 2 ? $zone['a'] : $zone['b']));
                $edge = [min(1.0, $top[0] * 1.25 + 0.1), min(1.0, $top[1] * 1.25 + 0.1), min(1.0, $top[2] * 1.25 + 0.1)];
                $side = [$top[0] * 0.55, $top[1] * 0.55, $top[2] * 0.55];
                [$x0, $x1, $z0, $z1, $y0] = [$lane - 2.47, $lane - 1.53, $row + 0.03, $row + 0.97, -0.5];
                self::quad($out, [$x0, $h, $z0], [$x1, $h, $z0], [$x1, $h, $z1], [$x0, $h, $z1], $top, [0, 1, 0]);
                // a bright strip on the front edge: the neon look
                self::quad($out, [$x0, $h + 0.002, $z0], [$x1, $h + 0.002, $z0], [$x1, $h + 0.002, $z0 + 0.06], [$x0, $h + 0.002, $z0 + 0.06], $edge, [0, 1, 0]);
                if (($track->height($row - 1, $lane) ?? -9.0) < $h) {
                    self::quad($out, [$x0, $y0, $z0], [$x1, $y0, $z0], [$x1, $h, $z0], [$x0, $h, $z0], $side, [0, 0, -1]);
                }
                if (($track->height($row, $lane - 1) ?? -9.0) < $h) {
                    self::quad($out, [$x0, $y0, $z0], [$x0, $h, $z0], [$x0, $h, $z1], [$x0, $y0, $z1], $side, [-1, 0, 0]);
                }
                if (($track->height($row, $lane + 1) ?? -9.0) < $h) {
                    self::quad($out, [$x1, $y0, $z0], [$x1, $h, $z0], [$x1, $h, $z1], [$x1, $y0, $z1], $side, [1, 0, 0]);
                }
            }
        }
        return $out;
    }

    /**
     * A sphere of radius 1, in coloured segments (a beach ball: the roll is visible).
     * @param int[] $a @param int[] $b
     * @return float[]
     */
    public static function sphere(array $a, array $b, int $rings = 10, int $segments = 14): array
    {
        $point = function (int $ring, int $seg) use ($rings, $segments): array {
            $theta = M_PI * $ring / $rings;
            $phi = 2 * M_PI * $seg / $segments;
            return [cos($theta), sin($theta) * cos($phi), sin($theta) * sin($phi)];    // poles on the x axis: it rolls around x
        };
        $out = [];
        for ($ring = 0; $ring < $rings; $ring++) {
            for ($seg = 0; $seg < $segments; $seg++) {
                $color = self::unit(intdiv($seg, 2) % 2 ? $a : $b);
                $p = [$point($ring, $seg), $point($ring + 1, $seg), $point($ring + 1, $seg + 1), $point($ring, $seg + 1)];
                foreach ([0, 1, 2, 0, 2, 3] as $i) {
                    array_push($out, ...$p[$i], ...$color, ...$p[$i]);
                }
            }
        }
        return $out;
    }

    /** A gem: an octahedron of radius 1. @param int[] $rgb @return float[] */
    public static function gem(array $rgb): array
    {
        $tips = [[0, 1.3, 0], [0, -1.3, 0]];
        $ring = [[1, 0, 0], [0, 0, 1], [-1, 0, 0], [0, 0, -1]];
        $out = [];
        foreach ($tips as $t => $tip) {
            for ($i = 0; $i < 4; $i++) {
                $a = $ring[$i];
                $b = $ring[($i + 1) % 4];
                $n = [($a[0] + $b[0]) / 2, $t === 0 ? 0.7 : -0.7, ($a[2] + $b[2]) / 2];
                $color = self::unit($rgb, $i % 2 ? 1.0 : 0.8);
                foreach ([$tip, $a, $b] as $p) {
                    array_push($out, ...$p, ...$color, ...$n);
                }
            }
        }
        return $out;
    }

    /** A flat disc of radius 1 at y = 0 (the shadow of the ball). @return float[] */
    public static function disc(int $segments = 16): array
    {
        $out = [];
        for ($i = 0; $i < $segments; $i++) {
            $a = 2 * M_PI * $i / $segments;
            $b = 2 * M_PI * ($i + 1) / $segments;
            foreach ([[0, 0], [cos($a), sin($a)], [cos($b), sin($b)]] as [$x, $z]) {
                array_push($out, $x, 0.0, $z, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0);
            }
        }
        return $out;
    }

    /**
     * @param array<int, mixed> $out
     * @param float[] $a @param float[] $b @param float[] $c @param float[] $d corners in order
     * @param float[] $color @param int[] $normal
     */
    private static function quad(array &$out, array $a, array $b, array $c, array $d, array $color, array $normal): void
    {
        foreach ([$a, $b, $c, $a, $c, $d] as $p) {
            array_push($out, $p[0], $p[1], $p[2], $color[0], $color[1], $color[2], $normal[0], $normal[1], $normal[2]);
        }
    }
}
