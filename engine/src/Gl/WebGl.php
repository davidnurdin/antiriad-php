<?php

namespace JumPHP\Gl;

use Evenement\EventEmitter;
use JumPHP\Bridge\JsBridge;
use JumPHP\Kernel\Platform;
use JumPHP\Renderer\Renderer;
use JumPHP\Util\Color;

/**
 * WebGL from PHP ($this->engine->gl()), in the browser: GLSL shaders, 3D, post-processing of the picture.
 *
 * The page keeps a second canvas over the game (engine/web/jumphp-gl.js). After each PHP frame it takes the
 * picture drawn by SDL, mixes it with a 3D layer drawn by the commands of this frame, then runs an optional
 * post-processing shader on the whole picture:
 *
 *   SDL picture (2D) ─┐
 *                     ├─ composite ─> post shader (optional) ─> screen
 *   3D layer ─────────┘   'below': the 3D shows through the 2D where the 2D has the key colour (HUD over 3D);
 *                                  a darker shade of the key shows a darker 3D (fades, translucent menus)
 *                         'above': the 3D is drawn over the 2D (its transparent pixels show the 2D)
 *
 * Resources are created once (enter()), they get an id; drawing is immediate: draw() in render(), every frame.
 *
 *   $gl = $this->engine->gl();
 *   if ($gl->isAvailable()) {
 *       $gl->setLayer('below', 'view', [255, 0, 255]);     // 3D at the virtual resolution, behind the 2D
 *       $this->prog = $gl->program($vertexGlsl, $fragmentGlsl);
 *       $this->cube = $gl->buffer($floats);                  // x y z r g b, x y z r g b...
 *   }
 *   // render():
 *   $r->clear([255, 0, 255]);                                // the key colour: here the 3D shows
 *   $gl->clear([0, 0, 0, 1]);
 *   $gl->draw($this->prog, [
 *       'attributes' => ['a_pos' => [$this->cube, 3, 6, 0], 'a_color' => [$this->cube, 3, 6, 3]],  // [buffer, size, stride, offset] in floats
 *       'uniforms' => ['u_mvp' => Mat4::multiply($projection, $model)],
 *       'count' => 36,
 *   ]);
 *   $gl->setPostShader('void main() { gl_FragColor = texture2D(u_frame, v_uv) * vec4(1.0, 0.9, 0.8, 1.0); }');
 *
 * GLSL: WebGL 1 (GLSL ES 1.00). A post shader gets: varying vec2 v_uv (0..1 over the canvas, y up),
 * uniform sampler2D u_frame (the picture), vec2 u_resolution (canvas pixels), vec4 u_viewport (where the game
 * view is: x, y, w, h in canvas pixels, y up), vec2 u_view (virtual size), float u_time (game seconds), plus
 * its own uniforms. These declarations and `precision mediump float;` are added before the source.
 *
 * Desktop: there is no WebGL (no OpenGL binding in PHP SDL): isAvailable() is false and every call does
 * nothing. A game draws a 2D fallback then (see games/hello-webgl, Mat4 also works for software 3D).
 *
 * Events: 'error' (string $message) when a shader does not compile / link (also written to the console).
 */
class WebGl extends EventEmitter
{
    public const FILE = '/tmp/jumphp-gl';
    private const MODES = ['points', 'lines', 'line_strip', 'line_loop', 'triangles', 'triangle_strip', 'triangle_fan'];

    private int $nextId = 1;
    /** @var array<int, array> commands of this frame */
    private array $commands = [];
    private bool $active = false;

    public function __construct(private JsBridge $bridge)
    {
        $bridge->on('gl', function (array $e): void {
            if (isset($e['error'])) {
                $message = (string)$e['error'];
                \JumPHP\Kernel\Application::stderr("[webgl] $message\n");
                $this->emit('error', [$message]);
            }
        });
    }

    /** True in a browser with WebGL. */
    public function isAvailable(): bool
    {
        return Platform::isWasm() && (bool)Platform::setting('JUMPHP_WEBGL', false);
    }

    // --- layer and post-processing -------------------------------------------------------------

    /**
     * Turns the 3D layer on.
     * @param string $position 'below' (2D over 3D, through the key colour) or 'above' (3D over the 2D)
     * @param string $resolution 'view' (game.yaml size: big retro pixels) or 'native' (screen pixels: smooth)
     * @param Color|array|string $key 'below' only: the 2D colour that shows the 3D (exact match)
     */
    public function setLayer(string $position = 'below', string $resolution = 'view', Color|array|string $key = [255, 0, 255]): void
    {
        if (!in_array($position, ['below', 'above'], true) || !in_array($resolution, ['view', 'native'], true)) {
            throw new \InvalidArgumentException("setLayer: position below|above, resolution view|native");
        }
        $c = Color::of($key);
        $this->command(['c' => 'layer', 'position' => $position, 'resolution' => $resolution, 'key' => [$c->r, $c->g, $c->b]]);
    }

    public function removeLayer(): void
    {
        $this->command(['c' => 'layer', 'position' => null]);
    }

    /**
     * Post-processing of the whole picture (null: none). Only the body: see the class comment for the inputs.
     * @param array<string, float|int|array> $uniforms its own uniforms (setUniform() changes them)
     */
    public function setPostShader(?string $fragment, array $uniforms = []): void
    {
        $this->command(['c' => 'post', 'fs' => $fragment, 'uniforms' => (object)$this->uniforms($uniforms)]);
    }

    /** A uniform of the post shader. */
    public function setUniform(string $name, float|int|array $value): void
    {
        $this->command(['c' => 'postUniform', 'name' => $name, 'value' => $this->uniformValue($value)]);
    }

    /** Back to plain SDL output: no layer, no post shader, resources kept. */
    public function disable(): void
    {
        $this->command(['c' => 'disable']);
    }

    // --- resources ------------------------------------------------------------------------------

    /** A shader program (GLSL ES 1.00, full sources). Errors: 'error' event. @return int id */
    public function program(string $vertex, string $fragment): int
    {
        return $this->create(['c' => 'program', 'vs' => $vertex, 'fs' => $fragment]);
    }

    /** @param float[] $data vertex data (Float32) @return int id */
    public function buffer(array $data, bool $dynamic = false): int
    {
        return $this->create(['c' => 'buffer', 'data' => self::floats($data), 'dynamic' => $dynamic]);
    }

    /** @param int[] $indices (Uint16: at most 65535 vertices) @return int id */
    public function indexBuffer(array $indices): int
    {
        return $this->create(['c' => 'buffer', 'data' => array_map('intval', array_values($indices)), 'index' => true]);
    }

    /** New data for a buffer (same kind). */
    public function updateBuffer(int $id, array $data): void
    {
        $this->command(['c' => 'update', 'id' => $id, 'data' => self::floats($data)]);
    }

    /**
     * A texture from an image of the game (png, jpg), loaded by the browser in the background (transparent
     * until then). Power-of-two sizes may repeat; others are clamped.
     * @param string $path relative to the game root: 'assets/textures/crate.png'
     * @return int id
     */
    public function texture(string $path, bool $smooth = false, bool $repeat = false): int
    {
        return $this->create(['c' => 'texture', 'path' => '/game/' . ltrim($path, '/'), 'smooth' => $smooth, 'repeat' => $repeat]);
    }

    /**
     * A texture from raw RGBA bytes (strlen = w * h * 4): procedural pictures, GD output (see rgbaFromGd()).
     * Give the same id again to replace its pixels. @return int id
     */
    public function textureFromRgba(int $width, int $height, string $rgba, bool $smooth = false, ?int $id = null): int
    {
        if (strlen($rgba) !== $width * $height * 4) {
            throw new \InvalidArgumentException('textureFromRgba: ' . strlen($rgba) . " bytes for {$width}x{$height}");
        }
        $command = ['c' => 'rgba', 'w' => $width, 'h' => $height, 'data' => base64_encode($rgba), 'smooth' => $smooth];
        if ($id !== null) {
            $this->command(['id' => $id] + $command);
            return $id;
        }
        return $this->create($command);
    }

    /** RGBA bytes of a GD image, for textureFromRgba(). */
    public static function rgbaFromGd(\GdImage $image): string
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $out = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($image, $x, $y);
                $alpha = ($c >> 24) & 0x7F;
                $out .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF) . chr(255 - (int)round($alpha * 255 / 127));
            }
        }
        return $out;
    }

    public function delete(int $id): void
    {
        $this->command(['c' => 'delete', 'id' => $id]);
    }

    // --- drawing (immediate: every frame) ---------------------------------------------------------

    /** Clears the 3D layer. @param Color|array|string $color alpha 0 = transparent ('above' layers) */
    public function clear(Color|array|string $color = [0, 0, 0, 0], bool $depth = true): void
    {
        $c = Color::of($color);
        $this->command(['c' => 'clear', 'color' => [$c->r / 255, $c->g / 255, $c->b / 255, $c->a / 255], 'depth' => $depth]);
    }

    /**
     * Draws with a program into the 3D layer. $options:
     *   attributes  name => [buffer, size, stride, offset] (stride / offset in floats; 0 = packed)
     *   uniforms    name => float, vecN, mat3 / mat4 (16 floats, column-major: Mat4)
     *   textures    sampler name => texture id
     *   indices     index buffer id; count, first: vertices (count 0 = all)
     *   mode        triangles (default), lines, line_strip, line_loop, points, triangle_strip, triangle_fan
     *   depth       depth test (default true); cull 'back' | 'front' | null (default); blend (alpha, default false)
     * @param array<string, mixed> $options
     */
    public function draw(int $program, array $options): void
    {
        $mode = $options['mode'] ?? 'triangles';
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException("draw: mode '$mode' (" . implode(', ', self::MODES) . ')');
        }
        $attributes = [];
        foreach ($options['attributes'] ?? [] as $name => $a) {
            $attributes[$name] = [(int)$a[0], (int)$a[1], (int)($a[2] ?? 0), (int)($a[3] ?? 0)];
        }
        $this->command([
            'c' => 'draw', 'program' => $program, 'mode' => $mode,
            'attributes' => (object)$attributes,
            'uniforms' => (object)$this->uniforms($options['uniforms'] ?? []),
            'textures' => (object)array_map('intval', $options['textures'] ?? []),
            'indices' => isset($options['indices']) ? (int)$options['indices'] : null,
            'count' => (int)($options['count'] ?? 0), 'first' => (int)($options['first'] ?? 0),
            'depth' => (bool)($options['depth'] ?? true), 'cull' => $options['cull'] ?? null, 'blend' => (bool)($options['blend'] ?? false),
        ]);
    }

    /**
     * @internal Engine: after the frame is drawn, the commands go to the page (engine/web/jumphp-gl.js reads
     * them right after the PHP frame).
     */
    public function flush(Renderer $renderer, float $time): void
    {
        if (!Platform::isWasm() || (!$this->active && $this->commands === [])) {
            $this->commands = [];
            return;
        }
        [$ow, $oh] = $renderer->outputSize();
        $frame = [
            'cmds' => $this->commands,
            'lb' => $renderer->letterbox(),
            'out' => [$ow, $oh],
            'view' => [$renderer->getWidth(), $renderer->getHeight()],
            'time' => $time,
        ];
        $this->commands = [];
        file_put_contents(self::FILE, json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    // ---------------------------------------------------------------------------------------------

    private function create(array $command): int
    {
        $id = $this->nextId++;
        $this->command(['id' => $id] + $command);
        return $id;
    }

    private function command(array $command): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        if ($command['c'] === 'layer' || $command['c'] === 'post') {
            $this->active = $this->active || ($command['position'] ?? $command['fs'] ?? null) !== null;
        } elseif ($command['c'] === 'disable') {
            $this->active = false;
        }
        $this->commands[] = $command;
    }

    /** @return array<string, float[]> */
    private function uniforms(array $uniforms): array
    {
        $out = [];
        foreach ($uniforms as $name => $value) {
            $out[$name] = $this->uniformValue($value);
        }
        return $out;
    }

    /** @return float[] */
    private function uniformValue(float|int|array $value): array
    {
        return is_array($value) ? self::floats($value) : [(float)$value];
    }

    /** @return float[] */
    private static function floats(array $data): array
    {
        $out = [];
        foreach ($data as $v) {
            $out[] = (float)$v;
        }
        return $out;
    }
}
