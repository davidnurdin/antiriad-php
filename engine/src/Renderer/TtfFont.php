<?php

namespace JumPHP\Renderer;

use JumPHP\Util\Color;

/**
 * Text with a TrueType font of the game (assets/fonts/*.ttf), rendered once per (text, colour) and
 * cached as a texture (LRU): drawing the same score every frame costs one SDL_RenderCopy.
 *
 *   $font = new TtfFont($sdl, 'assets/fonts/pixel.ttf', 8);   // size in view pixels
 *   $font->draw($r, 'Hello', 10, 10);
 *
 * $smooth = false renders "solid" (no anti-aliasing): best for pixel fonts.
 */
class TtfFont implements TextDrawer
{
    private const CACHE_SIZE = 128;

    private static bool $ttfReady = false;
    private $font;
    /** @var array<string, array{texture: mixed, w: int, h: int}> */
    private array $cache = [];

    public function __construct(private Sdl $sdl, string $path, private int $size, private bool $smooth = false)
    {
        if (!self::$ttfReady) {
            \TTF_Init();
            self::$ttfReady = true;
        }
        $file = \JumPHP\Kernel\Platform::isAbsolutePath($path) ? $path : $this->resolve($path);
        $this->font = \TTF_OpenFont($file, $size);
        if (!$this->font) {
            throw new \RuntimeException("TTF_OpenFont($file) failed: " . \SDL_GetError());
        }
    }

    public function draw(Renderer $r, string $text, float $x, float $y, float $scale = 1, Color|array|string $color = [255, 255, 255],
                         string $align = 'left', Color|array|string|null $shadow = null): void
    {
        if ($shadow !== null) {
            $this->draw($r, $text, $x + $scale, $y + $scale, $scale, $shadow, $align);
        }
        foreach (explode("\n", $text) as $n => $line) {
            if ($line === '') {
                continue;
            }
            $entry = $this->render($line, Color::of($color));
            $w = $entry['w'] * $scale;
            $lineX = $align === 'center' ? $x - $w / 2 : ($align === 'right' ? $x - $w : $x);
            $r->copy($entry['texture'], null, $lineX, $y + $n * $this->lineHeight($scale), $w, $entry['h'] * $scale);
        }
    }

    public function width(string $text, float $scale = 1): int
    {
        $max = 0;
        foreach (explode("\n", $text) as $line) {
            $w = $h = 0;
            \TTF_SizeUTF8($this->font, $line, $w, $h);
            $max = max($max, (int)$w);
        }
        return (int)round($max * $scale);
    }

    public function height(float $scale = 1): int
    {
        return (int)round(\TTF_FontHeight($this->font) * $scale);
    }

    public function lineHeight(float $scale = 1): int
    {
        return (int)round(\TTF_FontLineSkip($this->font) * $scale);
    }

    private function render(string $text, Color $color): array
    {
        $key = $color->key() . '|' . $text;
        if (isset($this->cache[$key])) {
            // LRU: move to the end
            $entry = $this->cache[$key];
            unset($this->cache[$key]);
            return $this->cache[$key] = $entry;
        }
        $sdlColor = new \SDL_Color($color->r, $color->g, $color->b, $color->a);
        $surface = $this->smooth
            ? \TTF_RenderUTF8_Blended($this->font, $text, $sdlColor)
            : \TTF_RenderUTF8_Solid($this->font, $text, $sdlColor);
        $texture = \SDL_CreateTextureFromSurface($this->sdl->getRenderer()->getRenderer(), $surface);
        $format = $access = $w = $h = 0;
        \SDL_QueryTexture($texture, $format, $access, $w, $h);
        \SDL_FreeSurface($surface);

        if (count($this->cache) >= self::CACHE_SIZE) {
            $oldest = array_key_first($this->cache);
            \SDL_DestroyTexture($this->cache[$oldest]['texture']);
            unset($this->cache[$oldest]);
        }
        return $this->cache[$key] = ['texture' => $texture, 'w' => (int)$w, 'h' => (int)$h];
    }

    private function resolve(string $path): string
    {
        $root = defined('JUMPHP_ROOT') ? JUMPHP_ROOT : getcwd();
        return rtrim((string)$root, '/') . '/' . $path;
    }
}
