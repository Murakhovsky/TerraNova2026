import { chromium } from 'playwright-core';

const url = process.argv[2] ?? 'http://127.0.0.1/';
const browser = await chromium.launch({
  executablePath: process.env.BROWSER_EXECUTABLE ?? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  headless: true,
});

try {
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const errors = [];
  page.on('pageerror', (error) => errors.push(`page: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(`console: ${message.text()}`);
  });
  page.on('response', (response) => {
    if (response.status() >= 400) errors.push(`http ${response.status()}: ${response.url()}`);
  });

  const response = await page.goto(url, { waitUntil: 'networkidle' });
  if (!response?.ok()) throw new Error(`Page returned HTTP ${response?.status() ?? 'unknown'}.`);

  const assets = await page.locator('link[href^="/build/"], script[src^="/build/"]').evaluateAll(
    (elements) => elements.map((element) => element.getAttribute('href') ?? element.getAttribute('src')),
  );
  if (assets.length < 4 || assets.some((asset) => !/\/build\/assets\/.+-.+\.(css|js)$/.test(asset ?? ''))) {
    throw new Error(`Unexpected manifest assets: ${JSON.stringify(assets)}`);
  }
  if (await page.locator('link[href^="/css/"], script[src^="/js/"]').count()) {
    throw new Error('Legacy public CSS/JS URL remains in the rendered page.');
  }
  if (errors.length) throw new Error(errors.join('\n'));

  console.log(`Frontend browser assets passed: ${assets.length} hashed assets, no runtime errors.`);
} finally {
  await browser.close();
}
