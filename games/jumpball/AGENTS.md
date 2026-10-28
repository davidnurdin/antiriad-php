# jumpball — notes for agents

This game is built with JumPHP. **Read `../../AGENTS.md` first** (rules, commands, API).

## Checklist

```bash
bin/jumphp test jumpball                 # lint + phpstan + headless smoke test (the bot plays, OpenGL on Xvfb)
JUMPHP_GL=0 bin/jumphp test jumpball     # the same without OpenGL: SoftwareView
bin/jumphp shot jumpball 90              # title screen over the bot's run
JUMPHP_SCENE='App\Scene\PlayScene' JUMPHP_SCENE_PARAMS='{"demo":true,"seed":3}' bin/jumphp shot jumpball 900 var/screenshots/play.png
bin/jumphp build jumpball && bin/jumphp webshot jumpball 8 '[[1,"Enter"],[3,"ArrowLeft"]]'   # WebGL
```

## This game

- **What it is**: a 3D runner. The ball rolls forward on its own (6 → 14 m/s); steer between 5 lanes, jump over
  holes, go around blocks or jump on them. Score = metres + 10 per gem; the best one is in `var/best.json`.
- **Logic** (`src/Game`, plain objects, excluded from the container):
  - `Track`: rows of 5 cells (hole / floor / block) + gems, generated in segments from a seed with a
    Park-Miller generator (floats: same track on 32-bit wasm and 64-bit desktop). The straight part before
    each obstacle is one jump long at the speed expected there (`Track::speedAt()`), platforms are long
    enough to land on.
  - `Run`: the ball (fixed step 120 Hz from the scenes), states run / fall / crash / over, events read by the
    scene for sounds, and `autopilot()` (the bot of the title screen, the demo and the smoke test).
- **Drawing** (`src/Gfx`): `World` (a shared service) draws a `Run`. OpenGL / WebGL layer below the 2D
  (`KEY` magenta) at the window's resolution, supersampled x2 (`setSupersample(2)`: smooth edges); the HUD keeps
  its big pixels. The track in chunks of 16 rows, one dynamic buffer each, recycled with `updateBuffer()` when a
  chunk leaves the view. `Meshes`: vertex = position, colour, normal; ONE lit program for everything, chosen by
  `u_material` (0 track: dark glossy tiles + neon borders computed from the world position, a light wave, the
  ball's soft shadow and glow; 1 ball: shiny, reflects the sky; 2 gem; 3 particle; 4 the neon grid 7 m below).
  Sky = full-screen quad (gradient, stars, striped sun, mountain ridge). Post shader = bloom (24 taps), colour
  fringe, vignette. Particles (`World::particles()`): sparks when a gem is taken, a trail when fast.
  Without OpenGL: `SoftwareView` (same camera matrix, convex faces filled per pixel row, far to near, the ball
  after its row).
- **Scenes**: `TitleScene` (menu over the bot's run) → `PlayScene` (params `demo`, `seed`) → `GameOverScene`
  (overlay: retry / title); `PauseScene` (overlay, also `pause_scene` of game.yaml), `OptionsScene` (pushed).
- **Controls** (`config/input.yaml`): left / right (or the left stick, analog), `jump` = Space, Up, W, Z,
  pad A, a tap. Holding jump jumps again at each landing.
- **Smoke test**: `PlayScene` with `{demo: true, seed: 42}`; the bot restarts by itself when it falls.
- **Non-obvious**: a program keeps its uniform values between draws: `World` passes every uniform of the lit
  program on each draw. Shaders use highp when the GPU has it (world coordinates through `fract()`) and keep
  `sin()` arguments small. Tuning: `Run` constants (speed, jump, gravity), `Track::segment()`
  odds, `Meshes::ZONES` colours.
