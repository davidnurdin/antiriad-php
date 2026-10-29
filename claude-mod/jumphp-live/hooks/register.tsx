// jumphp-live: a JumPHP pane beside the chat while Claude makes a game.
//  - Claude's `bin/jumphp test|shot|siteshot …` commands: their output copied to a log (tee) the pane follows
//    live, each check (php -l, phpstan, phpunit, smoke test) marked ✔ / ✘, what failed.
//  - The game's screen while it runs (JUMPHP_LIVE: the engine rewrites var/live/frame.png, the pane swaps it in),
//    the pictures `bin/jumphp shot` / `siteshot` write and those Claude reads (Read of a .png).
//  - The playground (MCP jumphp): its PHPUnit runs, its publications.
//  - The game tests (JumPHP\Testing\GameTestCase: a test plays the game): each one as it runs, its last frame.
//  - Pictures drawn as coloured half blocks in any terminal (the engine's small .rgb beside each PNG), or as images
//    in kitty / Ghostty / iTerm2; 🖼 opens the picture on the desktop.
//  - Buttons: run the tests or take a screenshot now (here, live), at the game's speed or fast, ask Claude to fix;
//    a field to tell Claude.
import { atom, read, update } from 'claude-code'
import type { EngineInterface as Engine, Register } from 'claude-code'

import type { Check, GameTest, Remote, Shot, Stage, View, Where } from '../types'

const PANE = 'jumphp-live'
const TITLE = 'JumPHP'
const MAX_LOG = 12_000

const check = atom({ plugin: 'jumphp-live', key: 'check' } as const, null as Check | null, { shape: 'check-3' })
const shots = atom({ plugin: 'jumphp-live', key: 'shots' } as const, [] as Shot[], { shape: 'shots-1' })
const remote = atom({ plugin: 'jumphp-live', key: 'remote' } as const, null as Remote | null, { shape: 'remote-1' })
const where = atom({ plugin: 'jumphp-live', key: 'where' } as const, { root: '', game: '' } as Where, { shape: 'where-1' })
const view = atom({ plugin: 'jumphp-live', key: 'view' } as const, { selected: '', isRealtime: true, pixels: 'blocks' } as View, { shape: 'view-2' })

/** a bin/jumphp command that checks or pictures a game: its kind and game */
const JUMPHP = /(?:^|[\s;&|(])(?:\.\/)?bin\/jumphp\s+(test|shot|siteshot|webshot)\b[ \t]*([\w.-]*)/
const PICTURE = /\.(png|jpe?g|webp)$/i
const ANSI = /\x1b\[[0-9;]*[A-Za-z]/g
const LIVE = /live frames: (\/\S+\.png)/
/** how often the live picture is swapped while a run goes on */
const LIVE_MS = 200

const clip = (text: string): string => (text.length > MAX_LOG ? text.slice(-MAX_LOG) : text)

/** the checks bin/jumphp announced (`==> games/pong: phpstan`), the last one running, or failed, or done */
export function stagesOf(log: string, status: Check['status']): Stage[] {
  const names = [...log.matchAll(/==> (?:[^\s:]+: )?([^\n]+)/g)]
    .map(m => (m[1] ?? '').trim().replace(/ \(.*$/, ''))
    .filter(name => !/all checks passed|^live frames/.test(name))
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

/** PHPUnit's testdox name of a test method: testThePaddleGoesDown → The paddle goes down */
export function labelOf(name: string): string {
  const words = (name.split('::')[1] ?? name).replace(/^test_?/, '').replace(/_/g, ' ')
    .replace(/([a-z0-9])([A-Z])/g, '$1 $2').trim().toLowerCase()
  return words.charAt(0).toUpperCase() + words.slice(1)
}

/**
 * The game tests of a run: `[jumphp-test] start <test>` / `[jumphp-test] frame <test> <file>` (JumPHP's Game,
 * the file relative to the live picture's folder; PHPUnit's progress dots may lead the line), then PHPUnit's
 * testdox marks (✔ / ✘ <label>).
 */
export function testsOf(log: string, live: string): GameTest[] {
  const dir = live.replace(/\/[^/]+$/, '')
  const tests = new Map<string, GameTest>()
  for (const m of log.matchAll(/\[jumphp-test\] (start|frame) (\S+)(?: (\S+))?$/gm)) {
    const name = m[2] ?? ''
    const known = tests.get(name)
    if (m[1] === 'start') {
      tests.set(name, { name, label: labelOf(name), status: 'run', frame: '' })
    } else if (known) {
      const file = m[3] ?? ''
      tests.set(name, { ...known, status: 'done', frame: file.startsWith('/') ? file : dir !== '' && file !== '' ? `${dir}/${file}` : '' })
    }
  }
  const marks = new Map<string, GameTest['status']>()
  for (const m of log.matchAll(/^\s*([✔✘])\s+(.+?)\s*$/gm)) {
    marks.set((m[2] ?? '').toLowerCase(), m[1] === '✔' ? 'ok' : 'fail')
  }
  return [...tests.values()].map(t => ({ ...t, status: marks.get(t.label.toLowerCase()) ?? t.status }))
}

const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'

export function fromBase64(text: string): Uint8Array {
  const clean = text.replace(/[^A-Za-z0-9+/]/g, '')
  const bytes = new Uint8Array(Math.floor(clean.length * 3 / 4))
  let bits = 0
  let value = 0
  let at = 0
  for (const char of clean) {
    value = (value << 6) | B64.indexOf(char)
    bits += 6
    if (bits >= 8) {
      bits -= 8
      bytes[at++] = (value >> bits) & 0xff
    }
  }
  return bytes.subarray(0, at)
}

export function toBase64(bytes: Uint8Array): string {
  let out = ''
  for (let i = 0; i < bytes.length; i += 3) {
    const n = ((bytes[i] ?? 0) << 16) | ((bytes[i + 1] ?? 0) << 8) | (bytes[i + 2] ?? 0)
    out += B64[(n >> 18) & 63]! + B64[(n >> 12) & 63]!
      + (i + 1 < bytes.length ? B64[(n >> 6) & 63]! : '=') + (i + 2 < bytes.length ? B64[n & 63]! : '=')
  }
  return out
}

/**
 * A picture as Raster cells: the engine's raw copy (`.rgb`: "JRGB", width, height as u16 LE, RGB bytes) drawn as
 * upper half blocks, the top pixel the foreground and the bottom one the background: two pixels a cell.
 */
export function cellsOf(rgb: Uint8Array, columns: number): { cells: string; rows: number } | null {
  if (rgb.length < 8 || String.fromCharCode(rgb[0]!, rgb[1]!, rgb[2]!, rgb[3]!) !== 'JRGB') {
    return null
  }
  const width = rgb[4]! | (rgb[5]! << 8)
  const height = rgb[6]! | (rgb[7]! << 8)
  if (width < 1 || height < 1 || rgb.length < 8 + width * height * 3) {
    return null
  }
  const rows = Math.max(1, Math.round(columns * height / width / 2))
  const pixel = (x: number, y: number): number => {
    const at = 8 + (Math.min(height - 1, y) * width + Math.min(width - 1, x)) * 3
    return (rgb[at]! << 16) | (rgb[at + 1]! << 8) | rgb[at + 2]!
  }
  const words = new Uint32Array(columns * rows * 3)
  for (let row = 0; row < rows; row++) {
    for (let column = 0; column < columns; column++) {
      const x = Math.floor(column * width / columns)
      const at = (row * columns + column) * 3
      words[at] = 0x2580
      words[at + 1] = pixel(x, Math.floor(row * 2 * height / (rows * 2)))
      words[at + 2] = pixel(x, Math.floor((row * 2 + 1) * height / (rows * 2)))
    }
  }
  return { cells: toBase64(new Uint8Array(words.buffer)), rows }
}

/** absolute paths of pictures in an output (bin/jumphp shot prints the file it wrote) */
export function picturesIn(log: string): string[] {
  return [...new Set([...log.matchAll(/(\/[^\s'"]+\.(?:png|jpe?g|webp))\b/gi)].map(m => m[1] ?? ''))]
}

let seen = 0
/** the live picture's box as last drawn (a swap keeps the size), and whether a loop swaps it */
let liveBox = { columns: 0, rows: 0 }
let liveGeneration = 0
let isWatching = false

/** a picture's blocks (its .rgb beside the PNG, written by JumPHP), or null when it has none */
async function blocksOf($: Engine, png: string, columns: number): Promise<{ cells: string; rows: number } | null> {
  try {
    const bytes = await $.fs.read(png.replace(/\.png$/i, '.rgb'), { as: 'bytes' })
    return cellsOf(fromBase64(bytes.base64), columns)
  } catch {
    return null
  }
}

/** a picture opened on the desktop (the system's viewer) */
async function openOnDesktop($: Engine, file: string): Promise<void> {
  for (const opener of ['xdg-open', 'open']) {
    try {
      await $.process.run([opener, file], { timeoutMs: 5000 })
      $.ui.toast(`Opened ${file}`)
      return
    } catch {
      // the next one
    }
  }
  $.ui.toast(`No viewer to open ${file}`)
}

async function begin($: Engine, kind: string, game: string, by: Check['by']): Promise<void> {
  const at = await $.clock.now()
  await update($, check, (): Check => ({ game, kind, by, status: 'running', stages: [], log: '', summary: '', failures: [], live: '', tests: [], at }))
  if (game) {
    await update($, where, w => ({ ...w, game }))
  }
  void $.ui.open({ id: PANE, title: TITLE })
  void watchLive($)
}

function progress($: Engine, log: string): Promise<unknown> {
  return update($, check, c => {
    if (!c) {
      return c
    }
    const live = c.live || (LIVE.exec(log)?.[1] ?? '')
    return { ...c, log: clip(log), stages: stagesOf(log, 'running'), live, tests: testsOf(log, live) }
  })
}

/** while a run goes on, the live picture swapped in the pane a few times a second (no redraw) */
async function watchLive($: Engine): Promise<void> {
  if (isWatching) {
    return
  }
  isWatching = true
  try {
    for (;;) {
      const c = await read($, check)
      if (!c || c.status !== 'running') {
        break
      }
      if (c.live !== '' && liveBox.columns > 0) {
        liveGeneration += 1
        if ((await read($, view)).pixels === 'image') {
          await $.ui.blit({ requestId: PANE, key: 'live', source: { file: c.live, format: 'png', generation: liveGeneration }, ...liveBox }).catch(() => undefined)
        } else {
          const blocks = await blocksOf($, c.live, liveBox.columns)
          if (blocks && blocks.rows === liveBox.rows) {
            await $.ui.blit({ requestId: PANE, key: 'live', cells: blocks.cells, ...liveBox }).catch(() => undefined)
          }
        }
      }
      await $.clock.sleep(LIVE_MS)
    }
  } finally {
    isWatching = false
  }
}

async function finish($: Engine, log: string, isOk: boolean): Promise<void> {
  const text = log.replace(ANSI, '')
  await update($, check, (c): Check | null => {
    if (!c) {
      return c
    }
    // a test passes when bin/jumphp says so; a picture when the command ended well
    const status: Check['status'] = isOk && (c.kind !== 'test' || text.includes('all checks passed')) ? 'passed' : 'failed'
    // a test still running when the run ended did not end well (a crash, a timeout)
    const live = c.live || (LIVE.exec(text)?.[1] ?? '')
    const tests = testsOf(text, live).map((t): GameTest => (t.status === 'run' ? { ...t, status: 'fail' } : t))
    return { ...c, status, log: clip(text), stages: stagesOf(text, status), live, tests, ...resultOf(text) }
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
  const { isRealtime } = await read($, view)
  let log = ''
  let code = 1
  try {
    const run = $.process.spawn({ argv: ['bin/jumphp', kind, game], cwd: root || undefined, env: { JUMPHP_LIVE: '1', ...(isRealtime ? { JUMPHP_REALTIME: '1' } : {}) } })
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
    // pictures as images where the terminal draws them (kitty, Ghostty, iTerm2), else as coloured blocks
    const term = `${(await $.env.get('TERM')) ?? ''} ${(await $.env.get('TERM_PROGRAM')) ?? ''}`
    const isImageTerminal = /kitty|ghostty|iterm/i.test(term) || (await $.env.get('KITTY_WINDOW_ID')) !== undefined
    await update($, view, v => ({ ...v, pixels: isImageTerminal ? 'image' as const : 'blocks' as const }))
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
    const env = (await read($, view)).isRealtime ? 'export JUMPHP_LIVE=1 JUMPHP_REALTIME=1' : 'export JUMPHP_LIVE=1'
    const ran = await next({ ...e, command: `{ ${env}\n${e.command}\n} 2>&1 | tee ${file}; ( exit \${PIPESTATUS[0]} )` })
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
    const { selected, isRealtime, pixels } = await read($, view)
    const width = Math.max(20, e.props.bodyColumns ?? 60)
    const color = (s: string) => (s === 'passed' || s === 'ok' ? 'success' : s === 'failed' || s === 'fail' || s === 'error' ? 'error' : 'warning')
    const mark = (s: Stage['status']) => (s === 'ok' ? '✔' : s === 'fail' ? '✘' : '…')
    const logLines = c ? c.log.split('\n').filter(l => l.trim() !== '').slice(c.status === 'running' ? -8 : -4) : []
    const shot = pictures[0]
    const isLive = c !== null && c.status === 'running' && c.live !== ''
    const tests = c?.tests ?? []
    const running = tests.findLast(t => t.status === 'run')
    const chosen = tests.find(t => t.name === selected && t.frame !== '') ?? null
    const testMark = (t: GameTest) => (t.status === 'ok' ? '✔' : t.status === 'fail' ? '✘' : t.status === 'run' ? '⏳' : '·')
    const Image = e.surface === 'terminal' && pixels === 'image' && 'Image' in ui ? ui.Image : null
    const Raster = e.surface === 'terminal' && pixels === 'blocks' && 'Raster' in ui ? ui.Raster : null
    const imageColumns = Math.min(width, 72)
    // the picture shown: the game live, a test's last frame, the last screenshot
    const picture = isLive ? c.live : chosen ? chosen.frame : shot?.path ?? ''
    const blocks = Raster && picture !== '' ? await blocksOf($, picture, imageColumns) : null
    const imageRows = blocks?.rows ?? Math.max(4, Math.round(imageColumns * 9 / 32))
    liveBox = { columns: imageColumns, rows: imageRows }
    const drawn = (key: string, generation: number) => (
      blocks && Raster ? <Raster key={key} columns={imageColumns} rows={blocks.rows} cells={blocks.cells} />
        : Image ? <Image key={key} source={{ file: picture, format: 'png', generation }} columns={imageColumns} rows={imageRows} alt={picture} />
          : null
    )

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
        {tests.length > 0 && <Text bold>Game tests</Text>}
        {tests.map((t, i) => (
          <Button key={`gt-${i}`} label={`${testMark(t)} ${t.label}${t.name === chosen?.name ? '  ◂' : ''}`}
            onPress={() => update($, view, v => ({ ...v, selected: v.selected === t.name ? '' : t.name }))} />
        ))}
        {r && (
          <Text>
            <Text dimColor>playground {r.tool}: </Text>
            <Text color={color(r.status)}>{r.status}</Text>
            {r.detail !== '' && <Text dimColor> · {r.detail}</Text>}
          </Text>
        )}
        {isLive && drawn('live', liveGeneration)}
        {isLive && <Text><Text color="error" bold>● live</Text><Text dimColor> {running ? running.label : c.stages.at(-1)?.name ?? ''}</Text></Text>}
        {!isLive && chosen && drawn('test', c?.at ?? 0)}
        {!isLive && chosen && <Text dimColor>last frame of: {chosen.label}</Text>}
        {!isLive && !chosen && shot && drawn('shot', shot.n)}
        {!isLive && !chosen && shot && <Text dimColor wrap="truncate-start">{shot.path}</Text>}
        <Box flexDirection="row" gap={1}>
          <Button key="test" label="▶ Tests" hotkey="t" onPress={() => void runHere($, 'test')} />
          <Button key="shot" label="📸 Shot" hotkey="s" onPress={() => void runHere($, 'shot')} />
          {picture !== '' && <Button key="open" label="🖼 Open" hotkey="o" onPress={() => void openOnDesktop($, picture)} />}
          <Button key="pixels" label={pixels === 'image' ? '🖼 HD' : '▦ Blocks'} hotkey="p" onPress={() => update($, view, v => ({ ...v, pixels: v.pixels === 'image' ? 'blocks' as const : 'image' as const }))} />
          <Button key="speed" label={isRealtime ? '🐢 Game speed' : '⏩ Fast'} hotkey="r" onPress={() => update($, view, v => ({ ...v, isRealtime: !v.isRealtime }))} />
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
