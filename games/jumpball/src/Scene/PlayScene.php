<?php

namespace App\Scene;

use App\Game\Best;
use App\Game\Run;
use App\Game\Sounds;
use App\Gfx\World;
use JumPHP\Loop\FixedStep;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Ui\Hud;

/**
 * The game: the ball rolls on by itself, faster and faster; steer and jump over the holes and blocks.
 * Params: demo (the bot plays, restarts by itself: smoke test), seed (the same track every time).
 */
final class PlayScene extends AbstractScene
{
    private const READY = 1.2;

    private Run $run;
    private FixedStep $step;
    private bool $demo = false;
    private float $ready = 0.0;
    private float $flash = 0.0;
    private int $best = 0;
    private bool $ended = false;

    public function __construct(private World $world)
    {
    }

    public function enter(array $params = []): void
    {
        $this->demo = (bool)($params['demo'] ?? false);
        $this->run = new Run((int)($params['seed'] ?? random_int(1, 999999)));
        $this->step = new FixedStep(120);
        $this->ready = $this->demo ? 0.0 : self::READY;
        $this->flash = 0.0;
        $this->ended = false;
        $this->best = Best::load($this->engine->storage);
        Sounds::load($this->sound());
        $this->world->prepare($this->engine->gl());
    }

    public function update(float $dt): void
    {
        if ($this->actions()->isPressed('pause')) {
            $this->scenes()->push(PauseScene::class);
            return;
        }
        $this->flash = max(0.0, $this->flash - $dt);
        if ($this->ready > 0) {
            $this->ready -= $dt;
            return;
        }
        if ($this->demo) {
            [$steer, $jump] = $this->run->autopilot();
        } else {
            $pad = $this->engine->input->getGamepad()->axis(0, 'lx');
            $steer = max(-1.0, min(1.0, $this->actions()->axis('left', 'right') + $pad));
            $jump = $this->actions()->isHeld('jump') || $this->actions()->isPressed('jump');
        }
        $this->step->run($dt, fn (float $h) => $this->run->step($h, $steer, $jump));
        $this->events();

        if ($this->run->state === 'over' && !$this->ended) {
            $this->ended = true;
            if ($this->demo) {
                $this->enter(['demo' => true, 'seed' => $this->run->distance() + 7]);
                return;
            }
            $score = $this->run->score();
            $record = Best::submit($this->engine->storage, $score);
            $this->scenes()->push(GameOverScene::class, [
                'score' => $score, 'distance' => $this->run->distance(), 'gems' => $this->run->gems,
                'best' => max($score, $this->best), 'record' => $record,
            ]);
        }
    }

    /** Sounds and rumble for what happened in the run. */
    private function events(): void
    {
        foreach ($this->run->events as $event) {
            $this->sound()->playSfx($event);
            match ($event) {
                'gem' => $this->flash = 0.15,
                'crash', 'fall' => $this->shake(),
                default => null,
            };
        }
        $this->run->events = [];
    }

    private function shake(): void
    {
        if (!$this->demo) {
            $this->engine->input->getGamepad()->rumble(0, 0.9, 0.5, 0.3);
            $this->engine->device()->vibrate(120);
        }
    }

    public function render(Renderer $r): void
    {
        $this->world->render($this->engine, $r, $this->run);
        $font = $this->font();
        $w = $this->width();
        $run = $this->run;

        $r->fillRect(0, 0, $w, 12, [0, 0, 0, 140]);
        $font->draw($r, $run->distance() . ' M', 4, 3, 1, [255, 255, 255]);
        $font->draw($r, 'GEMS ' . $run->gems, $w / 2, 3, 1, $this->flash > 0 ? [255, 255, 255] : [255, 220, 60], 'center');
        $font->draw($r, 'BEST ' . max($this->best, $this->demo ? 0 : $run->score()), $w - 4, 3, 1, [200, 200, 255], 'right');
        // speed gauge
        $ratio = ($run->speed - Run::START_SPEED) / (Run::MAX_SPEED - Run::START_SPEED);
        Hud::bar($r, 4, $this->height() - 7, 60, 3, max(0.0, $ratio));

        if ($this->ready > 0) {
            $text = $this->ready > 0.4 ? 'READY' : 'GO!';
            $font->draw($r, $text, $w / 2, $this->height() * 0.42, 3, [255, 220, 60], 'center', [0, 0, 0]);
        }
        if ($this->demo) {
            $font->draw($r, 'DEMO', $w - 4, $this->height() - 10, 1, [255, 255, 255], 'right', [0, 0, 0]);
        }
    }
}
