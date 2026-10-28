# AGENTS.md — how to build games with JumPHP

You are working in **JumPHP**, a framework to write **retro 2D games in PHP**. The same PHP code runs:

- on the **desktop** (native PHP 8.3 + SDL2 extensions, in Docker, in a real window with sound),
- in the **browser** (PHP 8.3 + SDL2 compiled to WebAssembly; the game is a static web page).

Everything (PHP, composer, emscripten, web server, relay server, tests) runs **in Docker** through one
command: `bin/jumphp`. Never install PHP, extensions, emscripten or node on the host; never run `php` or
`composer` directly — always `bin/jumphp ...`.

Read this whole file once before writing code. It is the contract: if you follow it, your game works on
both targets.

---

## 1. Map of the repository

```
bin/jumphp                 the CLI (bash). `bin/jumphp help`
AGENTS.md               this file
README.md, docs/        human documentation (French)
engine/                 the framework: composer package jumphp/engine, namespace JumPHP\  (engine/src)
  src/Kernel/           Application (boot), Engine (the frame), GameConfig, Platform
  src/Loop/             GameLoop (ReactPHP), FixedStep
  src/Renderer/         Sdl (textures), Renderer (drawing), Window, BitmapFont, TtfFont, TextDrawer, CrtFilter
  src/Input/            InputManager, ActionMap, Keyboard, Mouse, Touch, Gamepad
  src/Scene/            Scene, AbstractScene, SceneManager, Camera
  src/Entity/           Entity, GameObject, EntityManager, traits Sprite and Gravity
  src/Physics/          CollisionSystem (tiles), Aabb (boxes), TileCollision trait, CollisionResult
  src/Tile/             TileMap, TileSet, CollisionMask, TileMapRenderer, TiledJsonLoader, ArrayLoader, BinaryLoader
  src/Audio/            SoundManager, Synth (generated sounds)
  src/Ui/               Menu, MenuScene, TextInput, Hud
  src/Net/              NetSession, LobbyScene, MessageCodec, SnapshotBuffer
  src/Save/             Storage, Options
  src/Async/            Tasks, Pool, Task, TaskFailed, WebWorkers, Worker (threads / Web Workers / between frames)
  src/Bridge/           JsBridge (game -> web page)
  src/Util/             Color, Math, Time, Lang, Vector
  bootstrap/wasm.php    browser entry point
  web/                  the web page template (index.html, jumphp.js, jumphp-worker.js, touch.js)
  stubs/sdl.stub.php    signatures of every SDL function / constant / class (read it to know the raw API)
  stubs/async.stub.php  the TrueAsync API used by JumPHP\Async
  tests/                engine unit tests
skeleton/               template of a game, copied by `bin/jumphp new`
games/<id>/             the games (games/pong = the reference game: read it)
docker/                 engine-wasm (WebAssembly build), desktop (runtime), desktop-async (PHP 8.6 ZTS + TrueAsync),
                        relay (network server), web (FrankenPHP)
tools/                  build scripts used by bin/jumphp
build/engine/           the compiled WebAssembly engine (bin/jumphp engine:build), shared by all games
```

A game folder:

```
games/<id>/
  app.php               desktop entry point (do not change)
  composer.json         namespace App\ -> src/, requires jumphp/engine (path ../../engine)
  config/game.yaml      id, title, resolution, first scene, smoke test...
  config/services.yaml  dependency injection (every class of src/ is autowired, except src/Entity)
  config/input.yaml     actions -> keys / pad / touch
  config/lang.yaml      translations
  src/Scene/*.php       the screens (TitleScene, PlayScene, ...)
  src/Entity/*.php      the things that move (created with `new`)
  assets/               sprites/, tiles/, maps/, sfx/, music/, fonts/, icon.png, thumbnail.png
  var/                  player files (saves, options) — never commit, never read at build time
  dist/web/             browser build output (bin/jumphp build)
  AGENTS.md             notes about this game
```

---

## 2. Golden rules (read twice)

1. **Docker only**: `bin/jumphp ...` for everything (see §3).
2. **Never block the frame.** No `sleep()`, `usleep()`, `while (true)`, `readline()`, `fgets(STDIN)`,
   blocking sockets, long loops. The browser calls the engine once per frame; a blocking call freezes the tab.
   Time-based things: count `$dt` in `update()`, or `GameLoop::addTimer($seconds, fn () => ...)`.
3. **32-bit integers in the browser** (`PHP_INT_SIZE === 4`, max 2147483647):
   - no `(int)(microtime(true) * 1000)`, no `hrtime(true)` as int, no `time() * 1000`: use `JumPHP\Util\Time::now()` (float seconds) or `$this->engine->time()`.
   - no colour integers like `0xFF000000`: use `[r, g, b, a]` arrays, `'#rrggbb'` strings or `Color`.
   - no `pack('q'/'J'/'P')`: use `C c v s V g` (see MessageCodec).
   - big counters (score > 2 billion) are floats.
4. **Network only through `JumPHP\Net`** (`$this->engine->net()`). No `socket_*`, `fsockopen`,
   `stream_socket_client`, curl or `file_get_contents('http...')`: they do not exist in the browser.
5. **Files**: read game files with paths relative to the game root through `$this->engine->config->path('assets/...')`
   (or relative paths given to Sdl / SoundManager / loaders, which resolve them). Write only into `var/`
   through `$this->engine->storage` / `$this->engine->options` (persisted in IndexedDB in the browser).
   `sys_get_temp_dir()` is fine for temporary files.
6. **SDL limits** (the binding): no `SDL_SetTextureBlendMode`, `SDL_SetRenderDrawBlendMode`,
   `SDL_SetTextureColorMod`, `SDL_SetTextureAlphaMod`, `SDL_GameController*`. Translucency: give a colour with
   alpha to `Renderer::fillRect()` (handled), or use PNGs with alpha. Tinting: pre-coloured images (or
   `createTextureFromPixels` with another palette). Textures ≤ 4064 px wide (Sdl slices wider images itself).
7. **Draw only in `render()`, change state only in `update()`.** Coordinates are virtual pixels
   (`game.yaml` width × height); the engine scales to the window.
8. **Scenes are reused** (one instance per class, from the container): reset ALL state in `enter()`.
9. **No `declare(strict_types=1)`** required, PHP 8.2 syntax max (the desktop runs 8.3, the browser 8.3).
   Code, identifiers and comments in English. Follow the style of `games/pong`.
10. **Forbidden words**: `bin/jumphp lint` fails if the repository contains certain brand names
    (`tools/check-forbidden-words.sh`). Do not name games, classes or files after existing commercial games.
11. **Always verify** before saying you are done (§10): `bin/jumphp test <game>` must pass, and look at a
    screenshot (`bin/jumphp shot <game>`).

---

## 3. Commands

| Command | What it does |
|---|---|
| `bin/jumphp setup [async]` | builds the Docker images (desktop runtime, relay; `async`: also the PHP 8.6 ZTS + TrueAsync runtime, ~15 min). Done automatically when missing. |
| `bin/jumphp engine:build` | builds the WebAssembly engine into `build/engine/` (~25 min the first time, cached). Needed once for browser builds. |
| `bin/jumphp new <id> ["Title"]` | creates `games/<id>` from `skeleton/` (id: `a-z0-9-`) |
| `bin/jumphp install <id>` | composer install of the game |
| `bin/jumphp composer <id> require vendor/pkg` | composer (pure-PHP packages only: no extension exists in the browser) |
| `bin/jumphp run <id>` | plays on the desktop (window + sound). Needs a display; not usable by an agent without a screen. |
| `bin/jumphp test <id>` | `php -l`, phpstan (level 5, if `phpstan.neon` and `bin/jumphp test engine` ran once), phpunit (`tests/` if any), **headless smoke test** (600 frames, any PHP warning = failure) |
| `bin/jumphp shot <id> [frames] [out.png]` | **headless screenshot**: runs N frames (default 120) and saves a PNG in `games/<id>/var/screenshots/` — open it to SEE the game |
| `bin/jumphp build <id>` | browser build → `games/<id>/dist/web/` (static files) |
| `bin/jumphp serve <id> [port]` | serves the browser build on http://localhost:8080 |
| `bin/jumphp webshot <id> [seconds] [keys]` | headless Chrome: loads the browser build, presses keys, screenshot `dist/webshot.png`, fails on JS / PHP errors |
| `bin/jumphp relay up\|down\|logs` | multiplayer relay server on `ws://localhost:8090/ws` |
| `bin/jumphp export:desktop <id>` | a Docker image that runs the game |
| `bin/jumphp deploy <id>` | rsync `dist/web` to `$JUMPHP_DEPLOY_TARGET/<id>/` (.env) |
| `bin/jumphp pages [id...]` | builds the games (default: all) + a home page from their `game.json`, force-pushes them as the `gh-pages` branch of the git remote (GitHub Pages: `https://<owner>.github.io/<repo>/`). Publishing: only when asked |
| `bin/jumphp test engine` | engine unit tests + phpstan |
| `bin/jumphp lint` | php -l everywhere + forbidden words |
| `bin/jumphp php <dir> <args>` | php in the runtime image (from a repo-relative dir) |

Headless runs (`test`, `shot`) use a **fresh temporary `var/`** (options and saves start from the defaults,
the player's files are never touched; `JUMPHP_VAR=<dir>` forces a folder). They are driven by environment variables:

| Variable | Effect |
|---|---|
| `JUMPHP_SCENE='App\Scene\PlayScene'` | first scene (instead of `start_scene`) |
| `JUMPHP_SCENE_PARAMS='{"mode":"cpu"}'` | its `enter()` params (JSON) |
| `JUMPHP_INPUT='[[10,"confirm"],[30,"left","hold"],[90,"left","release"]]'` | scripted actions: `[frame, action]` = one press, `"hold"` / `"release"` |
| `JUMPHP_MAX_FRAMES=600` | how many frames (60 frames = 1 s of game time, fixed step) |
| `JUMPHP_SHOT_EVERY=30` | `shot`: also save one PNG every N frames (`shot-00030.png`...; older ones are deleted first) |
| `JUMPHP_REALTIME=1` | headless frames in real time (network tests between two processes) |
| `JUMPHP_DEBUG=1` | FPS / scene overlay (F3 toggles it too) |

Three key-name formats, do not mix them:

| Where | Format | Examples |
|---|---|---|
| `config/input.yaml` | SDL key names, `padN:`, `touch:`, `mouse:` | `Left`, `Space`, `Return`, `Left Shift`, `pad0:button0` |
| `JUMPHP_INPUT` (headless) | **action names** from input.yaml | `confirm`, `left`, `fire` |
| `bin/jumphp webshot` keys | browser `KeyboardEvent.key` names | `Enter`, `ArrowLeft`, ` ` (space), `Escape`, `a` |

Tip: for tests and shots, add test-only `enter()` params to your scenes (e.g. `{"level":3,"lives":1}`) and
pass them with `JUMPHP_SCENE_PARAMS`, rather than scripting long input sequences.

Examples:

```bash
bin/jumphp shot mygame 60                                     # the title screen after 1 s
JUMPHP_INPUT='[[5,"confirm"]]' bin/jumphp shot mygame 180 var/screenshots/play.png   # press confirm, 3 s later
JUMPHP_SCENE='App\Scene\PlayScene' bin/jumphp shot mygame 300
```

---

## 4. Create a game, step by step

```bash
bin/jumphp new breakout "BREAKOUT"
bin/jumphp install breakout
bin/jumphp test breakout          # the skeleton passes as is
bin/jumphp shot breakout 60       # look at games/breakout/var/screenshots/shot.png
```

The skeleton is a tiny platformer (title menu, options, a level with slopes, a player, coins, a pause menu).
Replace its scenes and entities with your game:

1. **`config/game.yaml`** (all keys under `game:`; every one is optional):

   | Key | Default | |
   |---|---|---|
   | `id`, `title`, `version`, `description` | | identity (page title, `game.json` for the portal) |
   | `width`, `height` | 320, 180 | virtual resolution (classic: 320×180, 256×224, 256×192, 320×240) |
   | `scale` | 3 | desktop window = width × scale |
   | `integer_scale` | false | scale by whole factors only (sharper, bigger black borders) |
   | `fullscreen`, `vsync`, `max_fps` | false, true, 120 | desktop window |
   | `background` | [0,0,0] | letterbox colour |
   | `start_scene`, `start_params` | | first scene class and its `enter()` params |
   | `lang` | en | default language (`config/lang.yaml`) |
   | `net_url` | ws://localhost:8090/ws | relay server |
   | `smoke` | | `{scene: ..., params: {...}}` run 600 frames by `bin/jumphp test`: make it a self-playing demo |
   | `online` | false | the game has online play (shown by the portal) |
   | `touch` | | phone pad keys: `{a: Space, b: Escape, start: Return}`; optional `pad: stick` (floating joystick, 8 directions), `select: P` (4th button), `labels: {a: JUMP, ..., rotate: TEXT}`; phones go fullscreen + landscape at the first tap (`fullscreen: false` to disable) |
   | `controls` | | one line of help text shown by the web page / portal |
   | `runtime` | default | `async`: desktop runs on PHP 8.6 ZTS + TrueAsync (real threads, §9 Threads) |
   | `debug_key`, `fullscreen_key` | F3, F11 | |
2. **`config/input.yaml`**: your actions (`jump`, `fire`, `p1_up`...). The engine already defines
   `up down left right confirm back pause`.
3. **Scenes** in `src/Scene/` (§5): a `TitleScene extends MenuScene`, a `PlayScene extends AbstractScene`,
   overlays (`PauseScene`, `GameOverScene`) with `isOverlay(): true`.
4. **Entities** in `src/Entity/` (§6), created with `new` by the scenes.
5. **Assets** (optional): PNG sprite sheets in `assets/sprites/`, tilesets in `assets/tiles/`, Tiled maps
   (`.tmj`) in `assets/maps/`, sounds (`.wav`, `.ogg`) in `assets/sfx/` and `assets/music/`, TTF fonts in
   `assets/fonts/`, `assets/icon.png` (page icon), `assets/thumbnail.png` (games portal). **No asset is
   required**: draw with rectangles, `createTextureFromPixels()` (pixel art in code), GD, the built-in
   `BitmapFont`, and `Synth` sounds.
6. Replace the skeleton leftovers: `description` in game.yaml, `README.md`, `AGENTS.md`, unused `lang.yaml`
   entries, `src/Entity/Coin.php`...
7. `bin/jumphp test <id>` + `bin/jumphp shot <id>`; iterate.
8. Browser: `bin/jumphp engine:build` (once per machine), `bin/jumphp build <id>`, `bin/jumphp webshot <id>`.

---

## 5. Scenes

```php
<?php

namespace App\Scene;

use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

final class PlayScene extends AbstractScene
{
    private float $x = 0;

    // constructor: services only (autowired), e.g. TileMapRenderer, CollisionSystem, EntityManager
    public function enter(array $params = []): void
    {
        $this->x = 0;                          // reset everything: the instance is reused
    }

    public function update(float $dt): void    // $dt in seconds (variable; ~0.016)
    {
        if ($this->actions()->isPressed('pause')) {
            $this->scenes()->push(PauseScene::class);
            return;
        }
        $this->x += $this->actions()->axis('left', 'right') * 100 * $dt;
    }

    public function render(Renderer $r): void
    {
        $r->clear([0, 0, 40]);
        $r->fillRect($this->x, 80, 16, 16, '#ffcc00');
        $this->font()->draw($r, 'HELLO', $this->width() / 2, 20, 2, [255, 255, 255], 'center');
    }
}
```

`AbstractScene` helpers: `$this->engine`, `actions()`, `scenes()`, `sound()`, `sdl()`, `font()`,
`width()`, `height()`. Lifecycle: `enter($params)`, `exit()`, `pause()` (something pushed over it),
`resume($result)` (it was popped), `update($dt)`, `render($r)`, `isOverlay()`.

SceneManager (`$this->scenes()`):

| Call | Effect |
|---|---|
| `switchTo(Class::class, $params = [], $fade = true)` | replaces the whole stack (black fade) |
| `push(Class::class, $params = [], $fade = false)` | over the current one (paused) |
| `pop($result = null)` | back to the one below (`resume($result)`) |
| `current()` | the scene on top |

Only the top scene is updated. Overlays (`isOverlay() === true`) are drawn over the scene below.

**Escape is bound to both `pause` and `back`.** In a pause menu (a `MenuScene` overlay), `back()` already
fires on Escape; to also resume with the other pause keys (P, Start) without popping twice:

```php
public function update(float $dt): void
{
    if ($this->actions()->isPressed('pause') && !$this->actions()->isPressed('back')) {
        $this->scenes()->pop();
        return;
    }
    parent::update($dt);
}
```

**Menus**: extend `JumPHP\Ui\MenuScene`, set `$title`, return rows from `items()`:

```php
final class TitleScene extends MenuScene
{
    protected string $title = 'MY GAME';

    protected function items(): array
    {
        return [
            // [label, value, onPress, onSide]
            ['PLAY', null, fn () => $this->scenes()->switchTo(PlayScene::class, ['level' => 1])],
            ['VOLUME', fn () => round($this->sound()->getSfxVolume() * 100) . '%', null,
                fn (int $dir) => $this->sound()->setSfxVolume($this->sound()->getSfxVolume() + $dir / 10)],
            ['QUIT', null, fn () => $this->engine->quit()],
        ];
    }

    protected function back(): void { }        // Escape; sub-menus: $this->scenes()->pop();
}
```

Labels and the `$title` are translated with `config/lang.yaml`. Call `$this->refresh()` after changing a value shown in a
label. Override `renderBackground(Renderer $r)` for a custom background; `$titleScale`, `$menuTop`,
`$background`, `$titleColor` customise it. Menu sounds: define sfx named `menu_move` and `menu_confirm`.
Hide `QUIT` in the browser: `if (Platform::isDesktop())`.

---

## 6. Entities, sprites, animation

`JumPHP\Entity\Entity` has **protected** fields `x`, `y` (top-left, float), `vx`, `vy`, `ax`, `ay`, `friction`,
`width`, `height`, `facing` ('left'/'right'), `state` (string): use them as `$this->x` inside the entity
class, and through accessors from outside. **Public** fields: `active`, `visible`, `dead`, `alwaysActive`.
Accessors: `setX()/setY()/setXY()`, `setSpeedX()/setSpeedY()`, `getSpeedX()/getSpeedY()`, `getSpeed()` (length),
`setAcceleration()`, `setFriction()`, `setFacing()/getFacing()`, `setState()/getState()`,
`setSize()`, `getWidth()/getHeight()`.
Methods: `getX()/getY()` (rounded ints), `getFloatX()/getFloatY()`, `setVelocity()`,
`getVelocity()` (`[vx, vy]`), `getBoundingBox()` (`['x','y','width','height']`), `centerX()`, `centerY()`,
`intersects($rect)`, `kill()`, `update($dt)` (integrates velocity, picks the animation `<state><Facing>`),
`render(Renderer $r, $camX, $camY)` (default: magenta box). It is an event emitter (`on()`, `emit()`).

`GameObject extends Entity`: `type`, `name`, `properties` (from the map editor), `property($name)`,
constructor `($x, $y, $width, $height, $type, $name, $properties)` — what `EntityManager::spawn()` calls.

Traits (compose them; the class's own `update()` calls the aliased ones):

```php
final class Hero extends Entity
{
    use Sprite, Gravity, TileCollision {
        Sprite::update as updateSprite;
        Gravity::update as updateGravity;
    }

    protected array $animations = [
        'idleRight' => ['coords' => [['x' => 0, 'y' => 0, 'w' => 16, 'h' => 16]]],
        'walkRight' => ['coords' => [['x' => 16, 'y' => 0, 'w' => 16, 'h' => 16], ['x' => 32, 'y' => 0, 'w' => 16, 'h' => 16]], 'duration' => 0.1],
        'idleLeft'  => 'reverseIdleRight',     // same frames, flipped
        'walkLeft'  => 'reverseWalkRight',
        'dieRight'  => ['coords' => [...], 'loop' => false],   // isAnimationDone() at the end
    ];

    public function __construct(Sdl $sdl, float $x, float $y)
    {
        $this->x = $x; $this->y = $y; $this->width = 12; $this->height = 16;
        $this->gravity = 900; $this->maxFallSpeed = 500;
        $sdl->hasTexture('hero') || $sdl->loadTexture('hero', 'assets/sprites/hero.png');
        $this->initSprite($sdl, 'hero', 'idleRight');
    }

    public function update(float $dt): void
    {
        $this->updateGravity($dt);             // vy += gravity
        $this->state = abs($this->vx) > 5 ? 'walk' : 'idle';
        $this->pickAnimation();                // "<state><Facing>"
        $this->updateSprite($dt);              // next frame
        // the scene then calls $collisions->move($hero, $map, $dt) to move against the tiles
    }

    public function render(Renderer $r, float $camX = 0, float $camY = 0): void
    {
        $this->drawAt($r, $this->x - $camX, $this->y - $camY);
    }
}
```

- `Sprite`: `initSprite($sdl, $textureName, $firstAnimation)`, `setAnimation()`, `hasAnimation()`,
  `getAnimation()`, `isAnimationDone()`, `drawAt($r, $x, $y, $forceFlip = false, $scale = 1)`.
  Animation keys: `coords` (frames in the sheet), `duration` (s per frame, default 0.1), `loop` (default true),
  `offset` ([dx, dy] drawing offset), `flags.flip`.
- `Gravity`: `$gravity` (px/s², default 900), `$maxFallSpeed`, `setGravity()`, `enableGravity(false)`.
- `TileCollision`: `$onGround`, `$onCeiling`, `$onWallLeft`, `$onWallRight`, `$groundGid` (filled by CollisionSystem).

`EntityManager` (inject it in the scene constructor; one per scene):
`register('coin', Coin::class)` or `register('enemy', fn (array $o) => new Enemy(...))`,
`spawnFromMap($map)`, `add($e)`, `remove($e)`, `clear()`, `update($dt, $camera)` (entities far from the
camera wait), `render($r, $camera)`, `all()`, `ofClass(Enemy::class)`, `overlapping($rect, $except)`,
`count()`. Dead entities (`kill()`) are removed automatically.

---

## 7. Drawing, textures, text

`Renderer $r` (in `render()`), view coordinates:

| Call | |
|---|---|
| `clear($color)` | fills the view |
| `fillRect($x, $y, $w, $h, $color)` | colour with alpha < 255 = translucent (works without blend modes) |
| `drawRect($x, $y, $w, $h, $color, $thickness = 1)` | outline |
| `drawLine($x1, $y1, $x2, $y2, $color)`, `drawPoint($x, $y, $color)` | |
| `fillCircle($cx, $cy, $radius, $color)` | |
| `copy($texture, $src = [x,y,w,h] or null, $x, $y, $w = null, $h = null, $flipH = false, $flipV = false, $angle = 0)` | draws a texture |
| `getWidth()`, `getHeight()` | view size |
| `setTarget($texture)` / `setTarget($r->getViewTexture())` | draw into a texture (caches) |
| `getRenderer()` | the raw SDL renderer for `\SDL_*` calls |

Colours everywhere: `[r, g, b]`, `[r, g, b, a]`, `'#rgb'`, `'#rrggbb'`, `'#rrggbbaa'` or `new Color(...)`.

Textures (`$this->sdl()`, class `Sdl`): textures have names.

| Call | |
|---|---|
| `loadTexture('hero', 'assets/sprites/hero.png', $transparentRgb = null)` | now; PNG alpha kept; `[255,0,255]` = colour key |
| `registerTexture('lvl2', 'assets/tiles/lvl2.png', 'level2')` + `loadGroup('level2')` / `releaseGroupsExcept('level2')` | lazy, per level |
| `getTexture('hero')` | `['texture' => resource, 'width' => int, 'height' => int, 'x' => 0]` |
| `hasTexture('hero')` | |
| `createTextureFromPixels('heart', ['.#.#.', '#####', '.###.', '..#..'], ['#' => '#e33'], $scale = 1)` | pixel art in code, `.`/space = transparent |
| `createTextureFromGd('name', $gdImage)` | draw with GD (imagefilledpolygon...) then use as texture |
| `createTexture('name', $w, $h)` | empty render target |
| `setTextureVariant('-night')` | `getTexture('tiles')` returns `tiles-night` when it exists |

Text: `$this->font()` is the built-in **BitmapFont** (5×7 pixels, upper case, no file):
`draw($r, $text, $x, $y, $scale = 1, $color = white, $align = 'left'|'center'|'right', $shadowColor = null)`,
`width($text, $scale)`, `height($scale)`, `lineHeight($scale)`. `\n` makes new lines.
A TTF font: `$font = new TtfFont($this->sdl(), 'assets/fonts/x.ttf', 8)` (same interface `TextDrawer`);
`$this->engine->setFont($font)` makes it the default one.

Helpers: `Hud::panel($r, $x, $y, $w, $h)`, `Hud::bar($r, $x, $y, $w, $h, $ratio)`,
`Hud::message($r, $font, "TEXT", $cx, $cy)`, `Hud::blink($time)`, `CrtFilter::apply($r)` (scanlines).

**Camera** (`JumPHP\Scene\Camera`): `new Camera($this->width(), $this->height())`, `setBounds(0, 0, $mapW, $mapH)`,
`follow($entity, $deadZoneW = 0.2, $deadZoneH = 0.3, $smoothing = 0)`, `update($dt)`, `getX()`, `getY()`
(whole pixels, subtract them when drawing), `centerOn()`, `lock()`, `shake($strength, $seconds)`,
`setOffset()`, `isVisible($rect, $margin)`, `worldToScreen()`.

---

## 8. Tiles and collisions

**Maps** (`JumPHP\Tile\TileMap`): layers of global tile ids (0 = empty), tilesets, objects, properties.

- From **Tiled** (recommended for real levels, https://www.mapeditor.org): save as JSON `.tmj`, tilesets
  embedded or `.tsj`, one image per tileset, "Infinite" off.
  `$map = TiledJsonLoader::load($this->engine->config->path('assets/maps/level1.tmj'));`
  - collision layer: the layer named `collision` (or with the bool property `collision`), else the first one;
  - per-tile collision: tile property `collision` = `full` | `none` | `oneway` | `slope:FROM,TO` (heights in
    px at the left / right edge, e.g. `slope:0,16`) | `heights:h0,h1,...` | `oneway-slope:...`, or shapes drawn
    in Tiled's collision editor (rectangles / polygons). Without anything a tile of the collision layer is solid;
  - layer property `foreground` (bool) = drawn over the entities; layer `visible` off = not drawn;
  - objects (object layers): `$map->objects` = `['type','name','x','y','width','height','properties','layer','gid']`,
    `$map->object('start')`, `$map->objectsOfType('coin')`; tile properties: `$map->tileProperty($gid, 'hazard')`;
  - animated tiles (Tiled animation editor), flipped tiles, image layers with parallax: supported.
- From **rows in code** (prototypes, tests, generated levels):
  ```php
  $map = ArrayLoader::fromRows([
      '..........',
      '..P...C...',
      '####==####',
  ], legend: ['#' => 1, '=' => 2], objects: ['P' => 'start', 'C' => 'coin'], tileWidth: 16, tileset: 'assets/tiles/tiles.png');
  $map->tilesets[0]->masks[1] = CollisionMask::oneWay();    // gid 2 = local id 1
  ```
  Without `tileset`, solid tiles are drawn as flat blocks.
- From **raw bytes** (`BinaryLoader::load($file, $w, $h, $tileW, $tileH, $image, $columns, $solidityFile, $shapes)`):
  one byte per tile, for levels ripped from old games.

Masks (`CollisionMask`): `full()`, `none()`, `oneWay()`, `slope($w, $h, $fromHeight, $toHeight)`,
`heights([...], $h)`, `fromRows(['#...', ...])`, `parse('slope:0,16', $w, $h)`.

Drawing: inject `TileMapRenderer $tiles` in the scene; in `render()`:
```php
$this->tiles->renderBackground($r, $map, $camera->getX(), $camera->getY(), $this->time);
$this->entities->render($r, $camera);
$this->tiles->renderForeground($r, $map, $camera->getX(), $camera->getY(), $this->time);
```

Collisions with tiles: inject `CollisionSystem $collisions` (one per scene). Each frame, set the velocity
then `$result = $this->collisions->move($entity, $map, $dt);` — it moves pixel by pixel, stops at walls,
lands on floors / one-way platforms, climbs slopes and steps up to `$maxStepUp` px (4), sticks to the ground
going down (`$snapDown`), and fills `TileCollision` flags. `CollisionResult`: `onGround`, `hitCeiling`,
`hitLeft`, `hitRight`, `hitWall()`, `groundGid`, `platform`.
Options: `$collisions->solidEdges` (map sides are walls), `touchLayers = ['items']`,
`setPlatformProvider(fn () => [['x'=>..,'y'=>..,'width'=>..,'height'=>..,'vx'=>..,'vy'=>..,'oneWay'=>true]])` (moving platforms carry the entity).
Events: `$collisions->on('touch', fn (Entity $e, string $layer, int $tx, int $ty, int $gid, array $props) => ...)`
for tiles that have properties (collect: `$map->setTile($layer, $tx, $ty, 0)`), `on('fell', fn (Entity $e) => ...)`.
Remove old listeners in `enter()`: `$this->collisions->removeAllListeners();`.
`isSolidAt($map, $px, $py)`, `groundBelow($map, $x, $y)` for AI (edge detection).

Entity vs entity: `Aabb::intersects($a, $b)`, `Aabb::overlap($a, $b)` (push-out vector), `Aabb::sweep(...)`,
`$entity->intersects($rect)`, `$entities->overlapping($rect)`.

Steady physics: `FixedStep` — `$this->step = new FixedStep(120); $this->step->run($dt, fn (float $h) => $this->simulate($h));`
(fast objects: use 120 Hz so they do not tunnel).

---

## 9. Input, sound, saves, language, network

**Input** (`$this->actions()`, `ActionMap`): `isPressed('jump')` (this frame), `isHeld()`, `isReleased()`,
`axis('left', 'right')` (-1/0/1), `label('confirm')` (key name for texts), `hold($action, $bool)` /
`press($action)` (bots, demos). Raw: `$this->engine->input->getKeyboard()->isKeyHeld(\SDLK_x)`,
`getMouse()->x/y/isHeld(1)` (view coordinates), `getTouch()->active/x/y` (0..1 of the screen),
`getGamepad()`. config/input.yaml binding names: SDL key names (`Left`, `Space`, `Return`, `Escape`,
`Left Shift`, `A`..`Z`, `0`..`9`, `F1`..`F12`, `Keypad 8`), `pad0:button0`, `pad0:left`, `touch:tap`,
`touch:left`, `mouse:1`. Global keys: F3 debug overlay, F11 fullscreen (desktop).
In the browser, phones get an on-screen pad (arrows + A/B/START buttons; set their keys in
`game.yaml touch: {a: Space, b: Escape, start: Return}`).

**Sound** (`$this->sound()`, `SoundManager`): `loadSfx('jump', 'assets/sfx/jump.wav')`,
`synth('jump', Synth::sweep(300, 900, 0.15))` (generated: `Synth::tone($hz, $s, 'square'|'triangle'|'sine'|'saw')`,
`sweep($from, $to, $s)`, `noise($s)`, `sequence([[hz, s], ...])`), `playSfx('jump', $loops = 0)`, `stopSfx()`,
`hasSfx()`, `loadMusic('level', 'assets/music/level.ogg', 'assets/music/level-intro.ogg')`,
`playMusic('level')`, `stopMusic()`, `setSfxVolume(0..1)`, `setMusicVolume(0..1)`, `setMuted()`.
Without an audio device everything is silent, never an error. Formats: wav, ogg, mp3. Load each sound once
(`if (!$this->sound()->hasSfx('x'))`).

**Saves**: `$this->engine->storage->writeJson('save.json', $data)`, `readJson('save.json', $default)`,
`exists()`, `delete()`; settings: `$this->engine->options->get('difficulty', 'normal')`, `->set(...)`
(saved at once). Engine option keys: `sfx_volume`, `music_volume`, `fullscreen`, `lang`.

**Language**: `config/lang.yaml` (`fr: {'PLAY': 'JOUER'}`), `Lang::t('PLAY')`, `Lang::t('{n} COINS', ['n' => 3])`,
`$this->engine->lang->setLang('fr')`, `available()`. Menus translate their labels.

**Timers**: `$this->engine->gameLoop->addTimer(1.5, fn () => ...)`, `addPeriodicTimer()`, `GameLoop::nextTick()`.
Prefer counting `$dt` in the scene (timers keep running when the scene changes: check
`$this->scenes()->current() === $this` in the callback).

**Network** (online multiplayer, same code desktop and browser):
1. `bin/jumphp relay up` (server on `ws://localhost:8090/ws`; `game.yaml net_url`, or `?net=wss://host/ws` in the page URL, or env `JUMPHP_NET_URL`).
2. Lobby: `$this->scenes()->switchTo(LobbyScene::class, ['next' => PlayScene::class, 'nextParams' => ['mode' => 'online'], 'back' => TitleScene::class, 'players' => 2])`.
   It creates / joins (4-letter code) / quick-matches a room, then switches to `next` with
   `['online' => true, 'net' => NetSession, 'host' => bool, 'peer' => int] + nextParams`.
   `'auto' => 'quick'|'host'|'ABCD'` skips its menu; `?room=ABCD` in the page URL joins directly.
3. In the game scene (`NetSession $net = $params['net']`):
   ```php
   $net->removeAllListeners();
   $net->on('message', fn (int $from, int $type, string $payload) => ...);
   $net->on('peerLeave', fn (int $peer) => ...);
   $net->send(MSG_STATE, pack('gg', $x, $y), reliable: false);   // frequent state: unreliable
   $net->sendJson(MSG_EVENT, ['goal' => 1]);                       // rare events: reliable JSON
   ```
   The engine polls the session every frame. `isHost()`, `peerId()`, `peers()`, `playerCount()`, `roomCode()`,
   `lastPing()` (after `ping()`), `close()`. Decode: `MessageCodec::unpack('gx/gy', $payload)`, `MessageCodec::unjson($payload)`.
4. Design: the **host is the authority** (simulates, scores) and sends snapshots ~20/s; others send their
   input/state ~30/s; draw remote things through `SnapshotBuffer` (`push($this->engine->time(), $state)`,
   `sample($this->engine->time())`, 100-120 ms behind). One type byte per message. 64 KB max, 16 players max.
   See `games/pong/src/Scene/PlayScene.php` and `src/Net/PongProtocol.php`.
5. Test online headless: two processes with `JUMPHP_REALTIME=1`, `JUMPHP_SCENE='JumPHP\Net\LobbyScene'`,
   `JUMPHP_SCENE_PARAMS='{"next":"App\\Scene\\PlayScene","nextParams":{"mode":"online"},"auto":"quick"}'`,
   `JUMPHP_NET_URL=ws://127.0.0.1:8090/ws` and `--network host` (see docs/reseau.md).

**Threads** (`JumPHP\Async`, `$this->engine->tasks()`): heavy work (level generation, path finding, AI,
procedural pictures) off the main thread, so the frame never waits.

- One API, three backends (`Tasks::backend()`):
  - `'threads'`: desktop with the **async runtime** (`runtime: async` in `game.yaml`: `bin/jumphp` runs the
    game on PHP 8.6 ZTS + [TrueAsync](https://true-async.github.io), `docker/desktop-async`);
  - `'workers'`: **the browser**: a pool of Web Workers, each running its own PHP engine with the game's
    files (`engine/web/jumphp-worker.js`; cores - 1, at most 4; `?workers=N` in the page URL, 0 = none);
  - `'frames'`: default desktop runtime (PHP 8.3): jobs run on the main thread between frames,
    `$tasks->budget` ms per frame (4 by default, at least one job). Also the fallback when no Web Worker
    can start.
  `Tasks::threaded()` is true for the first two. A game must be correct with all three.
- API:
  ```php
  $task = $this->engine->tasks()->thread(static fn () => Maze::generate(64, 48, $seed));   // one job, one thread
  $pool = $this->engine->tasks()->pool();            // one worker per core (Tasks::workers()); in enter()
  $job = $pool->submit(static fn (int $y) => Fractal::row($y, 192), $y)                    // never blocks
      ->then(fn (string $row) => $this->rows[$y] = $row, fn (\Throwable $e) => ...);      // main thread
  $task->isDone(); $task->isOk(); $task->result(); $task->error();
  $pool->close();                                    // in exit()
  ```
  The engine polls the tasks at the start of each frame (before the scenes update): `then()` callbacks run
  there, on the main thread, so they may touch the scene.
- **What crosses to a thread is a copy**, in a fresh PHP world that only has the game's autoloader
  (`vendor/autoload.php`): no engine, no SDL, no scene, no container. In the browser the closure travels as
  its source code + captured values (laravel/serializable-closure): its scope class (the class it is written
  in, for `self::`) must be autoloadable, which scenes are. Use **`static fn`**: a closure written
  in a method carries `$this` (the scene and its SDL resources) even if it does not use it, and is refused
  with a `LogicException` in both modes. Pass plain values (int, float, string, arrays, simple objects); return
  plain values. No references (`&$x`), no resources (files, textures, sounds), no objects with dynamic
  properties. Put thread code in plain static classes (e.g. `src/Work/`, excluded from the container in
  `config/services.yaml`).
- An exception in a thread comes back as `JumPHP\Async\TaskFailed` (`->class`, message, file, line of the
  original): `$task->error()`, or `result()` rethrows it.
- Results only arrive between frames (TrueAsync reports finished threads while the desktop loop sleeps;
  headless runs wait 1 ms per frame while tasks are pending; the page hands Web Worker results over once per
  frame): never busy-loop on `isDone()`. A Web Worker needs ~0.5-1 s to boot its engine: create the pool early
  (in `enter()`), it starts the workers at once.
- Rows of an older request can arrive after a new one started: tag jobs (a generation counter) and ignore
  stale results. Example: `games/hello-threads` (fractal by a thread pool, 60 FPS kept).
- Raw TrueAsync (`Async\spawn`, coroutines, channels, `Async\ThreadChannel`) is available in the async runtime
  too, but only `JumPHP\Async` also works in the browser. Browser jobs can not share memory with the game
  (each worker is a separate PHP): no global state survives between jobs on purpose.

**Web page** (`$this->engine->bridge->send('open', ['url' => ...])`, `send('quit')`, `send('share', [...])`;
custom types reach `window.JumPHP.onBridge(msg)` and the parent frame as `postMessage({type: 'jumphp:bridge'})`).
`Platform::isWasm()`, `isDesktop()`, `isMobile()`. `$this->engine->quit()`: desktop closes, browser goes to
`?back=` or shows "thanks for playing".

---

## 10. Definition of done (always)

1. `bin/jumphp test <id>` passes (it runs your `smoke` scene 600 frames; make that a self-playing demo).
2. `bin/jumphp shot <id> 60` (title) and `JUMPHP_INPUT='[[5,"confirm"]]' bin/jumphp shot <id> 240 var/screenshots/play.png`
   (in game): **open the PNGs and check** that what you see is what you intended.
3. Menus reachable, `pause` / `back` work, the game can be won / lost and restarted.
4. `bin/jumphp lint` passes.
5. If the browser engine exists (`build/engine/php-web.wasm`): `bin/jumphp build <id>` then
   `bin/jumphp webshot <id> 6 '[[1,"Enter"]]'` passes and its screenshot looks right.
6. Update `games/<id>/AGENTS.md` (what the game is, its scenes, its controls, anything non-obvious).

## 11. Troubleshooting

| Symptom | Fix |
|---|---|
| `Unknown texture 'x'` | `loadTexture` / `createTexture*` before use (in `enter()` or the constructor), check the path |
| Black screen in a shot | the scene draws nothing or outside the view; `JUMPHP_DEBUG=1`; check `render()` uses view coordinates minus the camera |
| `Scene class ... not found` | namespace `App\Scene\...` must match `src/Scene/...php`; `bin/jumphp composer <id> dump-autoload` |
| A scene keeps old state | reset it in `enter()` |
| Entity sinks / jitters on the ground | move it only with `CollisionSystem::move()` (not also `Entity::update()` velocity integration); keep `width/height` = the collision box |
| Fast object goes through walls | `FixedStep(120)` or smaller speed |
| Smoke test fails on a warning | fix the warning: undefined index / property, division by zero... |
| Works on desktop, not in the browser | §2: a blocking call, a 64-bit integer, a socket, a missing file (only the game folder is packaged; `var/` is not) |
| Browser: "The game did not start" | open the console (webshot prints it): PHP boot error with file and line |
| `bin/jumphp install` fails fetching `php-wasm-network` | the first install clones it from GitHub (network needed once); later installs use the composer cache |
| Composer package needs an extension | not possible in the browser: pure PHP only |
| `Class "App\..." not found` inside a thread | the thread only has the autoloader: use `JumPHP\Async` (it loads `vendor/autoload.php` there), not raw `Async\spawn_thread` without a bootloader |
| `LogicException ... must be a static closure` | write `static fn () => ...` (or a static method) for work sent to `thread()` / `submit()` |
| `ThreadTransferException`, `Cannot transfer a resource between threads` | a reference, a resource or an object with dynamic properties was sent to a thread (as an argument or a captured `use` variable): plain values only |
| The game freezes while threads work | a busy loop on `isDone()`, or raw `Async\ThreadPool::submit()` with a full queue: use `JumPHP\Async\Pool` (never blocks) |

## 12. Reference

- `games/pong/` — complete game: menus, options, AI, local 2 players, online, pause, game over.
- `games/hello-threads/` — threads (`runtime: async`): a thread pool computes a fractal, the frame never waits.
- `skeleton/` — platformer: tiles, slopes, one-way platforms, camera, coins, sprite from pixel strings.
- `engine/stubs/sdl.stub.php` — every raw SDL / SDL_image / SDL_mixer / SDL_ttf function and constant.
- `engine/tests/` — usage examples of CollisionSystem, TiledJsonLoader, SceneManager, Menu, Net.
- `docs/` (French) — architecture, build, browser pitfalls, tiles, network, porting an existing game.
