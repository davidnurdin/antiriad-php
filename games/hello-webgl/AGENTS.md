# hello-webgl — notes for agents

This game is built with JumPHP. **Read `../../AGENTS.md` first** (rules, commands, API), especially the
OpenGL / WebGL, positional sound, lifecycle, gamepad and PWA paragraphs of §9, and `../../docs/api-web.md`.

## Checklist

```bash
bin/jumphp test hello-webgl                   # lint + phpstan + headless smoke test (OpenGL on Xvfb)
bin/jumphp shot hello-webgl 60                # title screen
JUMPHP_SCENE='App\Scene\CubesScene' bin/jumphp shot hello-webgl 90 var/screenshots/play.png
JUMPHP_SCENE='App\Scene\RawGlScene' bin/jumphp shot hello-webgl 60 var/screenshots/raw.png
JUMPHP_GL=0 JUMPHP_SCENE='App\Scene\CubesScene' bin/jumphp shot hello-webgl 90 var/screenshots/nogl.png   # the fallback
bin/jumphp build hello-webgl && bin/jumphp webshot hello-webgl 6 '[[1,"Enter"],[3," "]]'   # WebGL + CRT shader
```

## This game

- **What it is**: the "hello world" of `JumPHP\Gl`. Five textured cubes turn over a floor, in real 3D on both
  targets (PHP-OpenGL: OpenGL on the desktop, WebGL in the browser; shaders written as PHP strings in
  `src/Gfx/Shaders.php`), drawn in a layer *below* the 2D: the scene clears the view with the key colour
  (magenta) and draws its HUD with plain SDL calls. Space / A cycles post-processing shaders on the whole
  picture (none, CRT, wave, 4 colours with dither).
- **Without OpenGL** (`WebGl::isAvailable()` false: `JUMPHP_GL=0`, no GPU): the same scene in wireframe,
  projected with the same matrices (`Mat4::project()` + `drawLine`).
- **`RawGlScene`** (menu RAW OPENGL): raw `gl*` calls between `opengl()->begin()` and `end()` (a lit torus and
  cube, per-pixel light), and `audio3d()`: a looping beep placed on the orbiting cube, the listener at the camera.
- **Scenes**: `TitleScene` (menu, + INSTALL / UPDATE when the PWA allows it) → `CubesScene` | `RawGlScene`; overlay
  `PauseScene` (also pushed automatically when the tab is hidden / the window loses the focus:
  `pause_scene` in game.yaml).
- **WebGL resources** are created once (`$this->ids`, the page keeps them); drawing is immediate: `draw()`
  every frame in `render()`. `exit()` calls `$gl->disable()`.
- **Controls**: arrows turn / zoom; gamepad: sticks turn, LT / RT zoom, A next shader (with rumble),
  Start pause; Space / Return next shader; Escape pause.
- **PWA**: `pwa:` in game.yaml: installable, plays offline after the first visit (https or localhost).
- **Smoke test**: `CubesScene` with `demo: true` (changes the shader every 2.5 s).
