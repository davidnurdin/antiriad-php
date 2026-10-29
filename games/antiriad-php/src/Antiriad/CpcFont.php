<?php

namespace App\Antiriad;

use JumPHP\Renderer\BitmapFont;
use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\Sdl;
use JumPHP\Renderer\TextDrawer;
use JumPHP\Util\Color;

/**
 * The original's 8 x 8 font (mode 1, #0400): capitals, digits and a few signs. The engine's pixel font
 * draws the characters it lacks (%, :, '...), accents are dropped.
 */
final class CpcFont implements TextDrawer
{
    private const ACCENTS = ['À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ñ' => 'N', '_' => '-'];

    /** @var array<string, mixed> one glyph strip per colour */
    private array $strips = [];
    private BitmapFont $fallback;

    public function __construct(private Sdl $sdl, private World $world)
    {
        $this->fallback = new BitmapFont($sdl);
    }

    private function strip(Color $c)
    {
        $key = 'cpcfont-' . $c->key();
        if (!isset($this->strips[$key])) {
            $rows = array_fill(0, 8, '');
            foreach ($this->world->font['glyphs'] as $glyph) {
                foreach ($glyph as $y => $line) {
                    $rows[$y] .= $line;
                }
            }
            $this->sdl->createTextureFromPixels($key, $rows, ['#' => $c]);
            $this->strips[$key] = $this->sdl->getTexture($key)['texture'];
        }
        return $this->strips[$key];
    }

    private function normalize(string $text): string
    {
        return strtr(mb_strtoupper($text, 'UTF-8'), self::ACCENTS);
    }

    public function draw(Renderer $r, string $text, float $x, float $y, float $scale = 1, Color|array|string $color = [255, 255, 255],
                         string $align = 'left', Color|array|string|null $shadow = null): void
    {
        if ($shadow !== null) {
            $this->draw($r, $text, $x + $scale, $y + $scale, $scale, $shadow, $align);
        }
        $c = Color::of($color);
        $strip = $this->strip($c);
        $size = 8 * $scale;
        foreach (explode("\n", $this->normalize($text)) as $n => $line) {
            $cx = $x;
            if ($align !== 'left') {
                $w = $this->width($line, $scale);
                $cx -= $align === 'center' ? $w / 2 : $w;
            }
            $cy = $y + $n * $this->lineHeight($scale);
            foreach (mb_str_split($line, 1, 'UTF-8') as $ch) {
                $code = $this->world->font['chars'][$ch] ?? null;
                if ($code !== null) {
                    if ($code !== 0) {
                        $r->copy($strip, [$code * 8, 0, 8, 8], $cx, $cy, $size, $size);
                    }
                } else {
                    $this->fallback->draw($r, $ch, $cx + $scale, $cy, $scale, $c);
                }
                $cx += $size;
            }
        }
    }

    /** Glyph $code (0-127: #6FBE draws the dashboard's graphics with codes 64-127) at view pixels x, y. */
    public function glyph(Renderer $r, int $code, float $x, float $y, Color|array|string $color): void
    {
        $r->copy($this->strip(Color::of($color)), [$code * 8, 0, 8, 8], $x, $y, 8, 8);
    }

    public function width(string $text, float $scale = 1): int
    {
        $lines = explode("\n", $this->normalize($text));
        return (int)round(max(array_map(fn ($l) => mb_strlen($l, 'UTF-8'), $lines)) * 8 * $scale);
    }

    public function height(float $scale = 1): int
    {
        return (int)round(8 * $scale);
    }

    public function lineHeight(float $scale = 1): int
    {
        return (int)round(10 * $scale);
    }
}
