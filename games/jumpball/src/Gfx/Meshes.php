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
        varying vec3 v_normal;
        varying vec3 v_world;
        void main() {
            vec4 world = u_model * vec4(a_pos, 1.0);
            v_world = world.xyz;
            v_normal = (u_model * vec4(a_normal, 0.0)).xyz;
            v_color = a_color;
            gl_Position = u_viewProj * world;
        }
        GLSL;

    /**
     * Per-pixel shading, one program for everything (u_material): 0 track (glossy dark tiles, neon borders,
     * a light wave, the ball's soft shadow and glow), 1 ball (shiny, reflects the sky), 2 gem, 3 particle
     * (flat, emissive), 4 the grid far below the track. Distance fog towards the horizon colour.
     * highp when the GPU has it: world coordinates go through fract().
     */
    public const FRAGMENT = <<<'GLSL'
        #ifdef GL_FRAGMENT_PRECISION_HIGH
        precision highp float;
        #else
        precision mediump float;
        #endif
        uniform vec3 u_eye;
        uniform vec3 u_fog;
        uniform vec3 u_skyTop;
        uniform vec3 u_sun;
        uniform vec3 u_tint;
        uniform vec3 u_glow;
        uniform vec4 u_ball;
        uniform float u_material;
        uniform float u_alpha;
        uniform float u_time;
        varying vec3 v_color;
        varying vec3 v_normal;
        varying vec3 v_world;

        // what a shiny surface reflects: the sky gradient and the sun ahead
        vec3 sky(vec3 r) {
            vec3 c = mix(u_fog, u_skyTop, clamp(r.y * 2.0, 0.0, 1.0));
            return c + u_sun * pow(max(dot(r, normalize(vec3(0.0, 0.1, 1.0))), 0.0), 16.0) * 1.3;
        }

        void main() {
            vec3 n = normalize(v_normal);
            vec3 toEye = u_eye - v_world;
            float dist = length(toEye);
            vec3 v = toEye / dist;
            vec3 l = normalize(vec3(-0.35, 0.8, -0.5));
            vec3 r = reflect(-v, n);
            float diffuse = max(dot(n, l), 0.0);
            float fresnel = pow(1.0 - max(dot(n, v), 0.0), 4.0);
            vec3 ambient = mix(u_fog * 0.5, u_skyTop + 0.12, n.y * 0.5 + 0.5);
            vec3 neonColor = v_color * 1.25 + 0.2;
            vec3 color;
            if (u_material < 0.5) {
                if (n.y > 0.5) {
                    vec2 t = fract(vec2(v_world.x + 0.5, v_world.z));
                    float e = min(min(t.x, 1.0 - t.x), min(t.y, 1.0 - t.y));
                    float neon = smoothstep(0.11, 0.035, e);
                    float inner = smoothstep(0.15, 0.175, e) * smoothstep(0.205, 0.18, e);
                    color = v_color * 0.32 * (ambient + diffuse) + sky(r) * (0.06 + 0.55 * fresnel);
                    color += neonColor * (neon * 1.35 + inner * 0.3);
                    float wave = pow(0.5 + 0.5 * sin(6.28318 * fract(v_world.z / 32.0 - u_time * 0.45)), 16.0);
                    color += v_color * wave * 0.35 * (1.0 - neon);
                } else {
                    float y = v_world.y;
                    float rim = max(smoothstep(0.06, 0.0, abs(y)), smoothstep(0.06, 0.0, abs(y - 1.0)));
                    color = v_color * (0.18 + 0.45 * diffuse) * (0.25 + 0.75 * clamp(y + 0.5, 0.0, 1.0));
                    color += neonColor * rim * 1.2;
                }
                // the ball: a soft shadow under it, some of its light around it
                float d = length(v_world.xz - u_ball.xz);
                float above = u_ball.y - v_world.y;
                if (above > -0.03) {
                    float spread = u_ball.w * (1.0 + above * 0.45);
                    float s = (1.0 - smoothstep(spread * 0.35, spread + 0.15, d)) * clamp(1.0 - above / 5.0, 0.0, 1.0);
                    color *= 1.0 - 0.75 * s;
                }
                color += vec3(1.0, 0.5, 0.55) * 0.16 / (1.0 + d * d * 3.0 + above * above * 3.0);
            } else if (u_material < 1.5) {
                vec3 h = normalize(l + v);
                float spec = pow(max(dot(n, h), 0.0), 70.0);
                color = v_color * (ambient * 0.45 + diffuse * 0.75) + sky(r) * (0.12 + 0.6 * fresnel) + vec3(spec * 1.4);
                color += vec3(0.35, 0.7, 1.0) * fresnel * 0.6;
            } else if (u_material < 2.5) {
                vec3 h = normalize(l + v);
                color = v_color * (0.7 + 0.6 * diffuse) + vec3(pow(max(dot(n, h), 0.0), 24.0)) + v_color * fresnel;
            } else if (u_material < 3.5) {
                color = v_color;
            } else {
                vec2 g = abs(fract(v_world.xz / 2.5) - 0.5);
                float line = smoothstep(0.45, 0.5, max(g.x, g.y)) * clamp(1.0 - dist / 48.0, 0.0, 1.0);
                color = u_fog * 0.3 + u_tint * line * 0.75;
            }
            color += u_glow;
            float fog = clamp((dist - 12.0) / 38.0, 0.0, 1.0);
            gl_FragColor = vec4(mix(color, u_fog, fog * fog), u_alpha);
        }
        GLSL;

    // the sky: a gradient, stars, a striped sun behind a mountain ridge (a full-screen quad, behind everything)
    public const SKY_VERTEX = <<<'GLSL'
        attribute vec2 a_pos;
        varying vec2 v_uv;
        void main() {
            v_uv = a_pos * 0.5 + 0.5;
            gl_Position = vec4(a_pos, 0.999, 1.0);
        }
        GLSL;

    public const SKY_FRAGMENT = <<<'GLSL'
        #ifdef GL_FRAGMENT_PRECISION_HIGH
        precision highp float;
        #else
        precision mediump float;
        #endif
        uniform vec3 u_top;
        uniform vec3 u_bottom;
        uniform vec3 u_sun;
        uniform float u_aspect;
        uniform float u_time;
        varying vec2 v_uv;

        float hash(vec2 p) {
            vec3 p3 = fract(vec3(p.xyx) * 0.1031);
            p3 += dot(p3, p3.yzx + 33.33);
            return fract((p3.x + p3.y) * p3.z);
        }

        void main() {
            vec2 uv = vec2(v_uv.x * u_aspect, v_uv.y);
            vec3 color = mix(u_bottom, u_top, smoothstep(0.55, 1.0, v_uv.y));
            // stars, twinkling
            vec2 cell = floor(uv * 70.0);
            float h = hash(cell);
            if (h > 0.965) {
                vec2 at = (cell + 0.25 + 0.5 * vec2(hash(cell + 7.0), hash(cell + 13.0))) / 70.0;
                float tw = 0.6 + 0.4 * sin(u_time * (1.5 + 3.0 * h) + h * 40.0);
                color += vec3(0.9, 0.9, 1.0) * tw * smoothstep(0.0035, 0.0, length(uv - at)) * smoothstep(0.68, 0.9, v_uv.y);
            }
            // the sun: stripes in its lower half, a large glow
            vec2 d = vec2((v_uv.x - 0.5) * u_aspect, v_uv.y - 0.655);
            float r = length(d);
            float stripes = step(0.42, fract(v_uv.y * 34.0 - u_time * 0.35)) + step(0.0, d.y + 0.03);
            float disc = smoothstep(0.205, 0.2, r) * min(stripes, 1.0);
            vec3 sunColor = mix(u_sun, vec3(1.0, 0.95, 0.65), clamp(d.y * 4.0 + 0.5, 0.0, 1.0));
            color = mix(color, sunColor * 1.15, disc);
            color += u_sun * (0.35 * exp(-r * 5.0) + 0.12 * exp(-r * 1.6));
            // mountains: a ridge of small sines, a neon line on top
            float x = v_uv.x * u_aspect;
            float ridge = 0.62 + 0.035 * sin(x * 6.0 + 1.0) + 0.02 * sin(x * 15.0 + 2.0) + 0.008 * sin(x * 37.0);
            if (v_uv.y < ridge) {
                color = mix(u_bottom, u_top, 0.35) * 0.55 + u_sun * 0.06 * smoothstep(ridge - 0.08, ridge, v_uv.y);
            }
            color += u_sun * 0.8 * smoothstep(0.004, 0.0, abs(v_uv.y - ridge));
            gl_FragColor = vec4(color, 1.0);
        }
        GLSL;

    /**
     * Post-processing of the whole picture (at the window's resolution): bloom (the bright pixels glow),
     * a slight colour fringe towards the edges, a soft vignette.
     */
    public const POST = <<<'GLSL'
        void main() {
            vec2 px = 1.0 / u_resolution;
            vec2 d = v_uv - 0.5;
            vec2 fringe = d * 0.003;
            vec3 color = vec3(texture2D(u_frame, v_uv - fringe).r, texture2D(u_frame, v_uv).g, texture2D(u_frame, v_uv + fringe).b);
            vec3 glow = vec3(0.0);
            float radius = u_resolution.y / 120.0;
            for (int i = 0; i < 12; i++) {
                float a = float(i) * 0.5236 + 0.26;
                vec2 dir = vec2(cos(a), sin(a)) * px * radius;
                glow += max(texture2D(u_frame, v_uv + dir).rgb - 0.5, 0.0);
                glow += max(texture2D(u_frame, v_uv + dir * 2.6).rgb - 0.5, 0.0) * 0.7;
            }
            color += glow * 0.11;
            color *= 1.0 - dot(d, d) * 0.85;
            gl_FragColor = vec4(color * 1.05, 1.0);
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
                $side = $top;
                [$x0, $x1, $z0, $z1, $y0] = [$lane - 2.47, $lane - 1.53, $row + 0.03, $row + 0.97, -0.5];
                self::quad($out, [$x0, $h, $z0], [$x1, $h, $z0], [$x1, $h, $z1], [$x0, $h, $z1], $top, [0, 1, 0]);
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
    public static function sphere(array $a, array $b, int $rings = 24, int $segments = 32): array
    {
        $point = function (int $ring, int $seg) use ($rings, $segments): array {
            $theta = M_PI * $ring / $rings;
            $phi = 2 * M_PI * $seg / $segments;
            return [cos($theta), sin($theta) * cos($phi), sin($theta) * sin($phi)];    // poles on the x axis: it rolls around x
        };
        $out = [];
        for ($ring = 0; $ring < $rings; $ring++) {
            for ($seg = 0; $seg < $segments; $seg++) {
                $color = self::unit(intdiv($seg, 4) % 2 ? $a : $b);
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

    /** A flat square -1..1 at y = 0 (the grid below the track). @return float[] */
    public static function plane(): array
    {
        $out = [];
        self::quad($out, [-1, 0, -1], [1, 0, -1], [1, 0, 1], [-1, 0, 1], [1, 1, 1], [0, 1, 0]);
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
