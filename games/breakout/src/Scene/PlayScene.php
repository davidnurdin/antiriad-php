<?php

namespace App\Scene;

use App\Entity\Ball;
use App\Entity\Brick;
use App\Entity\Paddle;
use App\Level\Layouts;
use JumPHP\Audio\Synth;
use JumPHP\Loop\FixedStep;
use JumPHP\Physics\Aabb;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Ui\Hud;
use JumPHP\Util\Lang;

/**
 * A game: 3 lives, a wall of bricks per level. Params (enter):
 *   ['level' => 1]     first level (default 1)
 *   ['demo' => true]   the computer plays, endless (title background idea, headless smoke test)
 *   ['keep' => 5]      only keep the first N bricks of each wall (tests: reach the next level fast)
 */
final class PlayScene extends AbstractScene
{
    public const HUD = 14;               // height of the score band
    public const WALL = 4;               // thickness of the side / top walls
    public const PADDLE_Y = 222;
    private const BRICKS_TOP = 36;
    private const LIVES = 3;
    private const AUTO_LAUNCH = 3.0;     // the ball leaves the paddle by itself after this many seconds
    private const CLEAR_DELAY = 1.6;     // "LEVEL n" message between two walls
    private const ROW_PITCH = [988, 880, 784, 659, 587, 523, 440];

    private Paddle $paddle;
    private Ball $ball;
    /** @var Brick[] */
    private array $bricks = [];
    private FixedStep $step;
    private string $state = 'serve';     // serve (ball on the paddle), play, clear (next level), over
    private float $timer = 0.0;
    private float $time = 0.0;
    private int $level = 1;
    private int $lives = self::LIVES;
    private int $score = 0;
    private bool $demo = false;
    private int $keep = 0;
    private array $params = [];

    public function enter(array $params = []): void
    {
        $this->params = $params;
        $this->demo = !empty($params['demo']);
        $this->keep = (int)($params['keep'] ?? 0);
        $this->level = max(1, (int)($params['level'] ?? 1));
        $this->lives = self::LIVES;
        $this->score = 0;
        $this->time = 0.0;
        $this->step = new FixedStep(120);   // ball up to 330 px/s: < 3 px per step, no tunnelling through 8 px bricks
        $this->paddle = new Paddle($this->width() / 2 - Paddle::WIDTH / 2, self::PADDLE_Y, self::WALL, $this->width() - self::WALL);
        $this->ball = new Ball(0, 0);
        $this->buildWall();
        $this->serve();

        $sound = $this->sound();
        if (!$sound->hasSfx('paddle')) {
            $sound->synth('paddle', Synth::tone(330, 0.06));
            $sound->synth('wall', Synth::tone(220, 0.04, 'triangle'));
            $sound->synth('steel', Synth::tone(1400, 0.05, 'triangle'));
            foreach (self::ROW_PITCH as $i => $hz) {
                $sound->synth('brick' . $i, Synth::tone($hz, 0.07));
            }
            $sound->synth('lose', Synth::sweep(500, 70, 0.6, 'saw'));
            $sound->synth('clear', Synth::sequence([[523, 0.08], [659, 0.08], [784, 0.08], [1047, 0.25]]));
            $sound->synth('over', Synth::sequence([[392, 0.15], [330, 0.15], [262, 0.15], [196, 0.4]], 'triangle'));
        }
    }

    /** The wall of the current level. */
    private function buildWall(): void
    {
        $this->bricks = [];
        $pitch = (int)(($this->width() - 2 * self::WALL) / Layouts::COLUMNS);   // 24 px
        foreach (Layouts::rows($this->level) as $row => $line) {
            for ($col = 0; $col < strlen($line); $col++) {
                $kind = $line[$col];
                if (isset(Brick::KINDS[$kind])) {
                    $x = self::WALL + $col * $pitch + ($pitch - Brick::WIDTH) / 2;
                    $this->bricks[] = new Brick($x, self::BRICKS_TOP + $row * (Brick::HEIGHT + 2), $kind);
                }
            }
        }
        if ($this->keep > 0) {
            $this->bricks = array_slice($this->bricks, -$this->keep);
        }
    }

    /** The ball waits on the paddle. */
    private function serve(): void
    {
        $this->state = 'serve';
        $this->timer = self::AUTO_LAUNCH;
        $this->ball->setVelocity(0, 0);
        $this->stickBall();
    }

    private function stickBall(): void
    {
        $this->ball->setXY($this->paddle->centerX() - Ball::SIZE / 2, self::PADDLE_Y - Ball::SIZE - 1);
    }

    private function levelSpeed(): float
    {
        return min(260.0, 150.0 + 18.0 * ($this->level - 1));
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        if ($this->actions()->isPressed('pause') && $this->state !== 'over') {
            if ($this->demo) {
                $this->scenes()->switchTo(TitleScene::class);
            } else {
                $this->scenes()->push(PauseScene::class);
            }
            return;
        }

        $this->controls($dt);
        if ($this->state === 'serve') {
            $this->stickBall();
            $this->timer -= $dt;
            if ($this->timer <= 0 || (!$this->demo && $this->actions()->isPressed('launch'))) {
                $this->state = 'play';
                $this->ball->launch($this->levelSpeed());
                $this->sound()->playSfx('paddle');
            }
        } elseif ($this->state === 'play') {
            $this->step->run($dt, fn (float $h) => $this->simulate($h));
        } elseif ($this->state === 'clear' || $this->state === 'over') {
            $this->timer -= $dt;
            if ($this->timer <= 0) {
                $this->state === 'clear' ? $this->nextLevel() : $this->enter($this->params);   // 'over' only in the demo
            }
        }
    }

    private function controls(float $dt): void
    {
        if ($this->demo) {
            $this->paddle->moveTowards($this->ball->centerX() - $this->demoAim() * Paddle::WIDTH / 2, $dt);
            return;
        }
        $touch = $this->engine->input->getTouch();
        if ($touch->active) {
            $this->paddle->moveTowards($touch->x * $this->width(), $dt);
        } else {
            $this->paddle->move($this->actions()->axis('left', 'right'), $dt);
        }
    }

    /** Demo: which part of the paddle (-1..1) sends the ball towards the lowest brick. */
    private function demoAim(): float
    {
        $target = null;
        foreach ($this->bricks as $brick) {
            if ($target === null || $brick->getFloatY() > $target->getFloatY()) {
                $target = $brick;
            }
        }
        if ($target === null) {
            return 0.0;
        }
        $dx = $target->centerX() - $this->ball->centerX();
        $dy = max(1.0, self::PADDLE_Y - $target->centerY());
        return max(-0.9, min(0.9, atan2($dx, $dy) / deg2rad(60)));
    }

    /** One physics step: x then y, so a brick knows which face was hit. */
    private function simulate(float $h): void
    {
        if ($this->state !== 'play') {
            return;
        }
        $ball = $this->ball;
        [$vx, $vy] = $ball->getVelocity();
        $right = $this->width() - self::WALL;

        $ball->setX($ball->getFloatX() + $vx * $h);
        if (($ball->getFloatX() < self::WALL && $vx < 0) || ($ball->getFloatX() + Ball::SIZE > $right && $vx > 0)) {
            $ball->setX($vx < 0 ? self::WALL : $right - Ball::SIZE);
            $ball->setSpeedX(-$vx);
            $this->sound()->playSfx('wall');
        } elseif ($this->hitBrick()) {
            $ball->setX($ball->getFloatX() - $vx * $h);
            $ball->setSpeedX(-$vx);
        }

        $ball->setY($ball->getFloatY() + $vy * $h);
        if ($ball->getFloatY() < self::HUD + self::WALL && $vy < 0) {
            $ball->setY(self::HUD + self::WALL);
            $ball->setSpeedY(-$vy);
            $this->sound()->playSfx('wall');
        } elseif ($this->hitBrick()) {
            $ball->setY($ball->getFloatY() - $vy * $h);
            $ball->setSpeedY(-$vy);
        } elseif ($vy > 0 && Aabb::intersects($ball->getBoundingBox(), $this->paddle->getBoundingBox())
            && $ball->getFloatY() + Ball::SIZE - $vy * $h <= self::PADDLE_Y + 2) {
            // the hit point on the paddle sets the angle: the edges send the ball sideways
            $offset = ($ball->centerX() - $this->paddle->centerX()) / (Paddle::WIDTH / 2);
            $ball->setY(self::PADDLE_Y - Ball::SIZE);
            $ball->aim($offset);
            $this->sound()->playSfx('paddle');
        }

        if ($ball->getFloatY() > $this->height()) {
            $this->loseLife();
        }
    }

    /** The first brick under the ball takes a hit. */
    private function hitBrick(): bool
    {
        $box = $this->ball->getBoundingBox();
        foreach ($this->bricks as $i => $brick) {
            if (!Aabb::intersects($box, $brick->getBoundingBox())) {
                continue;
            }
            if ($brick->hit()) {
                $this->score += $brick->points;
                $row = (int)(($brick->getFloatY() - self::BRICKS_TOP) / (Brick::HEIGHT + 2));
                $this->sound()->playSfx('brick' . min($row, count(self::ROW_PITCH) - 1));
                unset($this->bricks[$i]);
                $this->ball->speedUp(1.012);
                if ($this->bricks === []) {
                    $this->levelCleared();
                }
            } else {
                $this->sound()->playSfx('steel');
            }
            return true;
        }
        return false;
    }

    private function levelCleared(): void
    {
        $this->state = 'clear';
        $this->timer = self::CLEAR_DELAY;
        $this->score += 500;
        $this->sound()->playSfx('clear');
    }

    private function nextLevel(): void
    {
        $this->level++;
        $this->buildWall();
        $this->serve();
    }

    private function loseLife(): void
    {
        $this->lives--;
        $this->sound()->playSfx('lose');
        if ($this->lives > 0) {
            $this->serve();
            return;
        }
        $this->state = 'over';
        $this->timer = 2.5;
        $this->sound()->playSfx('over');
        if ($this->demo) {
            return;                              // restarts by itself (update)
        }
        $options = $this->engine->options;
        $best = (int)$options->get('best', 0);
        if ($this->score > $best) {
            $options->set('best', $this->score);
        }
        $this->scenes()->push(GameOverScene::class, [
            'score' => $this->score, 'level' => $this->level, 'record' => $this->score > $best, 'again' => $this->params,
        ]);
    }

    // --- drawing ---------------------------------------------------------------------------------

    public function render(Renderer $r): void
    {
        $w = $this->width();
        $font = $this->font();
        $r->clear([8, 8, 24]);
        // the walls
        $r->fillRect(0, self::HUD, $w, self::WALL, [90, 90, 110]);
        $r->fillRect(0, self::HUD, self::WALL, $this->height() - self::HUD, [90, 90, 110]);
        $r->fillRect($w - self::WALL, self::HUD, self::WALL, $this->height() - self::HUD, [90, 90, 110]);

        foreach ($this->bricks as $brick) {
            $brick->render($r);
        }
        $this->paddle->render($r);
        if ($this->state === 'play' || $this->state === 'serve') {
            $this->ball->render($r);
        }

        // HUD: score, level, lives (small paddles)
        $font->draw($r, Lang::t('SCORE {n}', ['n' => $this->score]), 4, 4, 1, [255, 255, 255]);
        $font->draw($r, $this->demo ? Lang::t('DEMO') : Lang::t('LEVEL {n}', ['n' => $this->level]), $w / 2, 4, 1, [255, 220, 120], 'center');
        for ($i = 0; $i < $this->lives; $i++) {
            $r->fillRect($w - 14 - $i * 14, 5, 10, 4, [200, 200, 220]);
        }

        $cy = $this->height() * 0.62;
        if ($this->state === 'clear') {
            Hud::message($r, $font, Lang::t('LEVEL {n}', ['n' => $this->level + 1]), $w / 2, $cy, 2);
        } elseif ($this->state === 'serve') {
            $font->draw($r, Lang::t('LEVEL {n}', ['n' => $this->level]), $w / 2, $cy - 14, 2, [255, 255, 255], 'center', [0, 0, 0]);
            if (!$this->demo && Hud::blink($this->time)) {
                $font->draw($r, Lang::t('{key} TO LAUNCH', ['key' => strtoupper($this->actions()->label('launch'))]), $w / 2, $cy + 6, 1,
                    [200, 200, 200], 'center', [0, 0, 0]);
            }
        } elseif ($this->state === 'over' && $this->demo) {   // a player sees GameOverScene instead
            Hud::message($r, $font, Lang::t('GAME OVER'), $w / 2, $cy, 2);
        }
    }
}
