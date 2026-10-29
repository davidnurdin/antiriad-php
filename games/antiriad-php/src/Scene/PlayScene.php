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
    private bool $humming = false;
    /** #5EF7: the message warble (#07) loops from a message's start until the line is empty */
    private bool $chatting = false;
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
        $this->ticker = new Ticker(120, 208, 184);      // #5EA0: the dashboard's message window (black from x 119 to 208, row 23)
        $this->acc = 0.0;
        $this->endTime = 0.0;
        $this->latch = [];
        $this->sound()->stopMusic();
        $this->flushMessages();
        $this->makeScanner();
        $this->humming = false;
        $this->sound()->stopSfx('teleport');
        $this->chatting = false;
        $this->sound()->stopSfx('message');
    }

    public function exit(): void
    {
        $this->sound()->stopSfx('teleport');
        $this->humming = false;
        $this->sound()->stopSfx('message');       // both start again with the next update()
        $this->chatting = false;
    }

    public function pause(): void
    {
        $this->exit();                            // the hum starts again with the next update()
    }

    private const SCANNER_FRAMES = 6;

    /** The suit's scanner (the centre screen): noise of pens 0-3 as the emulator shows it, a few pictures. */
    private function makeScanner(): void
    {
        if ($this->sdl()->hasTexture('scanner-0')) {
            return;
        }
        mt_srand(1986);
        for ($f = 0; $f < self::SCANNER_FRAMES; $f++) {
            $rows = [];
            for ($y = 0; $y < 23; $y++) {
                $row = '';
                for ($x = 0; $x < 41; $x++) {
                    $n = mt_rand(0, 99);         // ~45 % black, 25 % blue, 20 % red, 10 % cyan
                    $row .= $n < 45 ? '.' : ($n < 70 ? 'b' : ($n < 90 ? 'r' : 'c'));
                }
                $rows[] = $row;
            }
            $this->sdl()->createTextureFromPixels("scanner-$f", $rows, ['b' => [0, 128, 255], 'r' => [255, 0, 0], 'c' => [0, 255, 255]]);
        }
        mt_srand();
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
                $this->sound()->playSfx($s);
            }
            if ($g->won && !$wasWon) {
                $this->sound()->playMusic('victory', false);
                $g->messages[] = 20;
            }
            $this->flushMessages();
        }
        $hum = $g->hum && !$g->over && !$g->won;
        if ($hum !== $this->humming) {
            $hum ? $this->sound()->playSfx('teleport', -1) : $this->sound()->stopSfx('teleport');
            $this->humming = $hum;
        }
        if ($this->acc > Game::TICK) {
            $this->acc = 0.0;
        }
        $started = $this->ticker->update($dt, $this->font());
        $busy = $this->ticker->busy();
        if ($started || $busy !== $this->chatting) {
            $this->sound()->stopSfx('message');
            if ($busy) {
                $this->sound()->playSfx('message', -1);
            }
            $this->chatting = $busy;
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
            if ($a->fading()) {                   // appearing / going: through mask #B411 (#6E30)
                $this->gfx->masked($r, $pal, $a->frame, $a->mask, $a->x * World::PIXELS_PER_BYTE, $a->y - World::TOP);
            } elseif ($a->flash % 2 === 0) {
                $this->gfx->sprite($r, $pal, $a->frame, $a->x * World::PIXELS_PER_BYTE, $a->y - World::TOP);
            }
        }
        $h = $g->hero;
        if ($g->entering >= 0) {                  // getting in: Tal dissolves in front of the suit, mask 12 .. 0
            $this->gfx->masked($r, $pal, 9, intdiv(12 * $g->entering, Game::ENTER_TICKS), $h->x * World::PIXELS_PER_BYTE, $h->y - World::TOP + $h->drawOffset());
        } elseif ($h->invulnerable === 0 || $g->tick % 2 === 0) {
            $this->gfx->sprite($r, $pal, $h->frame, $h->x * World::PIXELS_PER_BYTE, $h->y - World::TOP + $h->drawOffset(), 128, $h->inSuit);
        }
        if ($g->flash > 0) {
            $r->fillRect(0, 0, 320, 128, [255, 255, 255, 140]);
        }
        $this->renderHud($r);
    }

    /** #8B9A: the dial's segments: row, column, character (through #8B3D: 'x' = glyph #30...) */
    private const DIAL = [[21, 27, 'x'], [20, 27, 'w'], [19, 27, 'v'], [19, 28, 'b'], [19, 29, 't'], [20, 29, 's'], [21, 29, 'r']];

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
            $cpc->sign($r, 'z', 34 * 8, 20 * 8, self::RED);                                  // #612E
            // #72CA: the radiation dial, one segment more per zone, '!' from zone 5 (the base)
            $zone = Game::zone($g->room);
            foreach (array_slice(self::DIAL, 0, $zone + 1) as [$row, $col, $ch]) {
                $cpc->sign($r, $ch, $col * 8, $row * 8, self::RED);
            }
            $cpc->sign($r, 'y', 28 * 8, 21 * 8, self::RED);
            if ($zone >= 5) {
                $font->draw($r, '!', 27 * 8, 23 * 8, 1, self::RED);
            }
        }
        // #5DFC: the bars follow the suit's energy (#D5FA) and Tal's life (#D69A) a unit a tick: value v is
        // v + 1 pixels (#8EBE: 1-4 pixels in its last byte) 4 lines high, pen 1 (red) for the first 16, pen 3
        // (cyan) after; black behind
        if ($g->shownLife >= 0) {
            foreach ([[154, $g->shownEnergy], [170, $g->shownLife]] as [$y, $v]) {
                $r->fillRect(38, $y - 2, 36, 8, [0, 0, 0]);
                $w = $v > 0 ? min(32, $v + 1) : 0;
                $r->fillRect(40, $y, min(16, $w), 4, self::RED);
                if ($w > 16) {
                    $r->fillRect(56, $y, $w - 16, 4, [0, 255, 255]);
                }
            }
        }
        // the centre screen: the mine's countdown, or the suit's scanner
        if ($g->countdown !== null) {
            $font->draw($r, (string)max(0, intdiv($g->countdown, 15)), 163, 160, 2, self::RED, 'center');
        } elseif ($g->hero->inSuit && $g->shownLife >= 0) {
            $r->fillRect(143, 157, 41, 23, [0, 0, 0]);
            $r->copy($this->sdl()->getTexture('scanner-' . $g->tick % self::SCANNER_FRAMES)['texture'], null, 143, 157, 41, 23);
        }
        if ($g->carryingCell) {
            $this->gfx->sprite($r, $g->palette(), 28, 224, 158, 200);
        }
    }
}
