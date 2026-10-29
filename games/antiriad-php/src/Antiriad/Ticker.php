<?php

namespace App\Antiriad;

use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\TextDrawer;

/**
 * The dashboard's message line: texts scroll from right to left, one after the other (#5EA0 queues
 * up to 8 of them and never twice the same in a row).
 */
final class Ticker
{
    private const SPEED = 4 / Game::TICK;   // #5EA0: a byte (4 view pixels) each turn of the main loop

    /** @var list<string> */
    private array $queue = [];
    private ?string $current = null;
    private float $x = 0;

    public function __construct(private float $left, private float $right, private float $y)
    {
    }

    public function push(string $text): void
    {
        if ($text === '' || $text === $this->current || end($this->queue) === $text || count($this->queue) >= 8) {
            return;
        }
        $this->queue[] = $text;
    }

    public function clear(): void
    {
        $this->queue = [];
        $this->current = null;
    }

    public function busy(): bool
    {
        return $this->current !== null || $this->queue !== [];
    }

    public function update(float $dt, TextDrawer $font): bool
    {
        $started = false;
        if ($this->current === null && $this->queue !== []) {
            $this->current = array_shift($this->queue);
            $this->x = $this->right;
            $started = true;
        }
        if ($this->current !== null) {
            $this->x -= self::SPEED * $dt;
            if ($this->x + $font->width($this->current) < $this->left) {
                $this->current = null;
            }
        }
        return $started;
    }

    public function render(Renderer $r, TextDrawer $font, array $color): void
    {
        if ($this->current === null) {
            return;
        }
        // draw only the characters inside the window
        $x = $this->x;
        foreach (mb_str_split($this->current, 1, 'UTF-8') as $ch) {
            if ($x >= $this->left - 0.5 && $x + 8 <= $this->right + 0.5) {
                $font->draw($r, $ch, $x, $this->y, 1, $color);
            }
            $x += 8;
        }
    }
}
