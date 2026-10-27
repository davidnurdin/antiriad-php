<?php

namespace JumPHP\Renderer;

use JumPHP\Kernel\GameConfig;
use JumPHP\Kernel\Platform;

/**
 * SDL facade: window, renderer and textures by name.
 *
 * A texture entry is a list of slices ['texture', 'width', 'height', 'x']: images wider than 4064 px are
 * cut into slices (WebGL limit), so most images have one slice: getTexture($name) returns it.
 *
 *   $sdl->loadTexture('player', 'assets/sprites/player.png');          // now
 *   $sdl->registerTexture('level2', 'assets/tiles/level2.png', 'lvl2');  // on first use, or loadGroup('lvl2')
 *   $sdl->createTextureFromPixels('ball', ['.##.', '####', '####', '.##.'], ['#' => '#fff']); // code-only art
 */
class Sdl
{
    public const MAX_TEXTURE_WIDTH = 4064;

    private ?Window $window = null;
    private ?Renderer $renderer = null;
    private $headlessSurface = null;

    /** @var array<string, array<int, array{texture: mixed, width: int, height: int, x: int}>> */
    private array $textures = [];
    /** @var array<string, array{path: string, group: ?string, transparent: ?array}> */
    private array $registered = [];
    private string $variant = '';

    public function __construct(private GameConfig $config)
    {
    }

    /** Opens the window and the renderer (Engine::boot() calls it with the game.yaml values). */
    public function initSDL(bool $fullscreen, string $title, int $width, int $height, bool $vsync = true, int $scale = 3): void
    {
        if (\SDL_Init(\SDL_INIT_VIDEO | \SDL_INIT_AUDIO | \SDL_INIT_JOYSTICK) < 0) {
            // no audio device or joystick subsystem: video alone is enough to play
            if (\SDL_Init(\SDL_INIT_VIDEO) < 0) {
                throw new \RuntimeException('SDL_Init failed: ' . \SDL_GetError());
            }
        }

        if (Platform::isHeadless()) {
            // smoke tests and screenshots: software renderer drawing into a surface, no window
            $this->headlessSurface = \SDL_CreateRGBSurface(0, $width * 2, $height * 2, 32, 0x00FF0000, 0x0000FF00, 0x000000FF, 0);
            $this->renderer = new Renderer(null, $width, $height, false, $this->headlessSurface);
            return;
        }

        $this->window = new Window($width * $scale, $height * $scale, $title, $fullscreen);
        $this->renderer = new Renderer($this->window->getWindow(), $width, $height, $vsync);
    }

    public function getRenderer(): Renderer
    {
        return $this->renderer ?? throw new \LogicException('SDL is not initialised: Sdl::initSDL() first');
    }

    public function getWindow(): ?Window
    {
        return $this->window;
    }

    public function isReady(): bool
    {
        return $this->renderer !== null;
    }

    /** Headless only: writes what was last presented to a PNG file. */
    public function saveScreenshot(string $pngFile): bool
    {
        if ($this->headlessSurface === null) {
            return false;
        }
        $bmp = sys_get_temp_dir() . '/jumphp-shot-' . getmypid() . '.bmp';
        \SDL_SaveBMP($this->headlessSurface, $bmp);
        $image = imagecreatefrombmp($bmp);
        @unlink($bmp);
        if ($image === false) {
            return false;
        }
        @mkdir(dirname($pngFile), 0777, true);
        return imagepng($image, $pngFile);
    }

    // --- textures ----------------------------------------------------------------------------------

    /** @param array{0:int,1:int,2:int}|null $transparent colour key: this RGB colour becomes transparent */
    public function loadTexture(string $name, string $path, ?array $transparent = null): array
    {
        $file = $this->resolve($path);
        if (!is_file($file)) {
            throw new \RuntimeException("Texture '$name': file not found: $file");
        }
        $this->releaseTexture($name);

        $size = @getimagesize($file);
        $width = $size ? (int)$size[0] : 0;
        $files = $width > self::MAX_TEXTURE_WIDTH ? $this->slice($file, $name) : [[$file, 0]];

        $slices = [];
        foreach ($files as [$sliceFile, $x]) {
            $surface = \IMG_Load($sliceFile);
            if (!$surface) {
                throw new \RuntimeException("IMG_Load($sliceFile) failed: " . \SDL_GetError());
            }
            if ($transparent !== null) {
                \SDL_SetColorKey($surface, 1, \SDL_MapRGB($surface->format, $transparent[0], $transparent[1], $transparent[2]));
            }
            $slices[] = $this->surfaceToSlice($surface, $x);
            \SDL_FreeSurface($surface);
        }

        return $this->textures[$name] = $slices;
    }

    /** Declares a texture loaded on first use (getTextures) or with its group (loadGroup). */
    public function registerTexture(string $name, string $path, ?string $group = null, ?array $transparent = null): void
    {
        $this->registered[$name] = ['path' => $path, 'group' => $group, 'transparent' => $transparent];
    }

    public function loadGroup(string $group): void
    {
        foreach ($this->registered as $name => $info) {
            if ($info['group'] === $group && !isset($this->textures[$name])) {
                $this->loadTexture($name, $info['path'], $info['transparent']);
            }
        }
    }

    /** Frees the textures of every group but $group (keeps the memory of one level at a time). */
    public function releaseGroupsExcept(?string $group): void
    {
        foreach ($this->registered as $name => $info) {
            if ($info['group'] !== null && $info['group'] !== $group) {
                $this->releaseTexture($name);
            }
        }
    }

    public function releaseTexture(string $name): void
    {
        foreach ($this->textures[$name] ?? [] as $slice) {
            \SDL_DestroyTexture($slice['texture']);
        }
        unset($this->textures[$name]);
    }

    /** "-water": getTextures('tiles') returns 'tiles-water' when it exists. '' to stop. */
    public function setTextureVariant(string $suffix): void
    {
        $this->variant = $suffix;
    }

    public function hasTexture(string $name): bool
    {
        return isset($this->textures[$name]) || isset($this->registered[$name]);
    }

    /** @return array<int, array{texture: mixed, width: int, height: int, x: int}> */
    public function getTextures(string $name): array
    {
        if ($this->variant !== '' && $this->hasTexture($name . $this->variant)) {
            $name .= $this->variant;
        }
        if (!isset($this->textures[$name])) {
            if (!isset($this->registered[$name])) {
                throw new \RuntimeException("Unknown texture '$name' (loadTexture / registerTexture / createTexture* first)");
            }
            $info = $this->registered[$name];
            $this->loadTexture($name, $info['path'], $info['transparent']);
        }
        return $this->textures[$name];
    }

    /** @return array{texture: mixed, width: int, height: int, x: int} */
    public function getTexture(string $name): array
    {
        return $this->getTextures($name)[0];
    }

    /** An empty render-target texture you can draw into (Renderer::setTarget). */
    public function createTexture(string $name, int $width, int $height): array
    {
        $this->releaseTexture($name);
        $texture = $this->getRenderer()->createTargetTexture($width, $height);
        return $this->textures[$name] = [['texture' => $texture, 'width' => $width, 'height' => $height, 'x' => 0]];
    }

    public function createTextureFromSurface(string $name, $surface): array
    {
        $this->releaseTexture($name);
        return $this->textures[$name] = [$this->surfaceToSlice($surface, 0)];
    }

    /** A GD image (drawn in PHP) as a texture; keeps its alpha channel. */
    public function createTextureFromGd(string $name, \GdImage $image): array
    {
        imagesavealpha($image, true);
        $file = $this->tempDir() . '/' . preg_replace('/[^\w.-]/', '_', $name) . '.png';
        imagepng($image, $file);
        $slices = $this->loadTexture($name, $file);
        @unlink($file);
        return $slices;
    }

    /**
     * Pixel art from strings: one string per row, one character per pixel, '.' or ' ' = transparent.
     *   createTextureFromPixels('heart', ['.#.#.', '#####', '.###.', '..#..'], ['#' => '#e33'], 2)
     */
    public function createTextureFromPixels(string $name, array $rows, array $palette, int $scale = 1): array
    {
        $height = count($rows);
        $width = max(array_map('strlen', $rows));
        $image = imagecreatetruecolor($width * $scale, $height * $scale);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        $colors = [];
        foreach ($palette as $char => $color) {
            $c = \JumPHP\Util\Color::of($color);
            $colors[$char] = imagecolorallocatealpha($image, $c->r, $c->g, $c->b, 127 - intdiv($c->a, 2));
        }
        foreach (array_values($rows) as $y => $row) {
            for ($x = 0, $n = strlen($row); $x < $n; $x++) {
                $char = $row[$x];
                if (isset($colors[$char])) {
                    imagefilledrectangle($image, $x * $scale, $y * $scale, ($x + 1) * $scale - 1, ($y + 1) * $scale - 1, $colors[$char]);
                }
            }
        }
        return $this->createTextureFromGd($name, $image);
    }

    private function surfaceToSlice($surface, int $x): array
    {
        $texture = \SDL_CreateTextureFromSurface($this->getRenderer()->getRenderer(), $surface);
        if (!$texture) {
            throw new \RuntimeException('SDL_CreateTextureFromSurface failed: ' . \SDL_GetError());
        }
        $format = $access = $w = $h = 0;
        \SDL_QueryTexture($texture, $format, $access, $w, $h);
        return ['texture' => $texture, 'width' => (int)$w, 'height' => (int)$h, 'x' => $x];
    }

    /** Cuts a too wide image into <= 4064 px slices with GD. @return array<int, array{0:string,1:int}> */
    private function slice(string $file, string $name): array
    {
        $source = imagecreatefromstring((string)file_get_contents($file));
        if ($source === false) {
            throw new \RuntimeException("Cannot slice $file (GD)");
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $files = [];
        for ($x = 0, $i = 0; $x < $width; $x += self::MAX_TEXTURE_WIDTH, $i++) {
            $w = min(self::MAX_TEXTURE_WIDTH, $width - $x);
            $part = imagecreatetruecolor($w, $height);
            imagealphablending($part, false);
            imagesavealpha($part, true);
            imagecopy($part, $source, 0, 0, $x, 0, $w, $height);
            $sliceFile = $this->tempDir() . "/$name-$i.png";
            imagepng($part, $sliceFile);
            $files[] = [$sliceFile, $x];
        }
        return $files;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/jumphp-textures';
        @mkdir($dir, 0777, true);
        return $dir;
    }

    private function resolve(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->config->path($path);
    }

    public function quit(): void
    {
        foreach (array_keys($this->textures) as $name) {
            $this->releaseTexture($name);
        }
        $this->renderer?->destroy();
        $this->window?->destroy();
        \SDL_Quit();
    }
}
