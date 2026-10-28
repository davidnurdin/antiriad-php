<?php

namespace App\Game;

use JumPHP\Audio\SoundManager;
use JumPHP\Audio\Synth;

/**
 * Every sound of the game, generated in PHP (no audio file).
 */
final class Sounds
{
    public static function load(SoundManager $sound): void
    {
        if ($sound->hasSfx('jump')) {
            return;
        }
        $sound->synth('menu_move', Synth::tone(660, 0.04));
        $sound->synth('menu_confirm', Synth::sequence([[660, 0.05], [990, 0.08]]));
        $sound->synth('jump', Synth::sweep(260, 720, 0.14, 'square', 0.35));
        $sound->synth('land', Synth::tone(110, 0.05, 'triangle', 0.5));
        $sound->synth('gem', Synth::sequence([[1320, 0.04], [1760, 0.07]], 'square', 0.3));
        $sound->synth('fall', Synth::sweep(700, 60, 0.7, 'triangle', 0.5));
        $sound->synth('crash', Synth::noise(0.35, 0.5));
        $sound->synth('milestone', Synth::sequence([[523, 0.06], [659, 0.06], [784, 0.06], [1047, 0.12]], 'square', 0.3));
    }
}
