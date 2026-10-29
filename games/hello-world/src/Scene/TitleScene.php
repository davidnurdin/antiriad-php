<?php

namespace App\Scene;

use JumPHP\Audio\Synth;
use JumPHP\Kernel\Platform;
use JumPHP\Renderer\Renderer;
use JumPHP\Ui\MenuScene;

/**
 * The first screen: a title and a menu. MenuScene draws the menu, moves in it (arrows, pad, touch) and plays
 * the sounds named menu_move / menu_confirm; we only give its rows and a background.
 */
final class TitleScene extends MenuScene
{
    protected string $title = 'HELLO WORLD';
    private float $time = 0.0;

    public function enter(array $params = []): void
    {
        parent::enter($params);
        // sounds made in PHP, no file needed (a real file: $this->sound()->loadSfx('beep', 'assets/sfx/beep.wav'))
        if (!$this->sound()->hasSfx('menu_move')) {
            $this->sound()->synth('menu_move', Synth::tone(660, 0.04));
            $this->sound()->synth('menu_confirm', Synth::sequence([[660, 0.05], [990, 0.08]]));
        }
    }

    /** The rows: [label, value shown on the right, on confirm, on left / right] */
    protected function items(): array
    {
        $items = [
            ['PLAY', null, fn () => $this->scenes()->switchTo(HelloScene::class)],
            ['SOUND', fn () => $this->sound()->isMuted() ? 'OFF' : 'ON', fn () => $this->toggleSound(), fn () => $this->toggleSound()],
        ];
        if (Platform::isDesktop()) {
            $items[] = ['QUIT', null, fn () => $this->engine->quit()];
        }
        return $items;
    }

    private function toggleSound(): void
    {
        $this->sound()->setMuted(!$this->sound()->isMuted());
        $this->refresh();    // the label shows the new value
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        parent::update($dt);
    }

    protected function renderBackground(Renderer $r): void
    {
        $r->clear([16, 16, 40]);
        for ($i = 0; $i < 40; $i++) {
            $x = fmod($i * 37.0 + $this->time * (10 + $i % 5 * 6), $this->width());
            $r->fillRect($x, ($i * 53) % $this->height(), 1, 1, [120 + $i * 3 % 135, 120, 200]);
        }
    }

    protected function back(): void
    {
        // Escape on the title screen: nothing (the browser page has its own way out)
    }
}
