<?php

namespace App\Scene;

use JumPHP\Audio\Synth;
use JumPHP\Kernel\Platform;
use JumPHP\Renderer\Renderer;
use JumPHP\Ui\MenuScene;

/**
 * First screen: the title and the main menu.
 */
final class TitleScene extends MenuScene
{
    protected string $title = 'HELLO WEBGL';
    private float $time = 0.0;

    public function enter(array $params = []): void
    {
        parent::enter($params);
        $pwa = $this->engine->pwa();
        $pwa->removeAllListeners();
        foreach (['installable', 'installed', 'update'] as $event) {
            $pwa->on($event, fn () => $this->refresh());   // the menu shows INSTALL / UPDATE when possible
        }
        // sounds made in PHP: no audio file needed (load real files with $this->sound()->loadSfx())
        if (!$this->sound()->hasSfx('menu_move')) {
            $this->sound()->synth('menu_move', Synth::tone(660, 0.04));
            $this->sound()->synth('menu_confirm', Synth::sequence([[660, 0.05], [990, 0.08]]));
        }
    }

    protected function items(): array
    {
        $items = [
            ['START', null, fn () => $this->scenes()->switchTo(CubesScene::class)],
        ];
        // installable app (game.yaml pwa): the browser's install question, or the newer build
        $pwa = $this->engine->pwa();
        if ($pwa->canInstall()) {
            $items[] = ['INSTALL', null, fn () => $pwa->install()->then(fn () => $this->refresh())];
        }
        if ($pwa->updateReady()) {
            $items[] = ['UPDATE', null, fn () => $pwa->applyUpdate()];
        }
        if (Platform::isDesktop()) {
            $items[] = ['QUIT', null, fn () => $this->engine->quit()];
        }
        return $items;
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        parent::update($dt);
    }

    protected function renderBackground(Renderer $r): void
    {
        $r->clear([16, 16, 40]);
        // a few moving stars
        for ($i = 0; $i < 40; $i++) {
            $x = fmod($i * 37.0 + $this->time * (10 + $i % 5 * 6), $this->width());
            $r->fillRect($x, ($i * 53) % $this->height(), 1, 1, [120 + $i * 3 % 135, 120, 200]);
        }
    }
}
