# hello-threads — notes for agents

This game is built with JumPHP. **Read `../../AGENTS.md` first** (rules, commands, API), especially the
"Threads" section.

## Checklist

```bash
bin/jumphp test hello-threads                 # lint + phpstan + headless smoke test (must pass)
bin/jumphp shot hello-threads 60              # title screen
JUMPHP_SCENE='App\Scene\FractalScene' bin/jumphp shot hello-threads 120 var/screenshots/play.png
JUMPHP_RUNTIME=default bin/jumphp test hello-threads   # the same game without threads (fallback path)
bin/jumphp build hello-threads && bin/jumphp webshot hello-threads 12 '[[1,"Enter"]]'  # browser (Web Workers)
```

## This game

- **What it is**: the "hello world" of `JumPHP\Async`. A Mandelbrot picture (192×144, 2500 iterations) is
  computed row by row by a pool of worker threads, while the main thread keeps drawing at 60 FPS (the
  turning bar proves it never stops). `M` computes the same picture on the main thread instead (4 ms per
  frame) to compare the times.
- **Runtime**: `game.yaml runtime: async` → `bin/jumphp` uses the PHP 8.6 ZTS + TrueAsync image
  (`docker/desktop-async`). In the browser the pool uses Web Workers (`Tasks::backend() === 'workers'`); on
  the default PHP 8.5 runtime it runs the jobs between frames. The panel shows which backend runs.
- **Scenes**: `TitleScene` (menu) → `FractalScene` ; overlay `PauseScene`.
- **Thread code**: `src/Work/Fractal.php`, pure static code (excluded from the container in
  `config/services.yaml`). Jobs are `static fn` with plain arguments; results are strings of iteration bytes,
  drawn into the `fractal` texture in `FractalScene::flushRows()`.
- **Controls**: Space / Enter next spot, M (Tab) threads ↔ main thread, Escape pause.
- **Smoke test**: `FractalScene` with `demo: true` (goes to the next spot after each picture, alternating
  threads and main thread).
