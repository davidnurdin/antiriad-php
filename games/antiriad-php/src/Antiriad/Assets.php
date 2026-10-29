<?php

namespace App\Antiriad;

use JumPHP\Audio\Synth;
use JumPHP\Kernel\Engine;

/**
 * Loads the game's data, font and sounds once (the scenes are reused); the original font becomes the
 * engine's font, so menus use it too.
 */
final class Assets
{
    private static ?Gfx $gfx = null;
    private static ?CpcFont $font = null;

    private const SFX = ['shoot', 'pickup', 'hurt', 'hit', 'step', 'beam', 'suit', 'message',
        'enter', 'leave', 'activate', 'teleport', 'saucer'];

    public static function boot(Engine $engine): World
    {
        $world = World::load($engine->config->path('assets/data/world.json'));
        if (self::$font === null) {
            self::$gfx = new Gfx($engine->sdl, $world);
            self::$font = new CpcFont($engine->sdl, $world);
            $engine->setFont(self::$font);
            $sound = $engine->sound;
            foreach (self::SFX as $name) {
                $sound->loadSfx($name, "assets/sfx/$name.ogg");
            }
            // menus: soft short notes (the game's own effects are too harsh there)
            $sound->synth('menu_move', Synth::tone(660, 0.04, 'triangle', 0.25));
            $sound->synth('menu_confirm', Synth::sequence([[523, 0.05], [784, 0.08]], 'triangle', 0.3));
            foreach (['title', 'death', 'victory'] as $name) {
                $sound->loadMusic($name, "assets/music/$name.ogg");
            }
        }
        return $world;
    }

    public static function gfx(): Gfx
    {
        return self::$gfx ?? throw new \LogicException('Assets::boot() first');
    }

    public static function font(): CpcFont
    {
        return self::$font ?? throw new \LogicException('Assets::boot() first');
    }
}
