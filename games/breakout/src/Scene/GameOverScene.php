<?php

namespace App\Scene;

use JumPHP\Renderer\Renderer;
use JumPHP\Ui\MenuScene;
use JumPHP\Util\Lang;

/**
 * Over the last frame of the game. Params: 'score', 'level', 'record' (bool), 'again' (PlayScene params).
 */
final class GameOverScene extends MenuScene
{
    protected string $title = 'GAME OVER';
    protected float $titleScale = 2.0;
    protected float $menuTop = 0.6;
    private array $result = [];

    public function isOverlay(): bool
    {
        return true;
    }

    public function enter(array $params = []): void
    {
        $this->result = $params;
        $this->title = Lang::t('GAME OVER');
        parent::enter($params);
    }

    protected function items(): array
    {
        return [
            ['PLAY AGAIN', null, fn () => $this->scenes()->switchTo(PlayScene::class, $this->result['again'] ?? [])],
            ['TITLE SCREEN', null, fn () => $this->scenes()->switchTo(TitleScene::class)],
        ];
    }

    protected function back(): void
    {
        $this->scenes()->switchTo(TitleScene::class);
    }

    protected function renderBackground(Renderer $r): void
    {
        parent::renderBackground($r);
        $font = $this->font();
        $cx = $this->width() / 2;
        $font->draw($r, Lang::t('SCORE {n}', ['n' => $this->result['score'] ?? 0]), $cx, $this->height() * 0.34, 2, [255, 255, 255], 'center');
        $font->draw($r, Lang::t('LEVEL {n}', ['n' => $this->result['level'] ?? 1]), $cx, $this->height() * 0.44, 1, [180, 180, 180], 'center');
        if (!empty($this->result['record'])) {
            $font->draw($r, Lang::t('NEW RECORD!'), $cx, $this->height() * 0.50, 1, [255, 220, 80], 'center');
        }
    }
}
