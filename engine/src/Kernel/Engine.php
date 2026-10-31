<?php

namespace JumPHP\Kernel;

use Evenement\EventEmitter;
use JumPHP\Async\Tasks;
use JumPHP\Audio\SoundManager;
use JumPHP\Bridge\JsBridge;
use JumPHP\Debug\FpsCounter;
use JumPHP\Debug\HotReload;
use JumPHP\Device\Device;
use JumPHP\Device\Lifecycle;
use JumPHP\Device\Pwa;
use JumPHP\Audio\Audio3d;
use JumPHP\Gl\OpenGl;
use JumPHP\Gl\WebGl;
use JumPHP\Http\Http;
use JumPHP\Input\ActionMap;
use JumPHP\Input\InputManager;
use JumPHP\Loop\GameLoop;
use JumPHP\Net\NetSession;
use JumPHP\Renderer\BitmapFont;
use JumPHP\Renderer\Sdl;
use JumPHP\Renderer\TextDrawer;
use JumPHP\Save\Options;
use JumPHP\Save\Storage;
use JumPHP\Scene\SceneManager;
use JumPHP\Util\Lang;
use JumPHP\Util\Time;

/**
 * The running game: owns the services and runs one frame per tick():
 *   input -> network -> sound -> scenes update -> scenes render -> present.
 *
 * Everything a scene needs is a public property: $engine->actions, ->scenes, ->sound, ->sdl, ->options,
 * ->storage, ->config, ->input, ->lang, ->bridge, plus font(), net(), tasks(), http(), gl(), opengl(), audio3d(),
 * device(), lifecycle() and pwa().
 *
 * Events: 'boot', 'frame' (float $dt) after each update, 'quit'.
 */
class Engine extends EventEmitter
{
    private static ?Engine $instance = null;

    private float $lastTime = 0.0;
    private float $time = 0.0;
    private int $frame = 0;
    private bool $running = false;
    private bool $debug = false;
    private ?TextDrawer $font = null;
    private ?NetSession $net = null;
    private ?Tasks $tasks = null;
    private ?Http $http = null;
    private ?WebGl $gl = null;
    private ?OpenGl $opengl = null;
    private ?Audio3d $audio3d = null;
    private ?Device $device = null;
    private ?Lifecycle $lifecycle = null;
    private ?Pwa $pwa = null;
    private ?BitmapFont $debugFont = null;
    /** Game speed multiplier (slow motion, pause = 0). */
    public float $timeScale = 1.0;
    /** Fixed frame time instead of the clock (headless runs: 600 frames = 10 s of game). */
    public ?float $fixedDelta = null;

    public function __construct(
        public readonly GameConfig $config,
        public readonly GameLoop $gameLoop,
        public readonly Sdl $sdl,
        public readonly InputManager $input,
        public readonly ActionMap $actions,
        public readonly SceneManager $scenes,
        public readonly SoundManager $sound,
        public readonly Storage $storage,
        public readonly Options $options,
        public readonly Lang $lang,
        public readonly JsBridge $bridge,
        public readonly FpsCounter $fps,
    ) {
        self::$instance = $this;
    }

    /** The running engine, for code that has no access to it (entities created with new). */
    public static function get(): self
    {
        return self::$instance ?? throw new \LogicException('The engine is not booted');
    }

    /** Opens SDL, loads input / language / options, starts the first scene. */
    public function boot(): void
    {
        $c = $this->config;
        $fullscreen = (bool)$this->options->get('fullscreen', $c->get('fullscreen'));
        $this->sdl->initSDL(($fullscreen && Platform::isDesktop()) || Platform::isPhoneApp(), $c->title(), $c->width(), $c->height(),
            (bool)$c->get('vsync'), (int)$c->get('scale'));
        $this->sdl->getRenderer()->setIntegerScale((bool)$c->get('integer_scale', false));

        if ($this->sound->init()) {
            $this->sound->setSfxVolume((float)$this->options->get('sfx_volume', 0.8));
            $this->sound->setMusicVolume((float)$this->options->get('music_volume', 0.6));
        }

        $this->input->getGamepad()->open();
        $this->actions->load(self::DEFAULT_ACTIONS);
        $this->actions->load($c->file('input.yaml')['actions'] ?? []);
        $this->input->on('quit', fn () => $this->quit());
        $this->input->on('key', fn (int $key) => $this->globalKey($key));
        if (!Platform::isWasm() && Platform::setting('JUMPHP_TOUCH_CONTROLS')) {
            // the native Android / iOS app: the on-screen controls of the browser version (engine/web/touch.js), drawn here
            $controls = new \JumPHP\Input\TouchControls($this->input->getKeyboard(), $this->input->getMouse(), $this->input,
                (array)$c->get('touch', []), $this->font());
            $this->input->setControls($controls);
            $this->sdl->getRenderer()->addOverlay([$controls, 'draw']);
        }

        $this->lang->setLang((string)$this->options->get('lang', $c->lang()));
        $this->debug = (bool)Platform::setting('JUMPHP_DEBUG', false);

        $this->scenes->setEngine($this);
        $this->lifecycle()->attach($this);
        $this->device();
        $this->pwa();
        $this->running = true;
        $this->lastTime = Time::now();
        $this->emit('boot');

        // after a hot reload (the playground's editor): the scene that was playing, with its state
        $hot = HotReload::pending();
        [$scene, $params] = $hot ?? $this->startScene();
        if ($scene === null) {
            throw new \RuntimeException('No first scene: set game.start_scene in config/game.yaml');
        }
        $this->scenes->switchTo($scene, $params, false);
        if ($hot !== null) {
            HotReload::restore($this, $hot[2]);
        }
    }

    public const DEFAULT_ACTIONS = [
        'up' => ['Up', 'W', 'pad0:up', 'touch:up'],
        'down' => ['Down', 'S', 'pad0:down', 'touch:down'],
        'left' => ['Left', 'A', 'pad0:left', 'touch:left'],
        'right' => ['Right', 'D', 'pad0:right', 'touch:right'],
        'confirm' => ['Return', 'Space', 'Keypad Enter', 'pad0:a', 'touch:tap'],
        'back' => ['Escape', 'Backspace', 'pad0:b'],
        'pause' => ['Escape', 'P', 'pad0:start'],
    ];

    /** @return array{0: ?string, 1: array} JUMPHP_SCENE (+ JUMPHP_SCENE_PARAMS JSON) overrides game.yaml; smoke tests use game.smoke */
    private function startScene(): array
    {
        $scene = Platform::setting('JUMPHP_SCENE');
        if ($scene !== null) {
            $params = json_decode((string)Platform::setting('JUMPHP_SCENE_PARAMS', '{}'), true);
            return [(string)$scene, is_array($params) ? $params : []];
        }
        $smoke = $this->config->get('smoke', []);
        if (Platform::setting('JUMPHP_SMOKE') !== null && !empty($smoke['scene'])) {
            return [$smoke['scene'], $smoke['params'] ?? []];
        }
        return [$this->config->get('start_scene'), (array)$this->config->get('start_params', [])];
    }

    /** One frame. Desktop: called by the loop timer. Browser: by frame(). */
    public function tick(): void
    {
        if (!$this->running) {
            return;
        }
        $now = Time::now();
        // clamp: a breakpoint, a hidden tab or a slow load must not teleport everything
        $dt = $this->fixedDelta ?? min(max($now - $this->lastTime, 0.0), 0.1);
        $this->lastTime = $now;
        $this->fps->tick($dt);
        $dt *= $this->timeScale;
        $this->time += $dt;

        $renderer = $this->sdl->getRenderer();
        $this->bridge->poll();
        $this->input->poll($renderer);
        if ($this->net !== null && $this->net->isActive()) {
            $this->net->poll();
        }
        $this->sound->update();
        $this->audio3d?->sync();
        $this->tasks?->poll();
        $this->http?->poll();
        $this->scenes->update($dt);
        $this->emit('frame', [$dt]);
        $this->input->endFrame();

        if (!$this->running) {
            return;
        }
        $renderer->beginFrame($this->config->get('background'));
        $this->scenes->render($renderer);
        if ($this->debug) {
            $this->drawDebug();
        }
        $renderer->endFrame();
        $this->frame++;
    }

    /** Browser: one frame, then one turn of the event loop (the game's timers). */
    public function frame(): void
    {
        $this->tick();
        $this->gameLoop->start(1);
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /** Stops the game: desktop closes the window, browser tells the page (JsBridge 'quit'). */
    public function quit(): void
    {
        if (!$this->running) {
            return;
        }
        $this->emit('quit');
        if (Platform::isWasm()) {
            // a page cannot be closed by PHP: let it decide (go back to the portal, "thanks for playing")
            $this->bridge->send('quit');
            return;
        }
        $this->running = false;
        $this->net?->close();
        $this->tasks?->shutdown();
        $this->gameLoop->stop();
    }

    /**
     * Everything the game holds outside PHP closed: the network, the worker threads, the sound, the window. Before
     * a hot reload starts PHP again in the same page (HotReload): the new boot opens them again.
     */
    public function shutdown(): void
    {
        $this->running = false;
        $this->net?->close();
        $this->tasks?->shutdown();
        $this->closeAudio3d();
        $this->sound->close();
        $this->sdl->quit();
    }

    /** Seconds of game time since boot (scaled by timeScale). */
    public function time(): float
    {
        return $this->time;
    }

    public function frameCount(): int
    {
        return $this->frame;
    }

    /** The default font: the built-in pixel font, unless setFont() gave another one. */
    public function font(): TextDrawer
    {
        return $this->font ??= new BitmapFont($this->sdl);
    }

    public function setFont(TextDrawer $font): void
    {
        $this->font = $font;
    }

    /** The online session (created on first use, polled each frame while open). */
    public function net(): NetSession
    {
        return $this->net ??= new NetSession($this->config);
    }

    /** Background work: threads with the async runtime, between frames elsewhere (created on first use). */
    public function tasks(): Tasks
    {
        return $this->tasks ??= new Tasks($this->config);
    }

    /** Asynchronous HTTP requests (fetch in the browser, curl / sockets on desktop). */
    public function http(): Http
    {
        return $this->http ??= new Http($this->bridge);
    }

    /** WebGL in the browser: shaders, 3D layer, post-processing (does nothing on desktop: isAvailable()). */
    /** A 3D layer + post-processing shaders over the 2D (OpenGL / WebGL, both targets). */
    public function gl(): WebGl
    {
        return $this->gl ??= new WebGl($this->opengl(), fn (): float => $this->time);
    }

    /** Positional sound (PHP-OpenAL): sounds placed in the 3D world, heard from a listener. */
    public function audio3d(): Audio3d
    {
        return $this->audio3d ??= new Audio3d($this->sound);
    }

    /** @internal Application: the OpenAL device, at the end */
    public function closeAudio3d(): void
    {
        $this->audio3d?->close();
    }

    /** Raw OpenGL (PHP-OpenGL) in SDL's context: begin() / end(), programs, buffers, textures. */
    public function opengl(): OpenGl
    {
        return $this->opengl ??= new OpenGl($this->sdl);
    }

    /** Phone features: vibration, screen awake, orientation, clipboard, tilt, battery... */
    public function device(): Device
    {
        return $this->device ??= new Device($this->bridge, $this);
    }

    /** Page / window life: hide, show, blur, focus, online, offline, resize, orientation, close. */
    public function lifecycle(): Lifecycle
    {
        return $this->lifecycle ??= new Lifecycle($this);
    }

    /** Installable / offline game (game.yaml pwa): install(), updateReady(), isOfflineReady()... */
    public function pwa(): Pwa
    {
        return $this->pwa ??= new Pwa($this->bridge, $this->config);
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function toggleFullscreen(): void
    {
        $window = $this->sdl->getWindow();
        if ($window !== null) {
            $window->toggleFullscreen();
            $this->options->set('fullscreen', $window->isFullscreen());
        }
    }

    private function globalKey(int $key): void
    {
        if ($key === \JumPHP\Input\ActionMap::keyCode((string)$this->config->get('debug_key'))) {
            $this->debug = !$this->debug;
        } elseif ($key === \JumPHP\Input\ActionMap::keyCode((string)$this->config->get('fullscreen_key'))) {
            $this->toggleFullscreen();
        }
    }

    private function drawDebug(): void
    {
        $r = $this->sdl->getRenderer();
        $scene = $this->scenes->current();
        $name = $scene ? (new \ReflectionClass($scene))->getShortName() : '-';
        $text = sprintf("FPS %d  WORST %.1fMS\n%s  MEM %.1fMB", $this->fps->fps, $this->fps->worstMs, $name,
            memory_get_usage() / 1048576);
        $font = $this->debugFont ??= new BitmapFont($this->sdl);
        $r->fillRect(0, 0, $font->width($text) + 4, $font->lineHeight() * 2 + 2, [0, 0, 0, 170]);
        $font->draw($r, $text, 2, 2, 1, [120, 255, 120]);
    }
}
