# breakout — notes for agents

This game is built with JumPHP. **Read `../../AGENTS.md` first** (rules, commands, API).

## Checklist

```bash
bin/jumphp test breakout                 # lint + phpstan + headless smoke test (must pass)
bin/jumphp shot breakout 60              # title screen -> games/breakout/var/screenshots/shot.png
JUMPHP_INPUT='[[5,"confirm"]]' bin/jumphp shot breakout 240 var/screenshots/play.png
JUMPHP_SCENE='App\Scene\PlayScene' JUMPHP_SCENE_PARAMS='{"demo":true,"keep":2}' bin/jumphp shot breakout 600   # next level
JUMPHP_SCENE='App\Scene\PlayScene' bin/jumphp shot breakout 1500    # nobody plays: game over overlay
bin/jumphp build breakout && bin/jumphp webshot breakout 6 '[[1,"Enter"]]'
```

## This game

- **What it is**: a brick breaker, 320×240. 3 lives, score, best score (`var/options.json` key `best`).
  Clearing a wall gives +500 and the next level (another layout, faster ball). Layouts loop after the last.
- **Scenes**: `TitleScene` (menu over sliding bricks: PLAY, OPTIONS, QUIT on desktop) → `PlayScene`;
  overlays `PauseScene` (Escape / P, Escape again resumes), `GameOverScene` (params `score`, `level`,
  `record`, `again`: PLAY AGAIN / TITLE SCREEN); `OptionsScene` pushed from the title (sound volume, language,
  fullscreen).
- **PlayScene params**: `level` (first level), `demo` (the computer plays, endless: smoke test), `keep`
  (keep only the last N bricks of each wall: tests reach the next level quickly).
  States: `serve` (ball on the paddle, launches with Space or by itself after 3 s), `play`, `clear`
  ("LEVEL n" for 1.6 s), `over`.
- **Entities**: `Paddle` (clamped between the walls), `Ball` (angle from the hit point on the paddle,
  speeds up 1.2 % per brick), `Brick` (kinds r o y g b p = 1 hit, `S` silver = 2 hits, points per kind).
  They are plain arrays in the scene (no EntityManager, no tiles).
- **Levels**: `src/Level/Layouts.php` — one string per row, 13 columns. Add a level = add an array.
- **Physics**: `FixedStep(120)`, the ball moves x then y; a brick hit reverses the axis that hit it.
- **Controls**: Left / Right (A / D, pad, touch drag) move; Space / Up / Return / tap launch; Escape / P pause.
- **Sounds**: all `Synth` (one pitch per brick row). **Font**: built-in BitmapFont.
- **Non-obvious**: `Entity::$x/$y/$vx/$vy` are protected: scenes use `setX()`, `getFloatX()`, `setSpeedX()`.
  Headless runs write `var/options.json` too (volume, best score): delete it for a clean title screen.
