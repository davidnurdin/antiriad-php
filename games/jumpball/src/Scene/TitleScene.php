<?php

namespace App\Scene;

use App\Game\Best;
use App\Game\Run;
use App\Game\Sounds;
use App\Gfx\World;
use JumPHP\Kernel\Platform;
use JumPHP\Loop\FixedStep;
use JumPHP\Renderer\Renderer;
use JumPHP\Ui\MenuScene;
use JumPHP\Util\Lang;

/**
 * First screen: the menu over a run played by the bot, in 3D.
 */
final class TitleScene extends MenuScene
{
    protected string $title = 'JUMPBALL';
    protected array $titleColor = [255, 220, 60];
    protected float $menuTop = 0.5;

    private Run $demo;
    private FixedStep $step;
    private int $best = 0;
    private int $seed = 1;

    public function __construct(private World $world)
    {
    }

    public function enter(array $params = []): void
    {
        Sounds::load($this->sound());
        parent::enter($params);
        $this->demo = new Run($this->seed++);
        $this->step = new FixedStep(120);
        $this->best = Best::load($this->engine->storage);
        $this->world->prepare($this->engine->gl());
        $pwa = $this->engine->pwa();
        $pwa->removeAllListeners();
        foreach (['installable', 'installed', 'update'] as $event) {
            $pwa->on($event, fn () => $this->refresh());   // the menu shows INSTALL / UPDATE when possible
        }
    }

    protected function items(): array
    {
        $items = [
            ['PLAY', null, fn () => $this->scenes()->switchTo(PlayScene::class)],
            ['OPTIONS', null, fn () => $this->scenes()->push(OptionsScene::class)],
        ];
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
        $this->step->run($dt, function (float $h): void {
            [$steer, $jump] = $this->demo->autopilot();
            $this->demo->step($h, $steer, $jump);
        });
        $this->demo->events = [];
        if ($this->demo->state === 'over') {
            $this->demo = new Run($this->seed++);
        }
        parent::update($dt);
    }

    protected function renderBackground(Renderer $r): void
    {
        $this->world->render($this->engine, $r, $this->demo);
        $r->fillRect(0, 0, $this->width(), $this->height(), [0, 0, 0, 70]);   // a darker key: a darker 3D
    }

    public function render(Renderer $r): void
    {
        parent::render($r);
        $font = $this->font();
        $cx = $this->width() / 2;
        $font->draw($r, 'P H P   +   W E B G L', $cx, $this->height() * 0.15 + 25, 1, [120, 230, 255], 'center', [0, 0, 0]);
        if ($this->best > 0) {
            $font->draw($r, Lang::t('BEST {n}', ['n' => $this->best]), $cx, $this->height() - 22, 1, [255, 220, 60], 'center', [0, 0, 0]);
        }
        $font->draw($r, Lang::t('ARROWS STEER   {jump} JUMP', ['jump' => $this->actions()->label('jump')]),
            $cx, $this->height() - 11, 1, [200, 200, 230], 'center', [0, 0, 0]);
    }
}
