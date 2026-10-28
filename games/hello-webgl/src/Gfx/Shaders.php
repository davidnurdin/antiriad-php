<?php

namespace App\Gfx;

/**
 * GLSL sources (WebGL 1 / GLSL ES 1.00) and the meshes of the demo.
 */
final class Shaders
{
    /** Vertex layout of the meshes: x y z, u v, shade (6 floats). */
    public const STRIDE = 6;

    public const VERTEX = <<<'GLSL'
        attribute vec3 a_pos;
        attribute vec2 a_uv;
        attribute float a_shade;
        uniform mat4 u_mvp;
        varying vec2 v_uv;
        varying float v_shade;
        varying float v_depth;
        void main() {
            v_uv = a_uv;
            v_shade = a_shade;
            gl_Position = u_mvp * vec4(a_pos, 1.0);
            v_depth = gl_Position.w;
        }
        GLSL;

    // texture x tint x face light, with a fog towards the sky colour
    public const FRAGMENT = <<<'GLSL'
        precision mediump float;
        uniform sampler2D u_tex;
        uniform vec3 u_tint;
        uniform vec3 u_fog;
        varying vec2 v_uv;
        varying float v_shade;
        varying float v_depth;
        void main() {
            vec3 color = texture2D(u_tex, v_uv).rgb * u_tint * v_shade;
            float fog = clamp((v_depth - 6.0) / 14.0, 0.0, 1.0);
            gl_FragColor = vec4(mix(color, u_fog, fog), 1.0);
        }
        GLSL;

    /** Post-processing shaders: only main(), the engine adds u_frame, v_uv, u_time... (JumPHP\Gl\WebGl). */
    public const POST = [
        'NONE' => null,
        'CRT' => <<<'GLSL'
            void main() {
                vec2 c = v_uv * 2.0 - 1.0;
                vec2 uv = v_uv + c * dot(c, c) * 0.04;            // curved glass
                if (uv.x < 0.0 || uv.y < 0.0 || uv.x > 1.0 || uv.y > 1.0) { gl_FragColor = vec4(0.0, 0.0, 0.0, 1.0); return; }
                vec3 color = texture2D(u_frame, uv).rgb;
                float line = sin(uv.y * u_view.y * 3.14159 * u_resolution.y / u_viewport.w);
                color *= 0.75 + 0.25 * line * line;               // scanlines (one per game pixel row)
                color *= 1.0 - 0.35 * dot(c, c);                  // vignette
                gl_FragColor = vec4(color * 1.15, 1.0);
            }
            GLSL,
        'WAVE' => <<<'GLSL'
            void main() {
                vec2 uv = v_uv;
                uv.x += sin(uv.y * 18.0 + u_time * 3.0) * 0.006;
                uv.y += cos(uv.x * 12.0 + u_time * 2.0) * 0.004;
                gl_FragColor = vec4(texture2D(u_frame, uv).rgb, 1.0);
            }
            GLSL,
        '4 COLOURS' => <<<'GLSL'
            uniform vec3 u_dark;
            uniform vec3 u_light;
            void main() {
                vec3 color = texture2D(u_frame, v_uv).rgb;
                float l = dot(color, vec3(0.299, 0.587, 0.114));
                // ordered dither on game pixels, 4 levels
                vec2 p = floor((v_uv * u_resolution - u_viewport.xy) / u_viewport.zw * u_view);
                float d = mod(p.x + p.y * 2.0, 4.0) / 4.0 - 0.375;
                float level = floor(clamp(l + d * 0.25, 0.0, 0.999) * 4.0) / 3.0;
                gl_FragColor = vec4(mix(u_dark, u_light, level), 1.0);
            }
            GLSL,
    ];

    /**
     * A cube of size 1 centred on 0: 36 vertices, each face its own light.
     * @return float[]
     */
    public static function cube(): array
    {
        // faces: normal axis, sign, light
        $faces = [[0, 1, 0.85], [0, -1, 0.6], [1, 1, 1.0], [1, -1, 0.45], [2, 1, 0.75], [2, -1, 0.55]];
        $out = [];
        foreach ($faces as [$axis, $sign, $light]) {
            $corner = function (float $a, float $b) use ($axis, $sign): array {
                $p = [0.0, 0.0, 0.0];
                $p[$axis] = 0.5 * $sign;
                $p[($axis + 1) % 3] = $a * $sign;
                $p[($axis + 2) % 3] = $b;
                return $p;
            };
            $quad = [[-0.5, -0.5, 0, 1], [0.5, -0.5, 1, 1], [0.5, 0.5, 1, 0], [-0.5, -0.5, 0, 1], [0.5, 0.5, 1, 0], [-0.5, 0.5, 0, 0]];
            foreach ($quad as [$a, $b, $u, $v]) {
                array_push($out, ...[...$corner($a, $b), $u, $v, $light]);
            }
        }
        return $out;
    }

    /**
     * The floor: one big square at y = 0, its texture repeated $tiles times.
     * @return float[]
     */
    public static function floor(float $size, float $tiles): array
    {
        $s = $size / 2;
        $quad = [[-$s, -$s, 0, 0], [$s, -$s, $tiles, 0], [$s, $s, $tiles, $tiles], [-$s, -$s, 0, 0], [$s, $s, $tiles, $tiles], [-$s, $s, 0, $tiles]];
        $out = [];
        foreach ($quad as [$x, $z, $u, $v]) {
            array_push($out, $x, 0.0, $z, $u, $v, 0.9);
        }
        return $out;
    }

    /** 16 x 16 RGBA checker with a border: the texture of the cubes and the floor. */
    public static function checker(array $a, array $b, array $border = [255, 255, 255]): string
    {
        $out = '';
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $c = (($x >> 2) + ($y >> 2)) % 2 ? $a : $b;
                if ($x === 0 || $y === 0 || $x === 15 || $y === 15) {
                    $c = $border;
                }
                $out .= chr($c[0]) . chr($c[1]) . chr($c[2]) . chr(255);
            }
        }
        return $out;
    }
}
