<?php

namespace App\Scene;

use JumPHP\Ui\MenuScene;

/**
 * End of a match, over the final score. Params: 'text' (who won), 'again' (PlayScene params, null online).
 */
final class GameOverScene extends MenuScene
{
    protected float $titleScale = 2.0;
    protected float $menuTop = 0.55;
    private ?array $again = null;

    public function isOverlay(): bool
    {
        return true;
    }

    public function enter(array $params = []): void
    {
        $this->title = (string)($params['text'] ?? '');
        $this->again = $params['again'] ?? null;
        parent::enter($params);
    }

    protected function items(): array
    {
        $items = [];
        if ($this->again !== null) {
            $items[] = ['PLAY AGAIN', null, fn () => $this->scenes()->switchTo(PlayScene::class, $this->again)];
        }
        $items[] = ['TITLE SCREEN', null, function (): void {
            $this->engine->net()->close();
            $this->scenes()->switchTo(TitleScene::class);
        }];
        return $items;
    }
}
