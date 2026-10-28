<?php

namespace App\Gfx;

use App\Game\Run;
use App\Game\Track;
use JumPHP\Gl\Mat4;
use JumPHP\Gl\WebGl;
use JumPHP\Kernel\Engine;
use JumPHP\Renderer\Renderer;

/**
 * Draws a Run in 3D (a service: one instance, shared by the title screen and the game).
 *
 * OpenGL (desktop) / WebGL (browser) behind the 2D (the scenes clear with KEY, the HUD is plain SDL drawing over it). The track
 * is cut in chunks of 16 rows, one vertex buffer each, built once when the chunk comes into view and reused
 * for the next chunk when it leaves: a frame only sends a dozen draw commands.
 * The layer is drawn at the window's resolution, supersampled (smooth edges), the HUD keeps its big pixels.
 * Particles: sparks when a gem is taken, a trail behind the ball when it goes fast.
 * Without OpenGL (JUMPHP_GL=0, no GPU): the same camera, drawn by SoftwareView.
 */
final class World
{
    /** The 2D colour where the 3D layer shows. */
    public const KEY = [255, 0, 255];
    private const CHUNK = 16;
    /** Rows drawn ahead of the ball. */
    public const AHEAD = 46;

    /** @var array<string, int> WebGL resources (they live as long as the page) */
    private array $ids = [];
    /** @var array<int, int> chunk index => buffer id */
    private array $chunks = [];
    /** @var int[] buffers ready for another chunk */
    private array $free = [];
    private int $trackSerial = 0;
    /** @var array<int, array{p: float[], v: float[], life: float, max: float, size: float, color: int}> */
    private array $particles = [];
    private float $lastTime = 0.0;
    private int $lastGems = 0;

    /** In the scenes' enter(): the layer, the post shader, the resources the first time. */
    public function prepare(WebGl $gl): void
    {
        if (!$gl->isAvailable()) {
            return;
        }
        if ($this->ids === []) {
            $this->ids = [
                'lit' => $gl->program(Meshes::VERTEX, Meshes::FRAGMENT),
                'sky' => $gl->program(Meshes::SKY_VERTEX, Meshes::SKY_FRAGMENT),
                'quad' => $gl->buffer([-1, -1, 1, -1, 1, 1, -1, -1, 1, 1, -1, 1]),
                'ball' => $gl->buffer(Meshes::sphere([250, 250, 255], [255, 60, 90])),
                'gem' => $gl->buffer(Meshes::gem([255, 220, 60])),
                'spark' => $gl->buffer(Meshes::gem([255, 240, 150])),
                'trail' => $gl->buffer(Meshes::gem([120, 220, 255])),
                'plane' => $gl->buffer(Meshes::plane()),
            ];
        }
        $gl->setLayer('below', 'native', self::KEY);
        $gl->setSupersample(2);
        $gl->setPostShader(Meshes::POST);
    }

    /** @return float[] the camera position */
    public static function eye(Run $run, float $shake = 0.0): array
    {
        return [$run->x * 0.6 + $shake, $run->camY + 2.1, $run->z - 4.0];
    }

    /** View-projection matrix of the camera behind the ball. @return float[] */
    public static function camera(Run $run, float $aspect): array
    {
        $shake = $run->state === 'crash' ? sin($run->time * 70) * 0.12 * max(0.0, 1 - $run->endTime * 2) : 0.0;
        $eye = self::eye($run, $shake);
        $target = [$run->x * 0.8, $run->camY + 0.1, $run->z + 6.0];
        // looking towards +z puts world +x on the left of the screen: mirror x so that right is right
        $fov = 60 + 8 * ($run->speed - Run::START_SPEED) / (Run::MAX_SPEED - Run::START_SPEED);    // wider when fast
        return Mat4::multiply(Mat4::perspective($fov, $aspect, 0.3, 80), Mat4::scale(-1, 1, 1), Mat4::lookAt($eye, $target));
    }

    public function render(Engine $engine, Renderer $r, Run $run): void
    {
        $viewProj = self::camera($run, $r->getWidth() / $r->getHeight());
        $sky = Meshes::sky($run->z);
        $gl = $engine->gl();
        if ($gl->isAvailable() && $this->ids !== []) {
            $r->clear(self::KEY);
            $this->draw3d($gl, $run, $viewProj, $sky, $r->getWidth() / $r->getHeight());
        } else {
            SoftwareView::draw($r, $run, $viewProj, $sky);
        }
    }

    /** @param float[] $viewProj @param array{0: int[], 1: int[], 2: int[]} $sky */
    private function draw3d(WebGl $gl, Run $run, array $viewProj, array $sky, float $aspect): void
    {
        [$top, $bottom, $sun] = $sky;
        $fog = Meshes::unit($bottom);
        $gl->clear($bottom);
        $gl->draw($this->ids['sky'], [
            'attributes' => ['a_pos' => [$this->ids['quad'], 2, 2, 0]],
            'uniforms' => ['u_top' => Meshes::unit($top), 'u_bottom' => $fog, 'u_sun' => Meshes::unit($sun),
                'u_aspect' => $aspect, 'u_time' => $run->time],
            'depth' => false,
        ]);
        // every uniform on each draw: a program keeps the values of its previous draw
        $common = [
            'u_viewProj' => $viewProj, 'u_eye' => self::eye($run), 'u_fog' => $fog, 'u_skyTop' => Meshes::unit($top),
            'u_sun' => Meshes::unit($sun), 'u_tint' => Meshes::unit(Meshes::zone($run->row())['a']),
            'u_ball' => [$run->x, $run->y, $run->z, Run::RADIUS], 'u_time' => $run->time,
            'u_glow' => [0, 0, 0], 'u_alpha' => 1.0, 'u_material' => 0.0,
        ];
        $lit = fn (int $buffer, array $model, array $uniforms = [], bool $blend = false) => $gl->draw($this->ids['lit'], [
            'attributes' => ['a_pos' => [$buffer, 3, Meshes::STRIDE, 0], 'a_color' => [$buffer, 3, Meshes::STRIDE, 3],
                'a_normal' => [$buffer, 3, Meshes::STRIDE, 6]],
            'uniforms' => ['u_model' => $model] + $uniforms + $common,
            'blend' => $blend,
        ]);

        // the grid far below: seen through the holes, and down to the horizon
        $lit($this->ids['plane'], Mat4::multiply(Mat4::translate(0, -7.0, $run->z + 30), Mat4::scale(70, 1, 60)), ['u_material' => 4.0]);

        $identity = Mat4::identity();
        foreach ($this->syncChunks($gl, $run) as $buffer) {
            $lit($buffer, $identity);
        }

        $row = $run->row();
        for ($i = max(0, $row - 2); $i < $row + 34; $i++) {
            for ($lane = 0; $lane < Track::LANES; $lane++) {
                if ($run->track->hasGem($i, $lane)) {
                    $bob = sin($run->time * 4 + $i) * 0.08;
                    $lit($this->ids['gem'], Mat4::multiply(
                        Mat4::translate($lane - 2, ($run->track->height($i, $lane) ?? 0.4) + 0.6 + $bob, $i + 0.5),
                        Mat4::rotateY($run->time * 3 + $i),
                        Mat4::scale(0.17),
                    ), ['u_material' => 2.0, 'u_glow' => [0.3, 0.22, 0.0]]);
                }
            }
        }

        $lit($this->ids['ball'], Mat4::multiply(
            Mat4::translate($run->x, $run->y + Run::RADIUS, $run->z),
            Mat4::rotateX($run->roll),
            Mat4::scale(Run::RADIUS),
        ), ['u_material' => 1.0]);

        $this->particles($run);
        foreach ($this->particles as $p) {
            $t = $p['life'] / $p['max'];
            $lit($this->ids[$p['color'] === 0 ? 'spark' : 'trail'], Mat4::multiply(
                Mat4::translate(...$p['p']),
                Mat4::rotateY($p['life'] * 9),
                Mat4::scale($p['size'] * (0.4 + 0.6 * $t)),
            ), ['u_material' => 3.0, 'u_alpha' => min(1.0, $t * 1.5)], true);
        }
    }

    /** Sparks of a gem taken, a trail when fast: moved with the run's clock (stops with it). */
    private function particles(Run $run): void
    {
        $dt = $run->time - $this->lastTime;
        $this->lastTime = $run->time;
        if ($dt < 0 || $dt > 0.5 || $run->gems < $this->lastGems) {      // another run
            [$this->particles, $dt] = [[], 0.0];
            $this->lastGems = $run->gems;
        }
        $centre = [$run->x, $run->y + Run::RADIUS, $run->z];
        if ($run->gems > $this->lastGems) {
            $this->lastGems = $run->gems;
            for ($i = 0; $i < 18; $i++) {
                $a = $i * 2.4;
                $this->particles[] = ['p' => $centre, 'v' => [cos($a) * 2.5, 2.5 + ($i % 5) * 0.8, sin($a) * 2.5 + $run->speed * 0.7],
                    'life' => 0.7, 'max' => 0.7, 'size' => 0.07, 'color' => 0];
            }
        }
        $fast = ($run->speed - Run::START_SPEED) / (Run::MAX_SPEED - Run::START_SPEED);
        if ($dt > 0 && $run->state === 'run' && $fast > 0.15 && count($this->particles) < 160) {
            $side = sin($run->time * 37) * Run::RADIUS * 0.8;
            $this->particles[] = ['p' => [$run->x + $side, $run->y + 0.08, $run->z - Run::RADIUS],
                'v' => [0.0, 0.4, 0.0], 'life' => 0.35, 'max' => 0.35, 'size' => 0.035 + 0.03 * $fast, 'color' => 1];
        }
        foreach ($this->particles as $k => &$p) {
            $p['life'] -= $dt;
            if ($p['life'] <= 0) {
                unset($this->particles[$k]);
                continue;
            }
            $p['v'][1] -= ($p['color'] === 0 ? 9.0 : 0.0) * $dt;
            for ($c = 0; $c < 3; $c++) {
                $p['p'][$c] += $p['v'][$c] * $dt;
            }
        }
        unset($p);
    }

    /** The chunk buffers around the ball, built when they come into view. @return int[] */
    private function syncChunks(WebGl $gl, Run $run): array
    {
        if ($run->track->serial !== $this->trackSerial) {      // another run: every chunk is out of date
            $this->trackSerial = $run->track->serial;
            array_push($this->free, ...array_values($this->chunks));
            $this->chunks = [];
        }
        $first = intdiv(max(0, $run->row() - 4), self::CHUNK);
        $last = intdiv($run->row() + self::AHEAD, self::CHUNK);
        foreach ($this->chunks as $c => $buffer) {
            if ($c < $first || $c > $last) {
                $this->free[] = $buffer;
                unset($this->chunks[$c]);
            }
        }
        for ($c = $first; $c <= $last; $c++) {
            if (!isset($this->chunks[$c])) {
                $data = Meshes::chunk($run->track, $c * self::CHUNK, self::CHUNK);
                $buffer = array_pop($this->free);
                if ($buffer === null) {
                    $buffer = $gl->buffer($data, true);
                } else {
                    $gl->updateBuffer($buffer, $data);
                }
                $this->chunks[$c] = $buffer;
            }
        }
        return array_values($this->chunks);
    }
}
