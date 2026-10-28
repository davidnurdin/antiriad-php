<?php

namespace App\Scene;

use JumPHP\Renderer\Renderer;
use JumPHP\Ui\MenuScene;
use JumPHP\Util\Lang;

/**
 * Over the frozen game when the ball falls or crashes: the score, then retry or the title screen.
 * Params: score, distance, gems, best, record.
 */
final class GameOverScene extends MenuScene
{
    protected string $title = 'GAME OVER';
    protected float $titleScale = 2.0;
    protected float $menuTop = 0.66;
    protected array $titleColor = [255, 90, 110];
    /** @var array<string, mixed> */
    private array $result = [];
    private float $time = 0.0;

    public function isOverlay(): bool
    {
        return true;
    }

    public function enter(array $params = []): void
    {
        $this->result = $params;
        $this->time = 0.0;
        parent::enter($params);
    }

    protected function items(): array
    {
        return [
            ['RETRY', null, fn () => $this->scenes()->switchTo(PlayScene::class, [], false)],
            ['TITLE SCREEN', null, fn () => $this->scenes()->switchTo(TitleScene::class)],
        ];
    }

    protected function back(): void
    {
        $this->scenes()->switchTo(TitleScene::class);
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        parent::update($dt);
    }

    protected function renderBackground(Renderer $r): void
    {
        $r->fillRect(0, 0, $this->width(), $this->height(), [0, 0, 0, 160]);
        $font = $this->font();
        $cx = $this->width() / 2;
        $y = $this->height() * 0.34;
        $font->draw($r, Lang::t('SCORE {n}', ['n' => $this->result['score'] ?? 0]), $cx, $y, 2, [255, 255, 255], 'center');
        $font->draw($r, Lang::t('{d} M  +  {g} GEMS', ['d' => $this->result['distance'] ?? 0, 'g' => $this->result['gems'] ?? 0]),
            $cx, $y + 20, 1, [200, 200, 230], 'center');
        if (!empty($this->result['record'])) {
            if (fmod($this->time, 0.6) < 0.4) {
                $font->draw($r, Lang::t('NEW RECORD!'), $cx, $y + 32, 1, [255, 220, 60], 'center');
            }
        } else {
            $font->draw($r, Lang::t('BEST {n}', ['n' => $this->result['best'] ?? 0]), $cx, $y + 32, 1, [255, 220, 60], 'center');
        }
    }
}
