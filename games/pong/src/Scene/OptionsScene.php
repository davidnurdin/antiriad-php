<?php

namespace App\Scene;

use JumPHP\Kernel\Platform;
use JumPHP\Ui\MenuScene;

/**
 * Settings, saved in var/options.json (IndexedDB in the browser).
 */
final class OptionsScene extends MenuScene
{
    protected string $title = 'OPTIONS';
    protected float $titleScale = 2.0;
    protected float $menuTop = 0.35;

    private const POINTS = [3, 5, 7, 11, 15];
    private const LEVELS = ['easy', 'normal', 'hard'];

    protected function items(): array
    {
        $options = $this->engine->options;
        $sound = $this->sound();
        $cycle = function (string $key, array $values, mixed $default, int $dir) use ($options): void {
            $i = array_search($options->get($key, $default), $values, true);
            $options->set($key, $values[((int)$i + $dir + count($values)) % count($values)]);
        };
        $items = [
            ['SOUND', fn () => round($sound->getSfxVolume() * 100) . '%', null, function (int $dir) use ($sound, $options): void {
                $sound->setSfxVolume(round($sound->getSfxVolume() * 10 + $dir) / 10);
                $options->set('sfx_volume', $sound->getSfxVolume());
                $sound->playSfx('hit');
            }],
            ['POINTS TO WIN', fn () => (string)$options->get('points', 7), null, fn (int $d) => $cycle('points', self::POINTS, 7, $d)],
            ['COMPUTER', fn () => \JumPHP\Util\Lang::t(strtoupper((string)$options->get('cpu', 'normal'))), null,
                fn (int $d) => $cycle('cpu', self::LEVELS, 'normal', $d)],
            ['LANGUAGE', fn () => strtoupper($this->engine->lang->getLang()), null, function (int $dir) use ($options): void {
                $langs = $this->engine->lang->available();
                $i = array_search($this->engine->lang->getLang(), $langs, true);
                $lang = $langs[((int)$i + $dir + count($langs)) % count($langs)];
                $this->engine->lang->setLang($lang);
                $options->set('lang', $lang);
            }],
        ];
        if (Platform::isDesktop()) {
            $items[] = ['FULLSCREEN', fn () => $options->get('fullscreen') ? 'ON' : 'OFF', null, fn () => $this->engine->toggleFullscreen()];
        }
        $items[] = ['BACK', null, fn () => $this->back()];
        return $items;
    }

    protected function back(): void
    {
        $this->scenes()->pop();
    }
}
