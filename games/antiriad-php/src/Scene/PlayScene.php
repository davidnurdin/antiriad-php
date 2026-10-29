<?php

namespace App\Scene;

use App\Antiriad\Assets;
use App\Antiriad\Game;
use App\Antiriad\Gfx;
use App\Antiriad\Messages;
use App\Antiriad\Ticker;
use App\Antiriad\World;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * The game: the room on top (320 x 128), the dashboard under it (its own picture, mode 1 on the CPC).
 * The rules run at the original's pace, 15 ticks a second (App\Antiriad\Game); drawing runs every frame.
 *
 * Test params: room, x, y, suit (bool), energy, modules (list of 0-3), lives, demo (a bot plays).
 */
final class PlayScene extends AbstractScene
{
    private const RED = [255, 0, 0];
    private const TEXT = [255, 255, 128];

    private World $world;
    private Gfx $gfx;
    private Game $game;
    private Ticker $ticker;
    private float $acc = 0.0;
    private float $endTime = 0.0;
    private bool $demo = false;
    /** @var array<string, bool> presses seen since the last tick */
    private array $latch = [];

    public function enter(array $params = []): void
    {
        $this->world = Assets::boot($this->engine);
        $this->gfx = Assets::gfx();
        $this->demo = !empty($params['demo']);
        $this->game = new Game($this->world, $params);
        $this->ticker = new Ticker(8, 312, 131);
        $this->acc = 0.0;
        $this->endTime = 0.0;
        $this->latch = [];
        $this->sound()->stopMusic();
        $this->flushMessages();
    }

    public function update(float $dt): void
    {
        if ($this->actions()->isPressed('pause') && !$this->demo) {
            $this->scenes()->push(PauseScene::class);
            return;
        }
        $g = $this->game;
        if ($g->over || $g->won) {
            $this->endTime += $dt;
            if ($this->endTime > 1.5 && ($this->actions()->isPressed('fire') || $this->actions()->isPressed('confirm'))) {
                $this->scenes()->switchTo(TitleScene::class);
                return;
            }
            if ($this->demo && $this->endTime > 3) {
                $this->enter(['demo' => true]);
                return;
            }
        }
        foreach (['fire', 'suit', 'jump'] as $action) {
            if ($this->actions()->isPressed($action)) {
                $this->latch[$action] = true;
            }
        }
        $this->acc += $dt;
        for ($n = 0; $this->acc >= Game::TICK && $n < 4; $n++) {
            $this->acc -= Game::TICK;
            $wasWon = $g->won;
            $g->tick($this->demo ? $this->bot() : $this->controls());
            $this->latch = [];
            foreach ($g->sounds as $s) {
                $s === 'death' ? $this->sound()->playMusic('death', false) : $this->sound()->playSfx($s);
            }
            if ($g->won && !$wasWon) {
                $this->sound()->playMusic('victory', false);
                $g->messages[] = 20;
            }
            $this->flushMessages();
        }
        if ($this->acc > Game::TICK) {
            $this->acc = 0.0;
        }
        if ($this->ticker->update($dt, $this->font())) {
            $this->sound()->playSfx('message');
        }
    }

    private function flushMessages(): void
    {
        $lang = $this->engine->lang->getLang();
        foreach ($this->game->messages as $id) {
            $this->ticker->push(Messages::text($this->world, $id, $lang));
        }
        $this->game->messages = [];
    }

    /** @return array<string, bool> */
    private function controls(): array
    {
        $a = $this->actions();
        return [
            'left' => $a->isHeld('left'), 'right' => $a->isHeld('right'), 'up' => $a->isHeld('up'),
            'down' => $a->isHeld('down'),
            'fire' => !empty($this->latch['fire']), 'suit' => !empty($this->latch['suit']),
            'jump' => !empty($this->latch['jump']) || $a->isHeld('jump'),
        ];
    }

    /** The smoke test's player: walks right, jumps and throws now and then. */
    private function bot(): array
    {
        $t = $this->game->tick;
        return [
            'left' => false, 'right' => $t % 60 < 45, 'up' => $t % 37 === 0, 'down' => $t % 90 === 80,
            'fire' => $t % 29 === 0, 'suit' => false, 'jump' => false,
        ];
    }

    public function render(Renderer $r): void
    {
        $g = $this->game;
        $pal = $g->palette();
        $r->clear([0, 0, 0]);
        $this->gfx->room($r, $g->room);
        foreach ($g->actors as $a) {
            if ($a->flash % 2 === 0) {
                $this->gfx->sprite($r, $pal, $a->frame, $a->x * World::PIXELS_PER_BYTE, $a->y - World::TOP);
            }
        }
        $h = $g->hero;
        if ($h->invulnerable === 0 || $g->tick % 2 === 0) {
            $this->gfx->sprite($r, $pal, $h->frame, $h->x * World::PIXELS_PER_BYTE, $h->y - World::TOP + $h->drawOffset());
        }
        if ($g->flash > 0) {
            $r->fillRect(0, 0, 320, 128, [255, 255, 255, 140]);
        }
        $this->renderHud($r);
    }

    private function renderHud(Renderer $r): void
    {
        $g = $this->game;
        $font = $this->font();
        $this->gfx->hud($r, 128);
        $this->ticker->render($r, $font, self::TEXT);
        // on foot the original's dashboard stays dark (emulator, life 1 included): the suit lights it
        if ($g->hero->inSuit || $g->countdown !== null) {
            $this->renderSuitPanel($r);
        }
        if (($g->over || $g->won) && $this->endTime > 0.5) {
            $text = \JumPHP\Util\Lang::t($g->won ? 'VICTORY' : 'GAME OVER');
            $w = $font->width($text, 2) + 16;
            $r->fillRect(160 - $w / 2, 48, $w, 30, [0, 0, 0, 200]);
            $font->draw($r, $text, 160, 56, 2, $g->won ? self::TEXT : self::RED, 'center');
        }
    }

    private function renderSuitPanel(Renderer $r): void
    {
        $g = $this->game;
        $font = $this->font();
        // the two bars of the left panel: the suit's energy, Tal's life
        $low = $g->energy < 7 && $g->tick % 6 < 3;
        $r->fillRect(39, 152, (int)round(34 * $g->energy / Game::MAX_ENERGY), 8, $low ? [128, 0, 0] : self::RED);
        $r->fillRect(39, 168, (int)round(34 * $g->life / Game::MAX_LIFE), 8, self::RED);
        $font->draw($r, sprintf('%05d', min(99999, $g->score)), 80, 160, 1, self::RED);
        // the module lamps (#5A43...: one character cell each)
        foreach ([[248, 184], [256, 176], [264, 184], [272, 176]] as $i => [$x, $y]) {
            if ($g->modules[$i]) {
                $r->fillRect($x + 1, $y + 1, 6, 6, self::RED);
            }
        }
        // the centre screen: the mine's countdown; the round one: the cell Tal carries
        if ($g->countdown !== null) {
            $font->draw($r, (string)max(0, intdiv($g->countdown, 15)), 163, 160, 2, self::RED, 'center');
        }
        if ($g->carryingCell) {
            $this->gfx->sprite($r, $g->palette(), 28, 224, 158, 200);
        }
    }
}
