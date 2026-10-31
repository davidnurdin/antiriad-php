<?php

namespace App\Scene;

use JumPHP\Audio\Synth;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * Hello world: a text that moves with the arrows, and a sound (and a burst of stars) on Space / Enter / a tap.
 * Escape goes back to the title. The whole game loop: update() changes the state, render() draws it.
 */
final class HelloScene extends AbstractScene
{
    private float $time = 0.0;
    private float $x = 0.0;
    private float $y = 0.0;
    private int $beeps = 0;
    private bool $demo = false;
    /** @var array<int, array{x: float, y: float, vx: float, vy: float, life: float}> */
    private array $stars = [];

    // scenes are reused: everything starts again here
    public function enter(array $params = []): void
    {
        $this->time = 0.0;
        $this->x = $this->width() / 2;
        $this->y = $this->height() / 2;
        $this->beeps = 0;
        $this->stars = [];
        $this->demo = !empty($params['demo']);    // the self-playing demo of `bin/jumphp test`
        if (!$this->sound()->hasSfx('beep')) {
            $this->sound()->synth('beep', Synth::sweep(440, 880, 0.12));
        }
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        if ($this->actions()->isPressed('back')) {
            $this->scenes()->switchTo(TitleScene::class);
            return;
        }
        // the arrows (or the pad, or the phone's pad) move the text, 90 pixels per second
        $this->x += $this->actions()->axis('left', 'right') * 90 * $dt;
        $this->y += $this->actions()->axis('up', 'down') * 90 * $dt;
        if ($this->demo) {
            $this->x = $this->width() / 2 + sin($this->time) * 60;
        }
        $this->x = max(40, min($this->width() - 40, $this->x));
        $this->y = max(20, min($this->height() - 30, $this->y));

        $beep = $this->actions()->isPressed('confirm') || $this->actions()->isPressed('jump')
            || ($this->demo && (int)($this->time * 2) !== (int)(($this->time - $dt) * 2));
        if ($beep) {
            $this->beeps++;
            $this->sound()->playSfx('beep');
            for ($i = 0; $i < 16; $i++) {
                $angle = $i / 16 * 2 * M_PI;
                $this->stars[] = ['x' => $this->x, 'y' => $this->y, 'vx' => cos($angle) * 80, 'vy' => sin($angle) * 80, 'life' => 0.8];
            }
        }
        foreach ($this->stars as $i => &$star) {
            $star['x'] += $star['vx'] * $dt;
            $star['y'] += $star['vy'] * $dt;
            $star['life'] -= $dt;
            if ($star['life'] <= 0) {
                unset($this->stars[$i]);
            }
        }
        unset($star);
    }

    public function render(Renderer $r): void
    {
        $r->clear([16, 16, 40]);
        foreach ($this->stars as $star) {
            $r->fillRect($star['x'], $star['y'], 2, 2, [255, 220, 80, (int)(255 * min(1, $star['life'] / 0.4))]);
        }
        // the text, its colour turning, bobbing up and down
        $colour = [(int)(160 + 95 * sin($this->time * 2)), (int)(160 + 95 * sin($this->time * 2 + 2.1)), (int)(160 + 95 * sin($this->time * 2 + 4.2))];
        $bob = sin($this->time * 4) * 3;
        $this->font()->draw($r, 'HELLO WORLD!', $this->x, $this->y - 7 + $bob, 2, $colour, 'center', [0, 0, 0]);
        $this->font()->draw($r, $this->beeps . ' BEEP' . ($this->beeps === 1 ? '' : 'S'), 6, 6, 1, [160, 160, 200]);
        $this->font()->draw($r, 'ARROWS: MOVE   SPACE: BEEP   ESC: BACK', $this->width() / 2, $this->height() - 12, 1, [120, 120, 160], 'center');
    }
}
