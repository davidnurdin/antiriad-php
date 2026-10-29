/** One step of `bin/jumphp test`: php -l, phpstan, phpunit, the headless smoke test. */
export type Stage = { name: string; status: 'run' | 'ok' | 'fail' }

/** A game test (JumPHP\Testing\GameTestCase) of the run: running, done, then passed or failed; its last frame. */
export type GameTest = { name: string; label: string; status: 'run' | 'done' | 'ok' | 'fail'; frame: string }

/** The last `bin/jumphp` command seen (Claude's, or one started from the pane). */
export type Check = {
  game: string
  kind: string
  by: 'claude' | 'you'
  status: 'running' | 'passed' | 'failed'
  stages: Stage[]
  /** the end of its output, as it comes */
  log: string
  /** PHPUnit's last line (OK (…) / Tests: …), if any */
  summary: string
  /** what failed: PHPUnit's failed tests, PHP warnings and errors */
  failures: string[]
  /** the picture the run rewrites as it goes (JUMPHP_LIVE: bin/jumphp says `live frames: <path>`), or '' */
  live: string
  /** the game tests, in the order they ran */
  tests: GameTest[]
  at: number
}

/**
 * What the pane shows: the test whose last frame is drawn, whether the runs go at the game's speed, how pictures
 * are drawn (`blocks`: coloured half blocks, any terminal; `image`: the PNG, kitty / Ghostty / iTerm2).
 */
export type View = { selected: string; isRealtime: boolean; pixels: 'blocks' | 'image' }

/** A picture of the game: from `bin/jumphp shot` / `siteshot`, or one Claude read. */
export type Shot = { path: string; at: number; n: number }

/** The playground's last answer about the game (MCP run_tests / get_test_run / sync_project). */
export type Remote = { tool: string; status: string; detail: string; at: number }

/** The project's root (where bin/jumphp is) and the game the buttons act on. */
export type Where = { root: string; game: string }

declare module 'claude-code' {
  interface PluginState {
    'jumphp-live': {
      check: Shaped<Check | null>
      shots: Shaped<Shot[]>
      remote: Shaped<Remote | null>
      where: Shaped<Where>
      view: Shaped<View>
    }
  }
}
