<?php

namespace App\Scene;

use App\Antiriad\Assets;
use App\Antiriad\Gfx;
use App\Antiriad\Ticker;
use JumPHP\Kernel\Platform;
use JumPHP\Renderer\Renderer;
use JumPHP\Ui\MenuScene;
use JumPHP\Util\Lang;

/**
 * The title screen of the original, remade: its music, the dashboard under the title, the credits
 * scrolling in the message line, Tal and the suit facing each other.
 */
final class TitleScene extends MenuScene
{
    protected float $menuTop = 0.40;
    private Gfx $gfx;
    private Ticker $ticker;
    private float $time = 0.0;

    public function enter(array $params = []): void
    {
        Assets::boot($this->engine);
        $this->gfx = Assets::gfx();
        parent::enter($params);
        $this->menu->selectedColor = [255, 255, 128];
        $this->menu->color = [0, 128, 255];
        $this->ticker = new Ticker(8, 312, 131);
        $this->time = 0.0;
        if ($this->sound()->getCurrentMusic() !== 'title') {
            $this->sound()->playMusic('title');
        }
    }

    protected function items(): array
    {
        $items = [
            ['PLAY', null, fn () => $this->scenes()->switchTo(PlayScene::class)],
            ['OPTIONS', null, fn () => $this->scenes()->push(OptionsScene::class)],
        ];
        if (Platform::isDesktop()) {
            $items[] = ['QUIT', null, fn () => $this->engine->quit()];
        }
        return $items;
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        if (!$this->ticker->busy()) {
            $this->ticker->push(Lang::t('AFTER THE GAME BY PALACE SOFTWARE 1986   PROGRAMMING ANDREW FITTER   GRAPHICS DANIEL MALONE   MUSIC RICHARD JOSEPH   REMADE IN PHP WITH JUMPHP   '));
            $this->ticker->push(Lang::t('ARROWS MOVE   UP JUMP / FLY   SPACE THROW / FIRE   DOWN CROUCH / PICK UP   E SUIT'));
        }
        $this->ticker->update($dt, $this->font());
        parent::update($dt);
    }

    protected function renderBackground(Renderer $r): void
    {
        $r->clear([0, 0, 0]);
        $font = $this->font();
        $font->draw($r, Lang::t('THE SACRED ARMOUR'), 160, 14, 2, [255, 128, 0], 'center', [128, 0, 0]);
        $font->draw($r, Lang::t('OF ANTIRIAD'), 160, 36, 2, [255, 255, 128], 'center', [128, 0, 0]);
        // Tal (sprite 9) and the empty suit (sprite 101), from the sheet of the jungle's palette
        $bob = (int)(2 * sin($this->time * 2));
        $this->gfx->sprite($r, 11, 9 | 0x80, 30, 70 + $bob, 200);
        $this->gfx->sprite($r, 11, 101, 266, 70 - $bob, 200);
        $this->gfx->hud($r, 128);
        $this->ticker->render($r, $font, [255, 255, 128]);
    }
}
