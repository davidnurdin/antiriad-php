<?php

namespace App\Scene;

use App\Entity\Ball;
use JumPHP\Audio\Synth;
use JumPHP\Kernel\Platform;
use JumPHP\Net\LobbyScene;
use JumPHP\Renderer\Renderer;
use JumPHP\Ui\MenuScene;

/**
 * Title screen: a ball bouncing behind the main menu.
 */
final class TitleScene extends MenuScene
{
    protected string $title = 'PONG';
    protected float $titleScale = 5.0;
    protected float $menuTop = 0.48;
    private Ball $ball;

    public function enter(array $params = []): void
    {
        parent::enter($params);
        $this->ball = new Ball(40, 40);
        $this->ball->setVelocity(90, 70);
        if (!$this->sound()->hasSfx('menu_move')) {
            $this->sound()->synth('menu_move', Synth::tone(660, 0.04));
            $this->sound()->synth('menu_confirm', Synth::sequence([[660, 0.05], [990, 0.08]]));
        }
    }

    protected function items(): array
    {
        $items = [
            ['1 PLAYER', null, fn () => $this->scenes()->switchTo(PlayScene::class, ['mode' => 'cpu'])],
            ['2 PLAYERS', null, fn () => $this->scenes()->switchTo(PlayScene::class, ['mode' => 'local'])],
            ['ONLINE', null, fn () => $this->scenes()->switchTo(LobbyScene::class, [
                'next' => PlayScene::class, 'nextParams' => ['mode' => 'online'], 'back' => self::class, 'players' => 2,
            ])],
            ['OPTIONS', null, fn () => $this->scenes()->push(OptionsScene::class)],
        ];
        if (Platform::isDesktop()) {
            $items[] = ['QUIT', null, fn () => $this->engine->quit()];
        }
        return $items;
    }

    public function update(float $dt): void
    {
        $this->ball->update($dt);
        [$vx, $vy] = $this->ball->getVelocity();
        if ($this->ball->getFloatX() < 0 || $this->ball->getFloatX() > $this->width() - Ball::SIZE) {
            $this->ball->setVelocity(-$vx, $vy);
        }
        if ($this->ball->getFloatY() < 0 || $this->ball->getFloatY() > $this->height() - Ball::SIZE) {
            $this->ball->setVelocity($vx, -$vy);
        }
        parent::update($dt);
    }

    protected function renderBackground(Renderer $r): void
    {
        $r->clear([0, 0, 0]);
        $this->ball->render($r);
        $this->font()->draw($r, 'JUMPHP ' . date('Y'), $this->width() / 2, $this->height() - 12, 1, [70, 70, 70], 'center');
    }
}
