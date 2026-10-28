<?php

namespace JumPHP\Device;

use Evenement\EventEmitter;
use JumPHP\Async\Task;
use JumPHP\Bridge\JsBridge;
use JumPHP\Kernel\GameConfig;
use JumPHP\Kernel\Platform;

/**
 * The game as an installable, offline app (PWA) — $this->engine->pwa().
 *
 * Turned on by game.yaml `pwa: true` (or {name, short_name, display, orientation, theme_color,
 * background_color}): `bin/jumphp build` then adds manifest.webmanifest, icons (assets/icon.png scaled up, else
 * the title's initial) and sw.js, a service worker that keeps the whole build in the browser's cache: once
 * loaded, the game starts without network. Needs https (or localhost).
 *
 *   if ($pwa->canInstall()) { $items[] = ['INSTALL', null, fn () => $pwa->install()]; }   // a menu entry
 *   if ($pwa->updateReady()) { $items[] = ['UPDATE', null, fn () => $pwa->applyUpdate()]; }
 *   $pwa->on('offline', fn () => ...);       // everything is cached: playable offline
 *
 * install() shows the browser's install question (Chromium: Android, desktop Chrome / Edge). iOS has no such
 * question: the player uses Share > "Add to Home Screen" (canInstall() stays false there).
 *
 * Events: 'installable' (the browser allows install() now), 'installed', 'offline' (cache complete),
 * 'update' (a newer build is downloaded: applyUpdate() reloads into it).
 * Desktop: nothing is enabled, install() settles with false.
 */
class Pwa extends EventEmitter
{
    private bool $installable = false;
    private bool $offline = false;
    private bool $update = false;
    private bool $installed;
    /** @var Task[] */
    private array $installs = [];

    public function __construct(private JsBridge $bridge, private GameConfig $config)
    {
        $this->installed = (bool)Platform::setting('JUMPHP_STANDALONE', false);
        $bridge->on('pwa', fn (array $e) => $this->pageEvent($e));
    }

    /** game.yaml `pwa` is on, in the browser. */
    public function isEnabled(): bool
    {
        return Platform::isWasm() && ($this->config->get('pwa') === true || is_array($this->config->get('pwa')));
    }

    /** Runs as an installed app (home screen / app window), not in a tab. */
    public function isInstalled(): bool
    {
        return $this->installed;
    }

    /** The browser can show its install question (install()). */
    public function canInstall(): bool
    {
        return $this->installable && !$this->installed;
    }

    /** The service worker has the whole build: the game starts offline. */
    public function isOfflineReady(): bool
    {
        return $this->offline;
    }

    /** A newer build is ready: applyUpdate() switches to it. */
    public function updateReady(): bool
    {
        return $this->update;
    }

    /**
     * The browser's install question (call it from a menu action: browsers want a recent key press / tap).
     * @return Task settles with true (installed) or false (refused / not possible)
     */
    public function install(): Task
    {
        $task = new Task();
        if (!$this->canInstall() || !$this->bridge->send('pwa', ['do' => 'install'])) {
            $task->settle(false);
            return $task;
        }
        $this->installs[] = $task;
        return $task;
    }

    /** Reloads the page into the newer build (saves are written first). */
    public function applyUpdate(): bool
    {
        return $this->update && $this->bridge->send('pwa', ['do' => 'update']);
    }

    /** @internal JsBridge 'pwa': {installable}, {installed}, {offline}, {update}, {choice: bool} */
    public function pageEvent(array $e): void
    {
        if (isset($e['installable']) && $this->installable !== (bool)$e['installable']) {
            $this->installable = (bool)$e['installable'];
            if ($this->installable) {
                $this->emit('installable');
            }
        }
        if (!empty($e['installed']) && !$this->installed) {
            $this->installed = true;
            $this->installable = false;
            $this->emit('installed');
        }
        if (!empty($e['offline']) && !$this->offline) {
            $this->offline = true;
            $this->emit('offline');
        }
        if (!empty($e['update']) && !$this->update) {
            $this->update = true;
            $this->emit('update');
        }
        if (array_key_exists('choice', $e)) {
            foreach (array_splice($this->installs, 0) as $task) {
                $task->settle((bool)$e['choice']);
            }
        }
    }
}
