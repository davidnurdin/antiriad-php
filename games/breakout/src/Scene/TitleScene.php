<?php

namespace App\Scene;

use App\Entity\Brick;
use App\Level\Layouts;
use JumPHP\Audio\Synth;
use JumPHP\Kernel\Platform;
use JumPHP\Renderer\Renderer;
use JumPHP\Ui\MenuScene;
use JumPHP\Util\Lang;

/**
 * Title screen: the main menu over a slowly scrolling wall of bricks.
 */
final class TitleScene extends MenuScene
{
    protected string $title = 'BREAKOUT';
    protected float $titleScale = 4.0;
    protected float $menuTop = 0.5;
    private float $time = 0.0;

    public function enter(array $params = []): void
    {
        parent::enter($params);
        $this->time = 0.0;
        if (!$this->sound()->hasSfx('menu_move')) {
            $this->sound()->synth('menu_move', Synth::tone(660, 0.04));
            $this->sound()->synth('menu_confirm', Synth::sequence([[660, 0.05], [990, 0.08]]));
        }
    }

    protected function items(): array
    {
        $items = [
            ['PLAY', null, fn () => $this->scenes()->switchTo(PlayScene::class, ['level' => 1])],
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
        parent::update($dt);
    }

    protected function renderBackground(Renderer $r): void
    {
        $r->clear([8, 8, 24]);
        $colors = array_values(array_map(fn (array $k) => $k[0], Brick::KINDS));
        $shift = fmod($this->time * 12, 24.0);
        // two dim rows of bricks at the top and the bottom, sliding in opposite directions
        foreach ([[6, 1], [$this->height() - 22, -1]] as $band => [$y, $dir]) {
            for ($row = 0; $row < 2; $row++) {
                for ($col = -1; $col < 15; $col++) {
                    [$red, $green, $blue] = $colors[($col + $row + $band * 3 + 14) % count($colors)];
                    $x = $col * 24 + $shift * $dir;
                    $r->fillRect($x, $y + $row * 10, Brick::WIDTH, Brick::HEIGHT, [$red, $green, $blue, 110]);
                }
            }
        }
        $best = (int)$this->engine->options->get('best', 0);
        if ($best > 0) {
            $this->font()->draw($r, Lang::t('BEST') . ' ' . $best, $this->width() / 2, $this->height() * 0.38, 1, [255, 220, 120], 'center');
        }
        $this->font()->draw($r, count(Layouts::LEVELS) . ' WALLS - JUMPHP ' . date('Y'), $this->width() / 2, $this->height() - 34, 1,
            [90, 90, 110], 'center');
    }
}
