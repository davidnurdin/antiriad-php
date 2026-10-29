import { expect, test } from 'claude-code/testing'
import { cellsOf, fromBase64, toBase64 } from '../hooks/register'

const PANE = {
  plugin: 'jumphp-live',
  component: 'Pane',
  requestId: 'jumphp-live',
  props: {
    title: 'JumPHP',
    isFocused: false,
    bodyColumns: 60,
    placement: 'dock',
    scroll: { offset: 0, bodyRows: 30 },
    view: {},
  },
} as const

const FAILED = [
  '==> live frames: /work/game/var/live/frame.png',
  '==> games/pong: php -l',
  '==> games/pong: phpstan',
  '==> games/pong: phpunit',
  'There was 1 failure:',
  '1) Tests\\ScoreTest::testBonus',
  'Failed asserting that 3 is identical to 4.',
  'Tests: 4, Assertions: 4, Failures: 1.',
].join('\n')

test("Claude's bin/jumphp test is followed, its checks marked, what failed shown", async ($, on) => {
  let ran = ''
  on('command.register', () => ({ value: { command: 'jumphp' } }))
  on('ui.open', () => ({ value: { isPlaced: true } }))
  on('session.start', (_, e) => ({ cwd: e.cwd }))
  on('process.spawn', async function* () {
    return { value: { code: 0, signal: null } }
  })
  on('process.run', () => ({ value: { exitCode: 0, stdout: '', stderr: '' } }))
  on('clock.now', () => ({ value: 1000 }))
  on('clock.sleep', () => ({ value: undefined }))
  on('ui.status', () => ({ value: undefined }))
  on('tool.call', (_, e) => {
    ran = e.tool === 'Bash' ? e.command : ran
    return { result: { stdout: FAILED, stderr: '', interrupted: false }, isError: true }
  })

  await $.session.start({ cwd: '/work/game', surface: 'terminal', isInteractive: true })
  await $.tool.call({ tool: 'Bash', command: 'bin/jumphp test pong' })
  // the command keeps its exit status and its output goes to the log the pane follows
  expect(ran).toContain('bin/jumphp test pong')
  expect(ran).toContain('| tee /tmp/jumphp-live-')
  expect(ran).toContain('PIPESTATUS[0]')
  // the engine writes the game's screen as it runs
  expect(ran).toContain('export JUMPHP_LIVE=1')

  for (const surface of ['terminal', 'desktop'] as const) {
    const ui = await $.ui.mount({ ...PANE, surface })
    expect((await ui.find({ type: 'Text', text: /pong/ }))?.text).toContain('pong')
    expect((await ui.find({ type: 'Text', text: /✔ php -l/ }))?.text).toBe('✔ php -l   ✔ phpstan   ✘ phpunit')
    expect((await ui.find({ type: 'Text', text: /testBonus/ }))?.text).toBe('✘ Tests\\ScoreTest::testBonus')
    expect((await ui.find({ type: 'Text', text: /^Tests: 4/ }))?.text).toBe('Tests: 4, Assertions: 4, Failures: 1.')
    // the run's last frame stays shown once it is over
    expect((await ui.find({ type: 'Text', text: /frame\.png$/ }))?.text).toBe('/work/game/var/live/frame.png')
    await ui.unmount()
  }
})

test('other commands are left alone', async ($, on) => {
  let ran = ''
  on('tool.call', (_, e) => {
    ran = e.tool === 'Bash' ? e.command : ran
    return { result: { stdout: '', stderr: '', interrupted: false } }
  })
  await $.tool.call({ tool: 'Bash', command: 'git status' })
  expect(ran).toBe('git status')
})

const PLAYED = [
  '==> live frames: /work/JumPHP/var/live/frame.png',
  '==> games/pong: php -l',
  '==> games/pong: phpunit',
  '[jumphp-test] start Tests\\PaddleTest::testThePaddleGoesFromTheTopToTheBottom',
  '[jumphp-test] frame Tests\\PaddleTest::testThePaddleGoesFromTheTopToTheBottom tests/Tests.PaddleTest-testThePaddleGoesFromTheTopToTheBottom.png',
  '.[jumphp-test] start Tests\\PaddleTest::testTheBallIsServed',
  '[jumphp-test] frame Tests\\PaddleTest::testTheBallIsServed tests/Tests.PaddleTest-testTheBallIsServed.png',
  'Paddle (Tests\\Paddle)',
  ' ✔ The paddle goes from the top to the bottom',
  ' ✘ The ball is served',
  'Tests: 2, Assertions: 3, Failures: 1.',
].join('\n')

test('the game tests are listed with their marks, a press shows a test\'s last frame', async ($, on) => {
  on('command.register', () => ({ value: { command: 'jumphp' } }))
  on('ui.open', () => ({ value: { isPlaced: true } }))
  on('process.spawn', async function* () {
    return { value: { code: 0, signal: null } }
  })
  on('process.run', () => ({ value: { exitCode: 0, stdout: '', stderr: '' } }))
  on('clock.now', () => ({ value: 1000 }))
  on('clock.sleep', () => ({ value: undefined }))
  on('ui.status', () => ({ value: undefined }))
  on('tool.call', () => ({ result: { stdout: PLAYED, stderr: '', interrupted: false }, isError: true }))

  await $.tool.call({ tool: 'Bash', command: 'bin/jumphp test pong' })

  for (const surface of ['terminal', 'desktop'] as const) {
    const ui = await $.ui.mount({ ...PANE, surface })
    expect((await ui.find({ type: 'Button', key: 'gt-0' }))?.text).toBe('✔ The paddle goes from the top to the bottom')
    expect((await ui.find({ type: 'Button', key: 'gt-1' }))?.text).toBe('✘ The ball is served')
    await ui.press({ key: 'gt-0' })
    expect((await ui.find({ type: 'Text', text: /^last frame of/ }))?.text).toBe('last frame of: The paddle goes from the top to the bottom')
    await ui.press({ key: 'gt-0' })
    await ui.unmount()
  }
})

test('a picture drawn as half blocks: the top pixel in front, the bottom one behind', async () => {
  // 2×2: red, green over blue, white
  const rgb = new Uint8Array([74, 82, 71, 66, 2, 0, 2, 0, 255, 0, 0, 0, 255, 0, 0, 0, 255, 255, 255, 255])
  expect(fromBase64(toBase64(rgb))).toEqual(rgb)
  const drawn = cellsOf(rgb, 2)
  expect(drawn?.rows).toBe(1)
  const words = new Uint32Array(fromBase64(drawn!.cells).buffer)
  expect(Array.from(words)).toEqual([0x2580, 0xff0000, 0x0000ff, 0x2580, 0x00ff00, 0xffffff])
  expect(cellsOf(new Uint8Array([1, 2, 3]), 2)).toBe(null)
})
