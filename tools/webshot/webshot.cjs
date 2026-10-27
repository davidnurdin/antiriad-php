// Headless Chrome check of a browser build (bin/jumphp webshot): opens the page, waits for the game to start,
// clicks the "play" overlay, plays scripted keys, saves a screenshot, prints the console and fails on errors.
//   node webshot.cjs <url> <out.png> [seconds=8] [keys JSON: [[seconds, "ArrowDown"], ...]]
const puppeteer = require('puppeteer');
const [url, out, seconds = '8', keysJson = '[]'] = process.argv.slice(2);
(async () => {
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--use-gl=swiftshader', '--enable-unsafe-swiftshader', '--autoplay-policy=no-user-gesture-required'],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 960, height: 540 });
  const errors = [];
  page.on('console', (m) => { console.log(`[console.${m.type()}] ${m.text()}`); if (/CRASH|BOOT FAILED|Fatal error|Uncaught/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => { console.log('[pageerror]', e.message); errors.push(e.message); });
  await page.goto(url, { waitUntil: 'load' });
  const ratio = await page.evaluate(() => (window.JUMPHP_GAME && window.JUMPHP_GAME.width / window.JUMPHP_GAME.height) || 16 / 9);
  await page.setViewport({ width: 960, height: Math.round(960 / ratio) });
  await page.waitForFunction(() => document.getElementById('loader').style.display === 'none'
    || document.getElementById('loader').classList.contains('error'), { timeout: 60000 });
  const failed = await page.evaluate(() => document.getElementById('loader').classList.contains('error')
    ? document.getElementById('status').textContent : null);
  if (failed) errors.push('loader: ' + failed);
  await page.mouse.click(480, 100);           // the "click to play" overlay
  const keys = JSON.parse(keysJson);
  const t0 = Date.now();
  for (const [at, key] of keys) {
    const wait = at * 1000 - (Date.now() - t0);
    if (wait > 0) await new Promise((r) => setTimeout(r, wait));
    await page.keyboard.down(key); await new Promise((r) => setTimeout(r, 80)); await page.keyboard.up(key);
  }
  const rest = Number(seconds) * 1000 - (Date.now() - t0);
  if (rest > 0) await new Promise((r) => setTimeout(r, rest));
  await page.screenshot({ path: out });
  console.log('screenshot:', out);
  await browser.close();
  if (errors.length) { console.error('ERRORS:\n' + errors.join('\n')); process.exit(1); }
})().catch((e) => { console.error(e); process.exit(1); });
