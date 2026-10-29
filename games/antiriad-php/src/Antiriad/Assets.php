<?php

namespace App\Antiriad;

use JumPHP\Kernel\Engine;

/**
 * Loads the game's data, font and sounds once (the scenes are reused); the original font becomes the
 * engine's font, so menus use it too.
 */
final class Assets
{
    private static ?Gfx $gfx = null;
    private static ?CpcFont $font = null;

    private const SFX = ['shoot', 'pickup', 'hurt', 'hit', 'step', 'beam', 'suit', 'message'];

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
            $sound->loadSfx('menu_move', 'assets/sfx/step.ogg');
            $sound->loadSfx('menu_confirm', 'assets/sfx/pickup.ogg');
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
