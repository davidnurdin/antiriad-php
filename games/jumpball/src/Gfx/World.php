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
 * Browser: WebGL behind the 2D (the scenes clear with KEY, the HUD is plain SDL drawing over it). The track
 * is cut in chunks of 16 rows, one vertex buffer each, built once when the chunk comes into view and reused
 * for the next chunk when it leaves: a frame only sends a dozen draw commands.
 * Desktop (no WebGL): the same camera, drawn by SoftwareView.
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
                'ball' => $gl->buffer(Meshes::sphere([250, 250, 255], [255, 70, 90])),
                'gem' => $gl->buffer(Meshes::gem([255, 220, 60])),
                'shadow' => $gl->buffer(Meshes::disc()),
            ];
        }
        $gl->setLayer('below', 'view', self::KEY);
        $gl->setPostShader(Meshes::POST);
    }

    /** View-projection matrix of the camera behind the ball. @return float[] */
    public static function camera(Run $run, float $aspect): array
    {
        $shake = $run->state === 'crash' ? sin($run->time * 70) * 0.12 * max(0.0, 1 - $run->endTime * 2) : 0.0;
        $eye = [$run->x * 0.6 + $shake, $run->camY + 2.1, $run->z - 4.0];
        $target = [$run->x * 0.8, $run->camY + 0.1, $run->z + 6.0];
        // looking towards +z puts world +x on the left of the screen: mirror x so that right is right
        return Mat4::multiply(Mat4::perspective(62, $aspect, 0.1, 80), Mat4::scale(-1, 1, 1), Mat4::lookAt($eye, $target));
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
        $lit = fn (int $buffer, array $model, array $uniforms = [], bool $blend = false) => $gl->draw($this->ids['lit'], [
            'attributes' => ['a_pos' => [$buffer, 3, Meshes::STRIDE, 0], 'a_color' => [$buffer, 3, Meshes::STRIDE, 3],
                'a_normal' => [$buffer, 3, Meshes::STRIDE, 6]],
            // every uniform each time: a program keeps the values of its previous draw
            'uniforms' => $uniforms + ['u_viewProj' => $viewProj, 'u_model' => $model, 'u_fog' => $fog, 'u_glow' => [0, 0, 0], 'u_alpha' => 1.0],
            'blend' => $blend,
        ]);

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
                    ), ['u_glow' => [0.25, 0.2, 0.0]]);
                }
            }
        }

        $ground = $run->ground();
        if ($ground !== null && $run->state !== 'fall' && $run->y >= $ground - 0.05) {
            $size = Run::RADIUS * max(0.4, 1 - ($run->y - $ground) / 3);
            $lit($this->ids['shadow'], Mat4::multiply(Mat4::translate($run->x, $ground + 0.01, $run->z), Mat4::scale($size, 1, $size)),
                ['u_alpha' => 0.45], true);
        }
        $lit($this->ids['ball'], Mat4::multiply(
            Mat4::translate($run->x, $run->y + Run::RADIUS, $run->z),
            Mat4::rotateX($run->roll),
            Mat4::scale(Run::RADIUS),
        ));
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
