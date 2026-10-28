<?php

namespace App\Scene;

use JumPHP\Audio\Synth;
use JumPHP\Gl\Mat4;
use JumPHP\Gl\OpenGl;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * Raw OpenGL: the PHP-OpenGL functions themselves (glUseProgram, glBindBuffer, glDrawArrays...) between
 * OpenGl::begin() and end(), in the same GL context as SDL's 2D (WebGL in the browser), and a positional
 * sound with OpenAL (Audio3d): the beeping cube is heard where it is, left or right.
 */
final class RawGlScene extends AbstractScene
{
    private const VERTEX = <<<'GLSL'
        attribute vec3 a_pos;
        attribute vec3 a_normal;
        uniform mat4 u_mvp;
        uniform mat4 u_model;
        varying vec3 v_normal;
        varying vec3 v_pos;
        void main() {
            v_normal = (u_model * vec4(a_normal, 0.0)).xyz;
            v_pos = (u_model * vec4(a_pos, 1.0)).xyz;
            gl_Position = u_mvp * vec4(a_pos, 1.0);
        }
        GLSL;

    // per-pixel light: diffuse + specular, a rim of colour
    private const FRAGMENT = <<<'GLSL'
        precision mediump float;
        uniform vec3 u_color;
        uniform vec3 u_eye;
        varying vec3 v_normal;
        varying vec3 v_pos;
        void main() {
            vec3 n = normalize(v_normal);
            vec3 l = normalize(vec3(0.6, 1.0, 0.4));
            vec3 v = normalize(u_eye - v_pos);
            float diffuse = max(dot(n, l), 0.0);
            float specular = pow(max(dot(reflect(-l, n), v), 0.0), 24.0);
            float rim = pow(1.0 - max(dot(n, v), 0.0), 3.0);
            gl_FragColor = vec4(u_color * (0.18 + 0.82 * diffuse) + vec3(specular) + vec3(0.3, 0.5, 1.0) * rim, 1.0);
        }
        GLSL;

    private float $time = 0.0;
    private int $program = 0;
    private int $torus = 0;
    private int $torusCount = 0;
    private int $cube = 0;
    private int $voice = 0;

    public function enter(array $params = []): void
    {
        $this->time = 0.0;
        $this->engine->gl()->disable();          // this scene draws its 3D itself
        $gl = $this->engine->opengl();
        if ($gl->isAvailable() && $this->program === 0) {
            $this->program = $gl->program(self::VERTEX, self::FRAGMENT, ['a_pos' => 0, 'a_normal' => 1]);
            $torus = self::torus(1.0, 0.38, 48, 20);
            $this->torusCount = intdiv(count($torus), 6);
            $this->torus = $gl->buffer($torus);
            $this->cube = $gl->buffer(self::cube());
        }
        $audio = $this->engine->audio3d();
        if ($audio->isAvailable() && !$audio->has('beep')) {
            $audio->synth('beep', Synth::sequence([[880, 0.08], [0, 0.25], [660, 0.08], [0, 0.25]], 'triangle'));
        }
        $this->voice = $audio->play('beep', [0, 0, -4], 0.8, 1.0, true);
    }

    public function exit(): void
    {
        $this->engine->audio3d()->stop($this->voice);
    }

    public function update(float $dt): void
    {
        if ($this->actions()->isPressed('back') || $this->actions()->isPressed('next')) {
            $this->scenes()->switchTo(TitleScene::class);
            return;
        }
        $this->time += $dt;
        // the listener at the camera, the sound on the orbiting cube
        $audio = $this->engine->audio3d();
        $audio->listener([0, 1.5, 5], [0, -0.3, -1]);
        $audio->move($this->voice, $this->cubePosition());
    }

    /** @return float[] */
    private function cubePosition(): array
    {
        return [cos($this->time * 1.2) * 3.0, 0.4, sin($this->time * 1.2) * 3.0];
    }

    public function render(Renderer $r): void
    {
        $gl = $this->engine->opengl();
        $r->clear([8, 10, 26]);
        if ($gl->begin([8, 10, 26, 255])) {
            $this->draw3d($gl);
            $gl->end();
        } else {
            $this->font()->draw($r, "NO OPENGL HERE\n(HEADLESS RUN)", $this->width() / 2, 70, 1, [255, 120, 120], 'center');
        }
        $font = $this->font();
        $r->fillRect(0, 0, $this->width(), 11, [0, 0, 0]);
        $font->draw($r, 'RAW OPENGL + OPENAL', 3, 2, 1, [255, 210, 63]);
        $sound = $this->engine->audio3d()->isAvailable() ? 'SOUND: 3D' : 'SOUND: NONE';
        $font->draw($r, $sound, $this->width() - 3, 2, 1, [200, 220, 255], 'right');
        $font->draw($r, 'THE BEEP COMES FROM THE CUBE   ' . $this->actions()->label('next') . ' BACK',
            $this->width() / 2, $this->height() - 9, 1, [150, 150, 180], 'center');
    }

    private function draw3d(OpenGl $gl): void
    {
        [$w, $h] = $gl->targetSize();
        $eye = [0.0, 1.5, 5.0];
        $viewProj = Mat4::multiply(Mat4::perspective(55, $w / $h, 0.1, 50), Mat4::lookAt($eye, [0, 0, 0]));
        glUseProgram($this->program);
        $mvp = glGetUniformLocation($this->program, 'u_mvp');
        $model = glGetUniformLocation($this->program, 'u_model');
        $color = glGetUniformLocation($this->program, 'u_color');
        glUniform3f(glGetUniformLocation($this->program, 'u_eye'), ...$eye);
        glEnable(GL_CULL_FACE);
        glCullFace(GL_BACK);

        $objects = [
            [$this->torus, $this->torusCount, Mat4::multiply(Mat4::rotateY($this->time * 0.7), Mat4::rotateX($this->time * 1.1)), [1.0, 0.45, 0.3]],
            [$this->cube, 36, Mat4::multiply(Mat4::translate(...$this->cubePosition()), Mat4::rotateY($this->time * 3), Mat4::scale(0.5)), [0.4, 1.0, 0.6]],
        ];
        foreach ($objects as [$buffer, $count, $m, $rgb]) {
            glBindBuffer(GL_ARRAY_BUFFER, $buffer);
            glEnableVertexAttribArray(0);
            glVertexAttribPointer(0, 3, GL_FLOAT, 0, 24, 0);
            glEnableVertexAttribArray(1);
            glVertexAttribPointer(1, 3, GL_FLOAT, 0, 24, 12);
            glUniformMatrix4fv($mvp, 1, false, Mat4::multiply($viewProj, $m));
            glUniformMatrix4fv($model, 1, false, $m);
            glUniform3f($color, ...$rgb);
            glDrawArrays(GL_TRIANGLES, 0, $count);
        }
        glDisableVertexAttribArray(0);
        glDisableVertexAttribArray(1);
    }

    /** A torus: position + normal per vertex. @return float[] */
    private static function torus(float $major, float $minor, int $segments, int $sides): array
    {
        $point = function (int $i, int $j) use ($major, $minor, $segments, $sides): array {
            $u = 2 * M_PI * $i / $segments;
            $v = 2 * M_PI * $j / $sides;
            $n = [cos($v) * cos($u), sin($v), cos($v) * sin($u)];
            return [($major + $minor * cos($v)) * cos($u), $minor * sin($v), ($major + $minor * cos($v)) * sin($u), ...$n];
        };
        $out = [];
        for ($i = 0; $i < $segments; $i++) {
            for ($j = 0; $j < $sides; $j++) {
                $q = [$point($i, $j), $point($i + 1, $j), $point($i + 1, $j + 1), $point($i, $j + 1)];
                foreach ([0, 2, 1, 0, 3, 2] as $k) {
                    array_push($out, ...$q[$k]);
                }
            }
        }
        return $out;
    }

    /** A cube of size 1: position + normal. @return float[] */
    private static function cube(): array
    {
        $out = [];
        foreach ([[0, 1], [0, -1], [1, 1], [1, -1], [2, 1], [2, -1]] as [$axis, $sign]) {
            $n = [0, 0, 0];
            $n[$axis] = $sign;
            $corner = function (float $a, float $b) use ($axis, $sign): array {
                $p = [0.0, 0.0, 0.0];
                $p[$axis] = 0.5 * $sign;
                $p[($axis + 1) % 3] = $a * $sign;
                $p[($axis + 2) % 3] = $b;
                return $p;
            };
            foreach ([[-0.5, -0.5], [0.5, -0.5], [0.5, 0.5], [-0.5, -0.5], [0.5, 0.5], [-0.5, 0.5]] as [$a, $b]) {
                array_push($out, ...$corner($a, $b), ...$n);
            }
        }
        return $out;
    }
}
