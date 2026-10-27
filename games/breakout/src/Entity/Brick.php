<?php

namespace App\Entity;

use JumPHP\Entity\Entity;
use JumPHP\Renderer\Renderer;

/**
 * One brick of the wall. Coloured bricks break at the first hit, silver ones need two.
 */
final class Brick extends Entity
{
    public const WIDTH = 22;
    public const HEIGHT = 8;

    /** brick letter => [colour, points] */
    public const KINDS = [
        'r' => [[230, 60, 60], 70],
        'o' => [[240, 140, 40], 60],
        'y' => [[230, 210, 50], 50],
        'g' => [[70, 200, 80], 40],
        'b' => [[60, 130, 230], 30],
        'p' => [[170, 80, 220], 20],
        'S' => [[180, 180, 195], 100],
    ];

    public int $hits;
    public int $points;
    /** @var int[] */
    private array $color;

    public function __construct(float $x, float $y, public readonly string $kind)
    {
        $this->x = $x;
        $this->y = $y;
        $this->width = self::WIDTH;
        $this->height = self::HEIGHT;
        [$this->color, $this->points] = self::KINDS[$kind];
        $this->hits = $kind === 'S' ? 2 : 1;
    }

    /** One hit of the ball: true when the brick breaks. */
    public function hit(): bool
    {
        $this->hits--;
        if ($this->hits <= 0) {
            $this->kill();
            return true;
        }
        return false;
    }

    public function render(Renderer $r, float $camX = 0, float $camY = 0): void
    {
        [$red, $green, $blue] = $this->color;
        if ($this->kind === 'S' && $this->hits === 1) {
            [$red, $green, $blue] = [120, 120, 135];      // cracked
        }
        $r->fillRect($this->x, $this->y, $this->width, $this->height, [$red, $green, $blue]);
        $r->fillRect($this->x, $this->y, $this->width, 1, [min(255, $red + 60), min(255, $green + 60), min(255, $blue + 60)]);
        $r->fillRect($this->x, $this->y + $this->height - 1, $this->width, 1, [(int)($red * 0.55), (int)($green * 0.55), (int)($blue * 0.55)]);
    }
}
