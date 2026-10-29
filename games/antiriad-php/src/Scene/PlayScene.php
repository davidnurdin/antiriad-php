<?php

namespace App\Scene;

use App\Antiriad\Actor;
use App\Antiriad\Assets;
use App\Antiriad\CpcFont;
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
        $this->ticker = new Ticker(136, 216, 184);      // #5EA0: the dashboard's message window (columns 17-26, row 23)
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
        if ($g->entering >= 0) {                  // getting in: Tal dissolves in front of the suit, mask 12 .. 0
            $this->gfx->dissolve($r, $pal, intdiv(12 * $g->entering, Game::ENTER_TICKS), $h->x * World::PIXELS_PER_BYTE, $h->y - World::TOP + $h->drawOffset());
        } elseif ($h->invulnerable === 0 || $g->tick % 2 === 0) {
            $this->gfx->sprite($r, $pal, $h->frame, $h->x * World::PIXELS_PER_BYTE, $h->y - World::TOP + $h->drawOffset(), 128, $h->inSuit);
        }
        if ($g->flash > 0) {
            $r->fillRect(0, 0, 320, 128, [255, 255, 255, 140]);
        }
        $this->renderHud($r);
    }

    /** #8B9A: the dial's segments: row, column, glyph */
    private const DIAL = [[21, 27, 0x78], [20, 27, 0x77], [19, 27, 0x76], [19, 28, 0x62], [19, 29, 0x74], [20, 29, 0x73], [21, 29, 0x72]];

    private function renderHud(Renderer $r): void
    {
        $g = $this->game;
        $font = $this->font();
        $this->gfx->hud($r, 128, $g->dashLit);
        $this->ticker->render($r, $font, self::RED);
        // dark and empty until Tal first gets into the suit (emulator); then lit while he is inside
        $this->renderPanel($r);
        if (($g->over || $g->won) && $this->endTime > 0.5) {
            $text = \JumPHP\Util\Lang::t($g->won ? 'VICTORY' : 'GAME OVER');
            $w = $font->width($text, 2) + 16;
            $r->fillRect(160 - $w / 2, 48, $w, 30, [0, 0, 0, 200]);
            $font->draw($r, $text, 160, 56, 2, $g->won ? self::TEXT : self::RED, 'center');
        }
    }

    /** What #6FBE writes on the dashboard (character cells: x = column * 8, y = row * 8), and the bars. */
    private function renderPanel(Renderer $r): void
    {
        $g = $this->game;
        $font = $this->font();
        $cpc = $font instanceof CpcFont ? $font : null;
        // the module lamps (#5A43, #5A67, #5A8B, #5AB3: a '.' each)
        foreach ([[31, 23], [32, 22], [33, 23], [34, 22]] as $i => [$col, $row]) {
            if ($g->modules[$i]) {
                $font->draw($r, '.', $col * 8, $row * 8, 1, self::RED);
            }
        }
        if ($g->dashOn && $cpc !== null) {
            $font->draw($r, sprintf('%05d', min(99999, $g->score)), 80, 160, 1, self::RED);   // #6137
            $cpc->glyph($r, ord('z'), 34 * 8, 20 * 8, self::RED);                            // #612E
            // #72CA: the radiation dial, one segment more per zone, '!' from zone 5 (the base)
            $zone = Game::zone($g->room);
            foreach (array_slice(self::DIAL, 0, $zone + 1) as [$row, $col, $code]) {
                $cpc->glyph($r, $code, $col * 8, $row * 8, self::RED);
            }
            $cpc->glyph($r, ord('y'), 28 * 8, 21 * 8, self::RED);
            if ($zone >= 5) {
                $font->draw($r, '!', 27 * 8, 23 * 8, 1, self::RED);
            }
        }
        // #5DE9: the bars follow the suit's energy and Tal's life a unit a tick (4 units a screen byte)
        if ($g->shownLife >= 0) {
            $low = $g->energy < 7 && $g->tick % 6 < 3;
            $r->fillRect(40, 152, $g->shownEnergy, 8, $low ? [128, 0, 0] : self::RED);
            $r->fillRect(40, 168, $g->shownLife, 8, self::RED);
        }
        // the centre screen: the mine's countdown, or the suit's scanner
        if ($g->countdown !== null) {
            $font->draw($r, (string)max(0, intdiv($g->countdown, 15)), 163, 160, 2, self::RED, 'center');
        } elseif ($g->hero->inSuit && $g->shownLife >= 0) {
            mt_srand($g->tick);
            for ($i = 0; $i < 7; $i++) {
                $r->fillRect(148 + $i * 5, 160 + mt_rand(0, 6), 2, 14, self::RED);
            }
            for ($i = 0; $i < 24; $i++) {
                $r->fillRect(146 + mt_rand(0, 36), 152 + mt_rand(0, 20), 2, 1, [0, 255, 255]);
            }
            mt_srand();
        }
        if ($g->carryingCell) {
            $this->gfx->sprite($r, $g->palette(), 28, 224, 158, 200);
        }
    }
}
