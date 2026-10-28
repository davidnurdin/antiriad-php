<?php

namespace App\Scene;

use App\Gfx\Shaders;
use JumPHP\Gl\Mat4;
use JumPHP\Gl\WebGl;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * Cubes turning over a floor. Browser: real 3D with WebGL (a layer behind the 2D: the HUD is plain SDL drawing),
 * plus a post-processing shader chosen with Space (OpenGL on the desktop, WebGL in the browser: the same calls).
 * Without OpenGL (headless with JUMPHP_GL=0, no GPU): the same scene in software wireframe,
 * with the same matrices (Mat4::project).
 */
final class CubesScene extends AbstractScene
{
    /** The 2D colour where the 3D layer shows (WebGl::setLayer). */
    private const KEY = [255, 0, 255];
    /** Sky colour (0..1, as GLSL wants it). */
    private const SKY = [0.09, 0.1, 0.26];
    private const TINTS = [[1.0, 0.45, 0.4], [0.45, 1.0, 0.5], [0.45, 0.6, 1.0], [1.0, 0.9, 0.35], [0.9, 0.5, 1.0]];

    private float $time = 0.0;
    private float $angle = 0.0;
    private float $distance = 7.0;
    private int $shader = 0;
    private bool $demo = false;
    private float $demoClock = 0.0;
    private ?string $glError = null;
    /** @var array<string, int> WebGL resource ids */
    private array $ids = [];

    public function enter(array $params = []): void
    {
        $this->time = $this->angle = $this->demoClock = 0.0;
        $this->distance = 7.0;
        $this->shader = 0;
        $this->demo = (bool)($params['demo'] ?? false);
        $this->glError = null;

        $gl = $this->gl();
        if ($gl->isAvailable()) {
            $gl->removeAllListeners('error');
            $gl->on('error', fn (string $message) => $this->glError = $message);
            if ($this->ids === []) {     // resources live as long as the page: created once
                $this->ids = [
                    'program' => $gl->program(Shaders::VERTEX, Shaders::FRAGMENT),
                    'cube' => $gl->buffer(Shaders::cube()),
                    'floor' => $gl->buffer(Shaders::floor(40, 20)),
                    'cubeTex' => $gl->textureFromRgba(16, 16, Shaders::checker([230, 230, 230], [150, 150, 160])),
                    'floorTex' => $gl->textureFromRgba(16, 16, Shaders::checker([40, 50, 90], [25, 30, 60], [90, 110, 190])),
                ];
            }
            // the 3D at the game's resolution (big pixels), behind the 2D
            $gl->setLayer('below', 'view', self::KEY);
            $this->applyShader();
        }
    }

    public function exit(): void
    {
        $this->gl()->disable();
    }

    public function update(float $dt): void
    {
        if ($this->actions()->isPressed('pause')) {
            $this->scenes()->push(PauseScene::class);
            return;
        }
        $this->time += $dt;
        // keys / d-pad / left stick (actions), plus the analog right stick and triggers of a gamepad
        $pad = $this->engine->input->getGamepad();
        $turn = $this->actions()->axis('left', 'right') + $pad->axis(0, 'rx');
        $zoom = $this->actions()->axis('up', 'down') + $pad->axis(0, 'rt') - $pad->axis(0, 'lt');
        $this->angle += $turn * 1.8 * $dt + 0.2 * $dt;
        $this->distance = max(3.5, min(14.0, $this->distance + $zoom * 6 * $dt));
        if ($this->demo) {
            $this->demoClock += $dt;
            if ($this->demoClock > 2.5) {
                $this->demoClock = 0.0;
                $this->nextShader();
            }
        }
        if ($this->actions()->isPressed('next')) {
            $this->nextShader();
            $this->sound()->hasSfx('menu_move') && $this->sound()->playSfx('menu_move');
            $this->engine->input->getGamepad()->rumble(0, 0.6, 0.3, 0.12);
            $this->engine->device()->vibrate(30);
        }
    }

    private function nextShader(): void
    {
        $this->shader = ($this->shader + 1) % count(Shaders::POST);
        $this->applyShader();
    }

    private function applyShader(): void
    {
        $source = array_values(Shaders::POST)[$this->shader];
        $this->gl()->setPostShader($source, ['u_dark' => [0.06, 0.12, 0.06], 'u_light' => [0.75, 0.9, 0.45]]);
    }

    /** @return array{0: float[], 1: float[][]} view-projection, and the model matrices of the cubes */
    private function scene(): array
    {
        $eye = [sin($this->angle) * $this->distance, 2.6, cos($this->angle) * $this->distance];
        $viewProj = Mat4::multiply(
            Mat4::perspective(60, $this->width() / $this->height(), 0.1, 60),
            Mat4::lookAt($eye, [0, 0.6, 0]),
        );
        $models = [];
        foreach (self::TINTS as $i => $tint) {
            $a = $this->time * 0.6 + $i * 2 * M_PI / count(self::TINTS);
            $models[] = Mat4::multiply(
                Mat4::translate(cos($a) * 2.6, 0.9 + sin($this->time * 2 + $i) * 0.35, sin($a) * 2.6),
                Mat4::rotateY($this->time * (1 + $i * 0.2)),
                Mat4::rotateX($this->time * 0.7 + $i),
                Mat4::scale(1.1),
            );
        }
        return [$viewProj, $models];
    }

    public function render(Renderer $r): void
    {
        [$viewProj, $models] = $this->scene();
        $gl = $this->gl();
        if ($gl->isAvailable()) {
            $r->clear(self::KEY);                  // the whole view shows the 3D layer...
            $this->draw3d($gl, $viewProj, $models);
        } else {
            $r->clear([10, 12, 34]);
            $this->drawWireframe($r, $viewProj, $models);
        }
        $this->drawHud($r);                        // ...except where the 2D draws
    }

    /** @param float[] $viewProj @param float[][] $models */
    private function draw3d(WebGl $gl, array $viewProj, array $models): void
    {
        $gl->clear(array_map(fn (float $v) => (int)round($v * 255), self::SKY));   // colours: 0..255, like everywhere
        $layout = fn (int $buffer) => ['a_pos' => [$buffer, 3, Shaders::STRIDE, 0], 'a_uv' => [$buffer, 2, Shaders::STRIDE, 3],
            'a_shade' => [$buffer, 1, Shaders::STRIDE, 5]];
        $gl->draw($this->ids['program'], [
            'attributes' => $layout($this->ids['floor']),
            'uniforms' => ['u_mvp' => $viewProj, 'u_tint' => [1, 1, 1], 'u_fog' => self::SKY],
            'textures' => ['u_tex' => $this->ids['floorTex']],
        ]);
        foreach ($models as $i => $model) {
            $gl->draw($this->ids['program'], [
                'attributes' => $layout($this->ids['cube']),
                'uniforms' => ['u_mvp' => Mat4::multiply($viewProj, $model), 'u_tint' => self::TINTS[$i], 'u_fog' => self::SKY],
                'textures' => ['u_tex' => $this->ids['cubeTex']],
                'cull' => 'back',
            ]);
        }
    }

    /** Desktop: the same scene in lines. @param float[] $viewProj @param float[][] $models */
    private function drawWireframe(Renderer $r, array $viewProj, array $models): void
    {
        [$w, $h] = [$this->width(), $this->height()];
        for ($i = -10; $i <= 10; $i += 2) {
            $this->line3d($r, $viewProj, [$i, 0, -10], [$i, 0, 10], [40, 50, 110]);
            $this->line3d($r, $viewProj, [-10, 0, $i], [10, 0, $i], [40, 50, 110]);
        }
        $edges = [[0, 1], [1, 3], [3, 2], [2, 0], [4, 5], [5, 7], [7, 6], [6, 4], [0, 4], [1, 5], [2, 6], [3, 7]];
        foreach ($models as $i => $model) {
            $mvp = Mat4::multiply($viewProj, $model);
            $points = [];
            for ($c = 0; $c < 8; $c++) {
                $points[] = Mat4::project($mvp, [($c & 1) - 0.5, (($c >> 1) & 1) - 0.5, (($c >> 2) & 1) - 0.5], $w, $h);
            }
            $color = array_map(fn (float $v) => (int)min(255, $v * 255), self::TINTS[$i]);
            foreach ($edges as [$a, $b]) {
                if ($points[$a] !== null && $points[$b] !== null) {
                    $r->drawLine($points[$a][0], $points[$a][1], $points[$b][0], $points[$b][1], $color);
                }
            }
        }
    }

    /** @param float[] $mvp @param float[] $a @param float[] $b @param int[] $color */
    private function line3d(Renderer $r, array $mvp, array $a, array $b, array $color): void
    {
        $pa = Mat4::project($mvp, $a, $this->width(), $this->height());
        $pb = Mat4::project($mvp, $b, $this->width(), $this->height());
        if ($pa !== null && $pb !== null) {
            $r->drawLine($pa[0], $pa[1], $pb[0], $pb[1], $color);
        }
    }

    private function drawHud(Renderer $r): void
    {
        $font = $this->font();
        $gl = $this->gl();
        $r->fillRect(0, 0, $this->width(), 11, [0, 0, 0]);
        $font->draw($r, 'HELLO WEBGL', 3, 2, 1, [255, 210, 63]);
        $mode = $gl->isAvailable() ? 'WEBGL  SHADER: ' . array_keys(Shaders::POST)[$this->shader] : 'NO OPENGL: SOFTWARE 3D';
        $font->draw($r, $mode, $this->width() - 3, 2, 1, [200, 220, 255], 'right');
        $r->fillRect(0, $this->height() - 11, $this->width(), 11, [0, 0, 0]);
        $pad = $this->engine->input->getGamepad();
        $help = match (true) {
            $this->glError !== null => 'GL ERROR: SEE THE CONSOLE',
            $pad->count() > 0 => 'PAD: STICKS TURN  LT/RT ZOOM  A SHADER',
            default => 'ARROWS TURN/ZOOM  ' . $this->actions()->label('next') . ' SHADER',
        };
        $font->draw($r, $help, $this->width() / 2, $this->height() - 9, 1, $this->glError !== null ? [255, 90, 90] : [150, 150, 180], 'center');
    }

    private function gl(): WebGl
    {
        return $this->engine->gl();
    }
}
