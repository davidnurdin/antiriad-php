// jumphp-live: a JumPHP pane beside the chat while Claude makes a game.
//  - Claude's `bin/jumphp test|shot|siteshot …` commands: their output copied to a log (tee) the pane follows
//    live, each check (php -l, phpstan, phpunit, smoke test) marked ✔ / ✘, what failed.
//  - The pictures: those `bin/jumphp shot` / `siteshot` write and those Claude reads (Read of a .png).
//  - The playground (MCP jumphp): its PHPUnit runs, its publications.
//  - Buttons: run the tests or take a screenshot now (here, live), ask Claude to fix; a field to tell Claude.
import { atom, read, update } from 'claude-code'
import type { EngineInterface as Engine, Register } from 'claude-code'

import type { Check, Remote, Shot, Stage, Where } from '../types'

const PANE = 'jumphp-live'
const TITLE = 'JumPHP'
const MAX_LOG = 12_000

const check = atom({ plugin: 'jumphp-live', key: 'check' } as const, null as Check | null, { shape: 'check-1' })
const shots = atom({ plugin: 'jumphp-live', key: 'shots' } as const, [] as Shot[], { shape: 'shots-1' })
const remote = atom({ plugin: 'jumphp-live', key: 'remote' } as const, null as Remote | null, { shape: 'remote-1' })
const where = atom({ plugin: 'jumphp-live', key: 'where' } as const, { root: '', game: '' } as Where, { shape: 'where-1' })

/** a bin/jumphp command that checks or pictures a game: its kind and game */
const JUMPHP = /(?:^|[\s;&|(])(?:\.\/)?bin\/jumphp\s+(test|shot|siteshot|webshot)\b[ \t]*([\w.-]*)/
const PICTURE = /\.(png|jpe?g|webp)$/i
const ANSI = /\x1b\[[0-9;]*[A-Za-z]/g

const clip = (text: string): string => (text.length > MAX_LOG ? text.slice(-MAX_LOG) : text)

/** the checks bin/jumphp announced (`==> games/pong: phpstan`), the last one running, or failed, or done */
export function stagesOf(log: string, status: Check['status']): Stage[] {
  const names = [...log.matchAll(/==> (?:[^\s:]+: )?([^\n]+)/g)]
    .map(m => (m[1] ?? '').trim().replace(/ \(.*$/, ''))
    .filter(name => !/all checks passed/.test(name))
  return names.map((name, i): Stage => ({
    name,
    status: i < names.length - 1 || status === 'passed' ? 'ok' : status === 'running' ? 'run' : 'fail',
  }))
}

/** PHPUnit's summary, and what failed: its failed tests, PHP's warnings and errors, bin/jumphp's [x] */
export function resultOf(log: string): { summary: string; failures: string[] } {
  const summaries = [...log.matchAll(/^(OK \(\d+ tests?, \d+ assertions?\)|Tests: \d+, Assertions: \d+[^\n]*)$/gm)]
  const failures = [
    ...[...log.matchAll(/^\d+\) ([\w\\]+::\w+[^\n]*)$/gm)].map(m => m[1] ?? ''),
    ...[...log.matchAll(/^.*(?:PHP (?:Warning|Fatal error|Parse error|Notice|Deprecated)|Parse error|\[x\]).*$/gm)].map(m => m[0].trim()),
  ]
  return { summary: summaries.at(-1)?.[1] ?? '', failures: [...new Set(failures)].slice(0, 8) }
}

/** absolute paths of pictures in an output (bin/jumphp shot prints the file it wrote) */
export function picturesIn(log: string): string[] {
  return [...new Set([...log.matchAll(/(\/[^\s'"]+\.(?:png|jpe?g|webp))\b/gi)].map(m => m[1] ?? ''))]
}

let seen = 0

async function begin($: Engine, kind: string, game: string, by: Check['by']): Promise<void> {
  const at = await $.clock.now()
  await update($, check, (): Check => ({ game, kind, by, status: 'running', stages: [], log: '', summary: '', failures: [], at }))
  if (game) {
    await update($, where, w => ({ ...w, game }))
  }
  void $.ui.open({ id: PANE, title: TITLE })
}

function progress($: Engine, log: string): Promise<unknown> {
  return update($, check, c => (c ? { ...c, log: clip(log), stages: stagesOf(log, 'running') } : c))
}

async function finish($: Engine, log: string, isOk: boolean): Promise<void> {
  const text = log.replace(ANSI, '')
  await update($, check, (c): Check | null => {
    if (!c) {
      return c
    }
    // a test passes when bin/jumphp says so; a picture when the command ended well
    const status: Check['status'] = isOk && (c.kind !== 'test' || text.includes('all checks passed')) ? 'passed' : 'failed'
    return { ...c, status, log: clip(text), stages: stagesOf(text, status), ...resultOf(text) }
  })
  for (const path of picturesIn(text)) {
    await addShot($, path)
  }
  const c = await read($, check)
  if (c?.kind === 'test') {
    $.ui.status(c.status === 'passed' ? `JumPHP ${c.game}: ✔ checks` : `JumPHP ${c.game}: ✘ ${c.stages.at(-1)?.name ?? 'checks'}`)
  }
}

async function addShot($: Engine, path: string): Promise<void> {
  const at = await $.clock.now()
  seen += 1
  await update($, shots, list => [{ path, at, n: seen + at }, ...list.filter(s => s.path !== path)].slice(0, 6))
}

/** follows a growing file (tail -F) into the pane until stop() */
function follow($: Engine, file: string): { stop: () => Promise<void> } {
  const tail = $.process.spawn({ argv: ['tail', '-c', '+1', '-F', file] })
  let log = ''
  const loop = (async () => {
    try {
      for await (const { text } of tail) {
        log += text.replace(ANSI, '')
        await progress($, log)
      }
    } catch {
      // tail ended
    }
  })()
  return {
    stop: async () => {
      await $.clock.sleep(400)
      await tail.return(undefined as never)
      await loop
    },
  }
}

/** a bin/jumphp command started from the pane: its output streams into it */
async function runHere($: Engine, kind: 'test' | 'shot'): Promise<void> {
  const { root, game } = await read($, where)
  if (!game) {
    $.ui.toast('JumPHP: no game yet — type /jumphp <game>')
    return
  }
  await begin($, kind, game, 'you')
  let log = ''
  let code = 1
  try {
    const run = $.process.spawn({ argv: ['bin/jumphp', kind, game], cwd: root || undefined })
    for (;;) {
      const piece = await run.next()
      if (piece.done) {
        code = piece.value.code ?? 1
        break
      }
      log += piece.value.text.replace(ANSI, '')
      await progress($, log)
    }
  } catch (error) {
    log += `\n[x] bin/jumphp: ${String(error)}`
  }
  await finish($, log, code === 0)
}

export const register: Register = on => {
  on('session.start', async ($, e, next) => {
    await $.command.register({ name: 'jumphp', description: 'Open the JumPHP pane (tests, screenshots); /jumphp <game> to choose the game', argumentHint: '[game]' })
    await update($, where, w => ({ ...w, root: w.root || e.cwd }))
    return next(e)
  })

  on('command.run', { command: 'jumphp' }, async ($, e) => {
    const game = e.args.trim()
    if (game) {
      await update($, where, w => ({ ...w, game }))
    }
    const opened = await $.ui.open({ id: PANE, title: TITLE, focus: true })
    return { text: opened.isPlaced ? `JumPHP pane open${game ? ` (game ${game})` : ''}.` : 'JumPHP pane: the window is too narrow to open it.' }
  })

  // Claude's bin/jumphp commands: copied to a log the pane follows, the exit status kept (PIPESTATUS)
  on('tool.call', { tool: 'Bash' }, async ($, e, next) => {
    const m = JUMPHP.exec(e.command)
    if (!m) {
      return next(e)
    }
    const at = await $.clock.now()
    const file = `/tmp/jumphp-live-${at}-${Math.floor(Math.random() * 1e6)}.log`
    await begin($, m[1] ?? 'test', m[2] || (await read($, where)).game, 'claude')
    const live = follow($, file)
    const ran = await next({ ...e, command: `{ ${e.command}\n} 2>&1 | tee ${file}; ( exit \${PIPESTATUS[0]} )` })
    await live.stop()
    const result = 'result' in ran && ran.result ? (ran.result as { stdout?: string; stderr?: string }) : {}
    await finish($, `${result.stdout ?? ''}\n${result.stderr ?? ''}`, ran.deny === undefined && ran.isError !== true)
    void $.process.run(['rm', '-f', file])
    return ran
  })

  // the pictures Claude looks at
  on('tool.call', { tool: 'Read' }, async ($, e, next) => {
    const ran = await next(e)
    if (PICTURE.test(e.file_path) && ran.deny === undefined && ran.isError !== true) {
      await addShot($, e.file_path)
      void $.ui.open({ id: PANE, title: TITLE })
    }
    return ran
  })

  // the playground's answers (MCP server jumphp): its PHPUnit runs and its publications
  on('tool.call', async ($, e, next) => {
    const tool = /^mcp__.*jumphp__(run_tests|get_test_run|sync_project)$/.exec(e.tool)?.[1]
    if (!tool) {
      return next(e)
    }
    const ran = await next(e)
    const text = JSON.stringify('result' in ran ? ran.result ?? '' : '').replace(/\\"/g, '"')
    const status = /"status":\s*"(\w+)"/.exec(text)?.[1] ?? (ran.isError ? 'error' : '?')
    const count = (key: string) => new RegExp(`"${key}":\\s*(\\d+)`).exec(text)?.[1] ?? null
    const tests = count('tests')
    const failures = count('failures')
    const detail = tool === 'sync_project'
      ? (/"errors":\s*\[\s*"([^"]+)/.exec(text)?.[1] ?? '')
      : tests !== null ? `${tests} tests, ${failures ?? 0} failures` : ''
    const at = await $.clock.now()
    await update($, remote, (): Remote => ({ tool, status, detail, at }))
    void $.ui.open({ id: PANE, title: TITLE })
    return ran
  })

  on('ui.render', { component: 'Pane', requestId: PANE }, async ($, e) => {
    const ui = $.ui.resolve(e)
    const { Box, Text, Button } = ui
    const Input = 'Input' in ui ? ui.Input : null
    const c = await read($, check)
    const pictures = await read($, shots)
    const r = await read($, remote)
    const { game } = await read($, where)
    const width = Math.max(20, e.props.bodyColumns ?? 60)
    const color = (s: string) => (s === 'passed' || s === 'ok' ? 'success' : s === 'failed' || s === 'fail' || s === 'error' ? 'error' : 'warning')
    const mark = (s: Stage['status']) => (s === 'ok' ? '✔' : s === 'fail' ? '✘' : '…')
    const logLines = c ? c.log.split('\n').filter(l => l.trim() !== '').slice(c.status === 'running' ? -8 : -4) : []
    const shot = pictures[0]
    const Image = e.surface === 'terminal' && 'Image' in ui ? ui.Image : null
    const imageColumns = Math.min(width, 72)

    return (
      <Box flexDirection="column">
        <Text bold>🎮 {game || 'no game yet (/jumphp <game>)'}</Text>
        {!c && <Text dimColor>Waiting for bin/jumphp test or shot (by Claude, or ▶ below).</Text>}
        {c && (
          <Box flexDirection="column">
            <Text>
              <Text color={color(c.status)} bold>{c.status === 'running' ? '⏳ running' : c.status === 'passed' ? '✔ passed' : '✘ failed'}</Text>
              <Text dimColor> bin/jumphp {c.kind} {c.game} · {c.by === 'claude' ? 'Claude' : 'you'}</Text>
            </Text>
            {c.stages.length > 0 && (
              <Text>{c.stages.map(s => `${mark(s.status)} ${s.name}`).join('   ')}</Text>
            )}
            {c.summary !== '' && <Text color={color(c.status)}>{c.summary}</Text>}
            {c.failures.map(f => <Text color="error">✘ {f.slice(0, width * 2)}</Text>)}
            {logLines.map(line => <Text dimColor wrap="truncate-end">{line}</Text>)}
          </Box>
        )}
        {r && (
          <Text>
            <Text dimColor>playground {r.tool}: </Text>
            <Text color={color(r.status)}>{r.status}</Text>
            {r.detail !== '' && <Text dimColor> · {r.detail}</Text>}
          </Text>
        )}
        {shot && Image && (
          <Image key="shot" source={{ file: shot.path, format: 'png', generation: shot.n }} columns={imageColumns} rows={Math.max(4, Math.round(imageColumns * 9 / 32))} alt={shot.path} />
        )}
        {shot && <Text dimColor wrap="truncate-start">{shot.path}</Text>}
        <Box flexDirection="row" gap={1}>
          <Button key="test" label="▶ Tests" hotkey="t" onPress={() => void runHere($, 'test')} />
          <Button key="shot" label="📸 Shot" hotkey="s" onPress={() => void runHere($, 'shot')} />
          {c?.status === 'failed' && (
            <Button key="fix" label="🛠 Ask Claude to fix" hotkey="f" onPress={async () => {
              const what = [c.stages.find(s => s.status === 'fail')?.name, ...c.failures.slice(0, 5)].filter(Boolean).join('; ')
              await $.prompt.submit({ text: `bin/jumphp ${c.kind} ${c.game} fails (${what}). Find the cause, fix it, and run bin/jumphp test ${c.game} again.`, asUser: true })
              $.ui.toast('Sent to Claude')
            }} />
          )}
        </Box>
        {Input && <Input key="say" label="Tell Claude: " placeholder="e.g. make the jump higher" submitLabel="send" onSubmit={async (value: string) => {
          if (value.trim() === '') {
            return
          }
          await $.prompt.submit({ text: value.trim(), asUser: true })
          $.ui.toast('Sent to Claude (after the current step)')
        }} />}
      </Box>
    )
  })
}
