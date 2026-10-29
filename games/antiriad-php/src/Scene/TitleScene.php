<?php

namespace App\Scene;

use App\Antiriad\Assets;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Util\Lang;

/**
 * The original's title screen, as the CPC draws it (assets/title/title.png, captured by tools/extract):
 * the credits over the dashboard, with the title tune. Fire / Enter starts, Escape opens the options.
 * The disc is the Spanish edition: in Spanish the capture is shown whole; in the other languages its lines
 * are written again, translated, with the original font, colour and rows (one every 16 lines).
 */
final class TitleScene extends AbstractScene
{
    private const BLUE = [0, 128, 255];
    private const CREDITS = [
        'THE SACRED ARMOUR OF ANTIRIAD',
        'PROGRAMMING',
        'ANDREW FITTER',
        'MAPS AND GRAPHICS',
        'DANIEL MALONE',
        'EMILIO',
        'RICHARD JOSEPH',
        '@ PALACE SOFTWARE MCMLXXXVII',     // @ = the font's copyright sign,
    ];

    public function enter(array $params = []): void
    {
        Assets::boot($this->engine);
        if (!$this->sdl()->hasTexture('title')) {
            $this->sdl()->loadTexture('title', 'assets/title/title.png');
        }
        if ($this->sound()->getCurrentMusic() !== 'title') {
            $this->sound()->playMusic('title');
        }
    }

    public function update(float $dt): void
    {
        $a = $this->actions();
        if ($a->isPressed('confirm') || $a->isPressed('fire') || $a->isPressed('jump')) {
            $this->sound()->playSfx('menu_confirm');
            $this->scenes()->switchTo(PlayScene::class);
        } elseif ($a->isPressed('back')) {
            $this->scenes()->push(OptionsScene::class);
        }
    }

    public function render(Renderer $r): void
    {
        $title = $this->sdl()->getTexture('title')['texture'];
        if ($this->engine->lang->getLang() === 'es') {
            $r->copy($title, null, 0, 0);
            return;
        }
        $r->clear([0, 0, 0]);
        $r->copy($title, [0, 128, 320, 72], 0, 128, 320, 72);
        foreach (self::CREDITS as $row => $line) {
            $this->font()->draw($r, Lang::t($line), 160, $row * 16, 1, self::BLUE, 'center');
        }
    }
}
