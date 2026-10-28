# hello-webgl — notes for agents

This game is built with JumPHP. **Read `../../AGENTS.md` first** (rules, commands, API), especially the
WebGL, lifecycle, gamepad and PWA paragraphs of §9, and `../../docs/api-web.md`.

## Checklist

```bash
bin/jumphp test hello-webgl                   # lint + phpstan + headless smoke test (desktop: software 3D)
bin/jumphp shot hello-webgl 60                # title screen
JUMPHP_SCENE='App\Scene\CubesScene' bin/jumphp shot hello-webgl 90 var/screenshots/play.png
bin/jumphp build hello-webgl && bin/jumphp webshot hello-webgl 6 '[[1,"Enter"],[3," "]]'   # WebGL + CRT shader
```

## This game

- **What it is**: the "hello world" of `JumPHP\Gl`. Five textured cubes turn over a floor. In the browser
  they are real 3D (WebGL, shaders written as PHP strings in `src/Gfx/Shaders.php`), drawn in a layer *below*
  the 2D: the scene clears the view with the key colour (magenta) and draws its HUD with plain SDL calls.
  Space / A cycles post-processing shaders on the whole picture (none, CRT, wave, 4 colours with dither).
- **Desktop**: no WebGL (`WebGl::isAvailable()` false): the same scene in wireframe, projected with the same
  matrices (`Mat4::project()` + `drawLine`). The smoke test and `shot` run this path.
- **Scenes**: `TitleScene` (menu, + INSTALL / UPDATE when the PWA allows it) → `CubesScene`; overlay
  `PauseScene` (also pushed automatically when the tab is hidden / the window loses the focus:
  `pause_scene` in game.yaml).
- **WebGL resources** are created once (`$this->ids`, the page keeps them); drawing is immediate: `draw()`
  every frame in `render()`. `exit()` calls `$gl->disable()`.
- **Controls**: arrows turn / zoom; gamepad: sticks turn, LT / RT zoom, A next shader (with rumble),
  Start pause; Space / Return next shader; Escape pause.
- **PWA**: `pwa:` in game.yaml: installable, plays offline after the first visit (https or localhost).
- **Smoke test**: `CubesScene` with `demo: true` (changes the shader every 2.5 s).
