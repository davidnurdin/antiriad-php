# pong — notes for agents

The reference JumPHP game. **Read `../../AGENTS.md` first.** Use this game as the example of every feature.

## Checklist

```bash
bin/jumphp test pong
bin/jumphp shot pong 60                                                          # title
JUMPHP_SCENE='App\Scene\PlayScene' JUMPHP_SCENE_PARAMS='{"mode":"demo"}' bin/jumphp shot pong 400
bin/jumphp build pong && bin/jumphp webshot pong 5 '[[1.5,"Enter"]]'
```

## The game

- **Modes** (`PlayScene` params): `cpu` (player left vs computer), `local` (W/S vs Up/Down), `online`
  (from `LobbyScene`: host = left paddle, authority on the ball), `demo` (computer vs computer, endless:
  the smoke test and nothing else).
- **Scenes**: `TitleScene` (menu over a bouncing ball) → `PlayScene`; overlays `PauseScene`,
  `GameOverScene` (`text`, `again` params); `OptionsScene` pushed from the title (sound, points to win,
  computer level, language, fullscreen) — saved in `var/options.json` (keys `points`, `cpu`, `sfx_volume`, `lang`).
- **Entities**: `Paddle` (clamped to the court), `Ball` (speeds up 7 % per hit, angle from the hit offset,
  trail). Physics at 120 Hz (`FixedStep`) so the ball never tunnels through a paddle.
- **AI**: `Ai\CpuPlayer` predicts where the ball crosses its side (wall bounces unfolded), with reaction
  time, speed and aiming error per level.
- **Online**: `Net\PongProtocol` — guest sends `STATE` (its paddle, 30/s, unreliable), host sends `SNAPSHOT`
  (ball, paddles, score, 20/s, unreliable) and `EVENT` (sounds, game over, reliable JSON). The guest draws the
  host's world 100 ms in the past through `SnapshotBuffer`. Escape leaves an online match.
- **Sounds**: all generated with `Synth` (no audio file). **Font**: the built-in `BitmapFont`.
- **Touch**: the paddle follows the finger (`PlayScene::solo()`); on-screen START / A = Return, B = Escape.
