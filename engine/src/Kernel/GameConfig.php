<?php

namespace JumPHP\Kernel;

use Symfony\Component\Yaml\Yaml;

/**
 * config/game.yaml of the game (the `game:` key), with defaults. Read-only once booted.
 */
final class GameConfig
{
    public const DEFAULTS = [
        'id' => 'game',
        'title' => 'JumPHP game',
        'version' => '0.1.0',
        'description' => '',
        'width' => 320,          // virtual resolution: everything is drawn at this size, then scaled
        'height' => 180,
        'scale' => 3,            // initial window size = width * scale (desktop)
        'fullscreen' => false,
        'vsync' => true,
        'max_fps' => 120,        // desktop frame cap (vsync usually paces first)
        'background' => [0, 0, 0],
        'start_scene' => null,   // class name of the first scene
        'start_params' => [],
        'lang' => 'en',
        'net_url' => 'ws://localhost:8090/ws',
        'debug_key' => 'F3',
        'fullscreen_key' => 'F11',
        'smoke' => [],           // {scene: ..., params: {...}}: what `jumphp test` runs headless
        'online' => false,       // shown by the future games portal (game.json)
        'runtime' => 'default',  // 'async': PHP 8.6 ZTS + TrueAsync on the desktop (real threads, JumPHP\Async)
        'pwa' => false,          // true / {name, short_name, display, orientation, theme_color, background_color}: installable, offline
        'pause_scene' => null,   // pushed when the game is hidden / loses the focus (JumPHP\Device\Lifecycle)
        'pause_on_blur' => true,
        'mute_when_hidden' => true,
    ];

    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values = [], private string $root = '.')
    {
        $this->values = array_replace(self::DEFAULTS, $values);
    }

    public static function load(string $root): self
    {
        $file = $root . '/config/game.yaml';
        $data = is_file($file) ? (Yaml::parseFile($file) ?? []) : [];
        return new self($data['game'] ?? [], $root);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Absolute path of a file of the game: path('assets/sprites/ball.png'). */
    public function path(string $relative = ''): string
    {
        return rtrim($this->root, '/') . ($relative === '' ? '' : '/' . ltrim($relative, '/'));
    }

    public function width(): int { return (int)$this->values['width']; }
    public function height(): int { return (int)$this->values['height']; }
    public function title(): string { return (string)$this->values['title']; }
    public function id(): string { return (string)$this->values['id']; }

    /** The relay server: JUMPHP_NET_URL (page ?net= / env) wins over game.yaml. */
    public function netUrl(): string
    {
        return (string)Platform::setting('JUMPHP_NET_URL', $this->values['net_url']);
    }

    public function lang(): string
    {
        return (string)Platform::setting('JUMPHP_LANG', $this->values['lang']);
    }

    /** Reads another YAML file of config/ (input.yaml, lang.yaml...). */
    public function file(string $name): array
    {
        $file = $this->path('config/' . $name);
        return is_file($file) ? (Yaml::parseFile($file) ?? []) : [];
    }
}
