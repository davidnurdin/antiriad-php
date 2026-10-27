<?php

namespace App\Level;

/**
 * The brick walls, one per level (they loop, faster, after the last one).
 * One string per row, one character per column (13 columns):
 *   '.' empty, 'r' 'o' 'y' 'g' 'b' 'p' a coloured brick (1 hit), 'S' a silver brick (2 hits).
 */
final class Layouts
{
    public const COLUMNS = 13;

    public const LEVELS = [
        [   // 1: the classic wall
            'rrrrrrrrrrrrr',
            'ooooooooooooo',
            'yyyyyyyyyyyyy',
            'ggggggggggggg',
            'bbbbbbbbbbbbb',
            'ppppppppppppp',
        ],
        [   // 2: a pyramid with a silver top
            '......S......',
            '.....SrS.....',
            '....ooooo....',
            '...yyyyyyy...',
            '..ggggggggg..',
            '.bbbbbbbbbbb.',
            'ppppppppppppp',
        ],
        [   // 3: columns and a silver bar
            'r.o.y.g.b.p.r',
            'r.o.y.g.b.p.r',
            'r.o.y.g.b.p.r',
            'SSSS.....SSSS',
            '.............',
            'ggg.bbbbb.ggg',
            'ppp.yyyyy.ppp',
        ],
        [   // 4: a checkerboard
            'r.r.r.r.r.r.r',
            '.o.o.o.o.o.o.',
            'y.y.y.y.y.y.y',
            '.g.g.g.g.g.g.',
            'b.b.b.b.b.b.b',
            '.p.p.p.p.p.p.',
            'SSSSSSSSSSSSS',
        ],
    ];

    /** @return string[] the rows of level $level (1-based, loops) */
    public static function rows(int $level): array
    {
        return self::LEVELS[($level - 1) % count(self::LEVELS)];
    }
}
