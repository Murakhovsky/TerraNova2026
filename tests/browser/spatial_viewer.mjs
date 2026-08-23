import { chromium } from 'playwright-core';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import pngjs from 'pngjs';

const { PNG } = pngjs;

const url = process.argv[2];
if (!url) throw new Error('Usage: node tests/browser/spatial_viewer.mjs <scene-url>');

const outputDir = resolve('tmp/browser-spatial');
await mkdir(outputDir, { recursive: true });
const browser = await chromium.launch({
  executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  headless: true,
});

const results = [];
try {
  for (const profile of [
    { name: 'desktop', viewport: { width: 1440, height: 1000 }, mobile: false },
    { name: 'mobile', viewport: { width: 390, height: 844 }, mobile: true },
  ]) {
    const context = await browser.newContext({ viewport: profile.viewport, isMobile: profile.mobile });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(`page: ${error.message}`));
    page.on('console', (message) => {
      if (message.type() === 'error') errors.push(`console: ${message.text()}`);
    });
    page.on('response', (response) => {
      if (response.status() >= 400) errors.push(`http ${response.status()}: ${response.url()}`);
    });

    await page.goto(url, { waitUntil: 'networkidle' });
    await page.locator('[data-spatial-viewer].is-ready canvas').waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(600);
    const canvas = page.locator('[data-spatial-viewer] canvas');
    const box = await canvas.boundingBox();
    if (!box || box.width < 300 || box.height < 300) throw new Error(`${profile.name}: invalid canvas bounds`);

    const screenshot = PNG.sync.read(await canvas.screenshot());
    const colors = new Set();
    let visible = 0;
    const step = Math.max(4, Math.floor(Math.sqrt((screenshot.width * screenshot.height) / 12000)));
    for (let y = 0; y < screenshot.height; y += step) {
      for (let x = 0; x < screenshot.width; x += step) {
        const offset = (y * screenshot.width + x) * 4;
        if (screenshot.data[offset + 3] > 0) visible++;
        colors.add(`${screenshot.data[offset] >> 3},${screenshot.data[offset + 1] >> 3},${screenshot.data[offset + 2] >> 3}`);
      }
    }
    const pixels = { visible, colors: colors.size, width: screenshot.width, height: screenshot.height };
    if (pixels.visible < 100 || pixels.colors < 2) throw new Error(`${profile.name}: canvas pixel check failed: ${JSON.stringify(pixels)}`);

    await page.mouse.move(box.x + box.width * 0.55, box.y + box.height * 0.5);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width * 0.7, box.y + box.height * 0.58, { steps: 8 });
    await page.mouse.up();
    await page.waitForTimeout(250);
    await page.screenshot({ path: resolve(outputDir, `${profile.name}.png`), fullPage: true });
    results.push({ profile: profile.name, canvas: pixels, errors });
    await context.close();
  }
} finally {
  await browser.close();
}

const failures = results.flatMap((result) => result.errors);
if (failures.length) throw new Error(failures.join('\n'));
console.log(JSON.stringify(results, null, 2));
