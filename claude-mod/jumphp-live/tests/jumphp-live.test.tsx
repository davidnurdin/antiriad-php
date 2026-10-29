import { expect, test } from 'claude-code/testing'

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
