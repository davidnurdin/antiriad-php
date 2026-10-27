<?php

namespace JumPHP\Renderer;

use JumPHP\Util\Color;

/**
 * Draws the game at its virtual resolution (game.yaml width x height) into a render-target texture,
 * then scales it to the window with letterboxing and nearest-neighbour filtering: crisp retro pixels
 * at any window size.
 *
 * All coordinates are view pixels. The SDL binding has no SDL_SetRenderDrawBlendMode: a colour with
 * alpha < 255 is drawn with a cached 1x1 translucent texture instead (fillRect handles it).
 */
class Renderer
{
    private $renderer = null;
    private $viewTexture = null;
    private $currentTarget = null;
    private int $viewWidth;
    private int $viewHeight;
    private bool $integerScale = false;
    /** @var array<string, mixed> 1x1 textures of translucent colours */
    private array $alphaTextures = [];
    /** @var array{0:int,1:int,2:int,3:int} letterbox of the last frame */
    private array $letterbox = [0, 0, 0, 0];
    /** @var array<int, callable> drawn after the scaled view, at window resolution */
    private array $overlays = [];

    /** @param resource|object|null $window SDL window, or null with $surface (headless: software renderer) */
    public function __construct($window, int $viewWidth, int $viewHeight, bool $vsync = true, $surface = null)
    {
        \SDL_SetHint(\SDL_HINT_RENDER_SCALE_QUALITY, '0'); // nearest: crisp pixels
        if ($surface !== null) {
            $this->renderer = \SDL_CreateSoftwareRenderer($surface);
        } else {
            $flags = \SDL_RENDERER_ACCELERATED | ($vsync ? \SDL_RENDERER_PRESENTVSYNC : 0);
            $this->renderer = \SDL_CreateRenderer($window, -1, $flags);
            if (!$this->renderer) {
                // no GPU (VM, remote X): software rendering still works
                $this->renderer = \SDL_CreateRenderer($window, -1, \SDL_RENDERER_SOFTWARE);
            }
        }
        if (!$this->renderer) {
            throw new \RuntimeException('SDL_CreateRenderer failed: ' . \SDL_GetError());
        }
        $this->resizeView($viewWidth, $viewHeight);
    }

    /** The raw SDL renderer, for direct \SDL_* calls. */
    public function getRenderer()
    {
        return $this->renderer;
    }

    public function getWidth(): int
    {
        return $this->viewWidth;
    }

    public function getHeight(): int
    {
        return $this->viewHeight;
    }

    /** Whole-number scaling only (pixel-perfect, bigger black borders). */
    public function setIntegerScale(bool $on): void
    {
        $this->integerScale = $on;
    }

    /** New virtual resolution (zoom, other aspect ratio). */
    public function resizeView(int $width, int $height): void
    {
        if ($this->viewTexture) {
            \SDL_DestroyTexture($this->viewTexture);
        }
        $this->viewWidth = $width;
        $this->viewHeight = $height;
        $this->viewTexture = $this->createTargetTexture($width, $height);
    }

    public function createTargetTexture(int $width, int $height)
    {
        $texture = \SDL_CreateTexture($this->renderer, \SDL_PIXELFORMAT_RGBA8888, \SDL_TEXTUREACCESS_TARGET, $width, $height);
        if (!$texture) {
            throw new \RuntimeException("SDL_CreateTexture({$width}x{$height}) failed: " . \SDL_GetError());
        }
        return $texture;
    }

    public function getViewTexture()
    {
        return $this->viewTexture;
    }

    // --- frame -----------------------------------------------------------------------------------

    public function beginFrame(Color|array|string $background = [0, 0, 0]): void
    {
        $this->setTarget($this->viewTexture);
        $this->clear($background);
    }

    public function endFrame(): void
    {
        $this->setTarget(null);
        \SDL_SetRenderDrawColor($this->renderer, 0, 0, 0, 255);
        \SDL_RenderClear($this->renderer);
        [$x, $y, $w, $h] = $this->letterbox = $this->computeLetterbox();
        \SDL_RenderCopy($this->renderer, $this->viewTexture, null, new \SDL_Rect($x, $y, $w, $h));
        foreach ($this->overlays as $overlay) {
            $overlay($this);
        }
        \SDL_RenderPresent($this->renderer);
    }

    /** $draw(Renderer) runs after the view is scaled: draws at window resolution (sharp UI, debug). */
    public function addOverlay(callable $draw): int
    {
        $this->overlays[] = $draw;
        return array_key_last($this->overlays);
    }

    public function removeOverlay(int $id): void
    {
        unset($this->overlays[$id]);
    }

    /** Draws into $texture (null: the window); returns the previous target. */
    public function setTarget($texture)
    {
        $previous = $this->currentTarget;
        $this->currentTarget = $texture;
        \SDL_SetRenderTarget($this->renderer, $texture);
        return $previous;
    }

    public function getTarget()
    {
        return $this->currentTarget;
    }

    /** @return array{0:int,1:int} window (or screenshot) size in physical pixels */
    public function outputSize(): array
    {
        $w = 0;
        $h = 0;
        \SDL_GetRendererOutputSize($this->renderer, $w, $h);
        return [(int)$w, (int)$h];
    }

    /** @return array{0:int,1:int,2:int,3:int} where the view lands in the window: x, y, w, h */
    public function letterbox(): array
    {
        return $this->letterbox;
    }

    /** Window pixel -> view pixel (mouse, touch). */
    public function windowToView(float $px, float $py): array
    {
        [$x, $y, $w, $h] = $this->letterbox;
        if ($w === 0 || $h === 0) {
            return [$px, $py];
        }
        return [($px - $x) * $this->viewWidth / $w, ($py - $y) * $this->viewHeight / $h];
    }

    private function computeLetterbox(): array
    {
        [$ow, $oh] = $this->outputSize();
        $scale = min($ow / $this->viewWidth, $oh / $this->viewHeight);
        if ($this->integerScale && $scale >= 1) {
            $scale = floor($scale);
        }
        $w = (int)round($this->viewWidth * $scale);
        $h = (int)round($this->viewHeight * $scale);
        return [intdiv($ow - $w, 2), intdiv($oh - $h, 2), $w, $h];
    }

    // --- drawing -----------------------------------------------------------------------------------

    public function setColor(int $r, int $g, int $b, int $a = 255): void
    {
        \SDL_SetRenderDrawColor($this->renderer, $r, $g, $b, $a);
    }

    public function clear(Color|array|string $color = [0, 0, 0]): void
    {
        $c = Color::of($color);
        \SDL_SetRenderDrawColor($this->renderer, $c->r, $c->g, $c->b, 255);
        \SDL_RenderClear($this->renderer);
    }

    public function fillRect(float $x, float $y, float $w, float $h, Color|array|string $color): void
    {
        $c = Color::of($color);
        if ($c->a <= 0) {
            return;
        }
        $rect = new \SDL_Rect((int)round($x), (int)round($y), (int)round($w), (int)round($h));
        if ($c->a < 255) {
            \SDL_RenderCopy($this->renderer, $this->alphaTexture($c), null, $rect);
            return;
        }
        \SDL_SetRenderDrawColor($this->renderer, $c->r, $c->g, $c->b, 255);
        \SDL_RenderFillRect($this->renderer, $rect);
    }

    public function drawRect(float $x, float $y, float $w, float $h, Color|array|string $color, int $thickness = 1): void
    {
        $this->fillRect($x, $y, $w, $thickness, $color);
        $this->fillRect($x, $y + $h - $thickness, $w, $thickness, $color);
        $this->fillRect($x, $y + $thickness, $thickness, $h - 2 * $thickness, $color);
        $this->fillRect($x + $w - $thickness, $y + $thickness, $thickness, $h - 2 * $thickness, $color);
    }

    public function drawLine(float $x1, float $y1, float $x2, float $y2, Color|array|string $color): void
    {
        $c = Color::of($color);
        \SDL_SetRenderDrawColor($this->renderer, $c->r, $c->g, $c->b, 255);
        \SDL_RenderDrawLine($this->renderer, (int)round($x1), (int)round($y1), (int)round($x2), (int)round($y2));
    }

    public function drawPoint(float $x, float $y, Color|array|string $color): void
    {
        $c = Color::of($color);
        \SDL_SetRenderDrawColor($this->renderer, $c->r, $c->g, $c->b, 255);
        \SDL_RenderDrawPoint($this->renderer, (int)round($x), (int)round($y));
    }

    /** Filled circle (scanlines): balls, particles. */
    public function fillCircle(float $cx, float $cy, float $radius, Color|array|string $color): void
    {
        $r = (int)round($radius);
        for ($dy = -$r; $dy <= $r; $dy++) {
            $dx = (int)floor(sqrt($r * $r - $dy * $dy));
            $this->fillRect($cx - $dx, $cy + $dy, 2 * $dx + 1, 1, $color);
        }
    }

    /**
     * Draws a texture (or a part of it). $src = [x, y, w, h] in the texture, null for all of it.
     * $w / $h default to the source size.
     */
    public function copy($texture, ?array $src, float $x, float $y, ?float $w = null, ?float $h = null,
                         bool $flipH = false, bool $flipV = false, float $angle = 0.0): void
    {
        $srcRect = $src === null ? null : new \SDL_Rect((int)$src[0], (int)$src[1], (int)$src[2], (int)$src[3]);
        if ($w === null || $h === null) {
            if ($src !== null) {
                $w ??= $src[2];
                $h ??= $src[3];
            } else {
                $format = $access = $tw = $th = 0;
                \SDL_QueryTexture($texture, $format, $access, $tw, $th);
                $w ??= $tw;
                $h ??= $th;
            }
        }
        $dst = new \SDL_Rect((int)round($x), (int)round($y), (int)round($w), (int)round($h));
        if (!$flipH && !$flipV && $angle == 0.0) {
            \SDL_RenderCopy($this->renderer, $texture, $srcRect, $dst);
            return;
        }
        $flip = ($flipH ? \SDL_FLIP_HORIZONTAL : 0) | ($flipV ? \SDL_FLIP_VERTICAL : 0);
        \SDL_RenderCopyEx($this->renderer, $texture, $srcRect, $dst, $angle, null, $flip);
    }

    /** A 1x1 texture of a translucent colour, stretched by fillRect (the binding has no draw blend mode). */
    private function alphaTexture(Color $c)
    {
        // 16 alpha levels: enough for fades, a bounded cache
        $a = max(1, min(255, (int)(round($c->a / 17) * 17)));
        $key = $c->r . ',' . $c->g . ',' . $c->b . ',' . $a;
        if (isset($this->alphaTextures[$key])) {
            return $this->alphaTextures[$key];
        }
        $surface = \SDL_CreateRGBSurface(0, 1, 1, 32, 0x000000FF, 0x0000FF00, 0x00FF0000, -0x1000000);
        \SDL_FillRect($surface, new \SDL_Rect(0, 0, 1, 1), \SDL_MapRGBA($surface->format, $c->r, $c->g, $c->b, $a));
        $texture = \SDL_CreateTextureFromSurface($this->renderer, $surface);
        \SDL_FreeSurface($surface);
        if (count($this->alphaTextures) > 256) {
            foreach ($this->alphaTextures as $old) {
                \SDL_DestroyTexture($old);
            }
            $this->alphaTextures = [];
        }
        return $this->alphaTextures[$key] = $texture;
    }

    public function destroy(): void
    {
        foreach ($this->alphaTextures as $texture) {
            \SDL_DestroyTexture($texture);
        }
        $this->alphaTextures = [];
        if ($this->viewTexture) {
            \SDL_DestroyTexture($this->viewTexture);
        }
        if ($this->renderer) {
            \SDL_DestroyRenderer($this->renderer);
            $this->renderer = null;
        }
    }
}
