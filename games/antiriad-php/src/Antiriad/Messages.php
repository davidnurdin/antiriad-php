<?php

namespace App\Antiriad;

use JumPHP\Util\Lang;

/**
 * The original's messages (#A093), by number. The disc is the Spanish edition: in Spanish the remake shows
 * its very texts; English is the source of the translations (config/lang.yaml: French).
 */
final class Messages
{
    private const EN = [
        0 => '',
        1 => 'ALREADY CARRYING THE RECHARGE ELEMENT',
        2 => 'THREE REJUVENATIONS LEFT',
        3 => 'TWO REJUVENATIONS LEFT',
        4 => 'ONE REJUVENATION LEFT',
        5 => 'THIS IS YOUR LAST REJUVENATION',
        6 => 'YOU ARE DEAD YOUR RACE HAS NO SALVATION PRESS FIRE TO PLAY AGAIN',
        7 => 'BEAM ME UP SCOTTY',
        8 => 'GRAVITY DISPLACER ACTIVATED SUIT MOBILIZED',
        9 => 'PULSE BEAM ACTIVATED READY TO FIRE',
        10 => 'PARTICLE NEGATOR OPERATIONAL SHIELDING AT MAXIMUM POWER',
        11 => 'IMPLOSION MINE READY CAUTION HIGH RADIATION WILL DETONATE',
        12 => 'RECHARGE CELL',
        13 => 'GRAVITY PREVENTS FLIGHT',
        14 => 'THE PULSE BEAM IS MISSING',
        15 => 'DANGER SUIT ENERGY LOW',
        16 => 'SUIT INOPERATIVE RECHARGE ELEMENT WEAKENED',
        17 => 'ANTI-RAD SUIT ACTIVE',
        18 => 'EXCESSIVE RADIATION SHIELDING FAILURE IMMINENT',
        19 => 'THE IMPLOSION MINE DETONATION CHAIN HAS STARTED    FIVE    FOUR    THREE    TWO    ONE    ZERO',
        20 => 'THE OPPRESSORS ARE DEFEATED YOUR RACE IS FREE PRESS FIRE TO PLAY AGAIN',
        21 => 'HIGH RADIATION SAFETY GEAR OPERATIONAL',
    ];

    public static function text(World $world, int $id, string $lang): string
    {
        if ($lang === 'es') {
            $text = $world->messages[$id] ?? '';
            // message 20 runs into 21 on the disc (no '$' between them)
            return trim(str_replace("\n", ' ', $id === 20 ? explode('NUEVAMENTE', $text)[0] . 'NUEVAMENTE' : $text));
        }
        return Lang::t(self::EN[$id] ?? '');
    }
}
