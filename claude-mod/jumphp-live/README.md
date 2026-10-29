# jumphp-live: JumPHP beside the chat

A Claude Code mod for making a [JumPHP](https://github.com/davidnurdin/jumPhp) game with Claude: a **JumPHP pane**
next to the conversation that shows what Claude is doing to the game, as it happens, and lets you step in.

- **The checks, live**: when Claude runs `bin/jumphp test <game>`, its output streams into the pane and each check
  is marked as it goes: `✔ php -l   ✔ phpstan   … phpunit   smoke test`. When it ends: PHPUnit's summary and
  what failed (the failed tests, PHP warnings and errors).
- **The screens**: the screenshot `bin/jumphp shot` / `siteshot` just wrote, and every picture Claude reads to
  look at the game, drawn in the pane (kitty, Ghostty, iTerm2; the path elsewhere).
- **The playground**: the result of `run_tests` / `get_test_run` (PHPUnit in the playground's sandbox) and of
  `sync_project` when Claude uses the JumPHP playground's MCP server.
- **Your hands on it**: `▶ Tests` (key `t`) and `📸 Shot` (`s`) run them now, in the pane, without Claude;
  `🛠 Ask Claude to fix` (`f`) after a failure; a field to tell Claude something (it reads it after its current
  step).

## Install

At the prompt of a Claude Code terminal session:

```
/plugin install jumphp-live --marketplace davidnurdin/jumPhp
```

Answer `y` to add the marketplace, then pick a scope. From a shell: `claude plugin install jumphp-live --marketplace davidnurdin/jumPhp`.

## Use

In your JumPHP project (the folder with `bin/jumphp`), start Claude Code and type `/jumphp <game>` (for instance
`/jumphp pong`): the pane opens on the right (a wide terminal: 110 columns or more). It also opens by itself the
first time Claude runs `bin/jumphp test` or `shot`.

How it follows Claude's commands: a Bash command of Claude's that runs `bin/jumphp test|shot|siteshot|webshot` is
run as `{ <command> } 2>&1 | tee <log>` with its exit status kept, and the pane follows that log. Other commands
are not touched.
