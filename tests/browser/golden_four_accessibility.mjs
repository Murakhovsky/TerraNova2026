import { chromium } from 'playwright-core';
import AxeBuilder from '@axe-core/playwright';
import { access, mkdir, writeFile } from 'node:fs/promises';
import { constants as fsConstants } from 'node:fs';
import { resolve } from 'node:path';

const baseUrl = process.argv[2]
  || process.env.GOLDEN_FOUR_BASE_URL
  || process.env.SALES_E2E_BASE_URL
  || '';
const storageState = process.argv[3]
  || process.env.GOLDEN_FOUR_STORAGE_STATE
  || process.env.SALES_E2E_STORAGE_STATE
  || '';

if (!baseUrl) throw new Error('Golden Four accessibility requires an authenticated base URL.');
if (!storageState) throw new Error('Golden Four accessibility requires a Playwright storage state.');

const candidates = [
  process.env.BROWSER_EXECUTABLE,
  process.env.CHROME_PATH,
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
].filter(Boolean);

let executablePath = null;
for (const candidate of candidates) {
  try {
    await access(candidate, fsConstants.X_OK);
    executablePath = candidate;
    break;
  } catch {}
}
if (!executablePath) throw new Error('No Chrome/Chromium executable found for Golden Four accessibility.');

const outputDir = resolve(process.env.GOLDEN_FOUR_A11Y_OUTPUT_DIR || 'tmp/golden-four-accessibility');
await mkdir(outputDir, { recursive: true });

const profiles = [
  { name: 'desktop', viewport: { width: 1440, height: 1000 }, isMobile: false },
  { name: 'mobile', viewport: { width: 390, height: 844 }, isMobile: true },
];
const wcagTags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const absolute = (path) => new URL(path, baseUrl).toString();

const browser = await chromium.launch({ headless: true, executablePath });
const failures = [];

try {
  const discoveryContext = await browser.newContext({
    viewport: profiles[0].viewport,
    storageState,
  });
  const discoveryPage = await discoveryContext.newPage();
  const pipelineResponse = await discoveryPage.goto(absolute('/sales/pipeline'), { waitUntil: 'networkidle' });
  if (!pipelineResponse || pipelineResponse.status() >= 400) {
    throw new Error(`Deal discovery returned ${pipelineResponse?.status() ?? 'no response'}`);
  }
  const firstDeal = discoveryPage.locator('[data-sales-deal-card]').first();
  if (!await firstDeal.count()) {
    throw new Error('Golden Four fixture requires at least one Sales Deal card.');
  }
  const dealHref = await firstDeal.getAttribute('href');
  const dealId = await firstDeal.getAttribute('data-deal-id');
  const dealPath = dealHref || (dealId ? `/sales/deals/${dealId}` : '');
  if (!dealPath) {
    throw new Error('Golden Four Deal fixture must expose href or data-deal-id.');
  }
  await discoveryContext.close();

  const targets = [
    { name: 'executive-dashboard', path: '/admin', archetype: 'executive_dashboard' },
    { name: 'sales-dashboard', path: '/sales/dashboard', archetype: 'domain_dashboard' },
    { name: 'sales-leads', path: '/sales/leads', archetype: 'collection' },
    { name: 'sales-deal', path: dealPath, archetype: 'entity_workspace' },
  ];

  for (const profile of profiles) {
    const context = await browser.newContext({
      viewport: profile.viewport,
      isMobile: profile.isMobile,
      reducedMotion: 'reduce',
      colorScheme: 'light',
      storageState,
    });

    for (const target of targets) {
      const page = await context.newPage();
      const response = await page.goto(absolute(target.path), { waitUntil: 'networkidle' });
      if (!response || response.status() >= 400) {
        throw new Error(`${profile.name}/${target.name}: HTTP ${response?.status() ?? 'no response'}`);
      }

      const marker = page.locator(`[data-cos-archetype="${target.archetype}"]`);
      if (!await marker.count()) {
        throw new Error(`${profile.name}/${target.name}: canonical archetype marker is missing`);
      }

      const overflow = await page.evaluate(() => (
        document.documentElement.scrollWidth - document.documentElement.clientWidth
      ));
      if (overflow > 1) {
        failures.push(`${profile.name}/${target.name}: horizontal overflow ${overflow}px`);
      }

      const result = await new AxeBuilder({ page }).withTags(wcagTags).analyze();
      const evidencePath = resolve(outputDir, `${profile.name}-${target.name}.json`);
      await writeFile(evidencePath, JSON.stringify(result, null, 2));

      if (result.violations.length) {
        const summary = result.violations.map((violation) => {
          const nodes = violation.nodes
            .flatMap((node) => node.target)
            .slice(0, 4)
            .join(', ');
          return `${violation.id} [${violation.impact || 'unknown'}] ${nodes}`;
        });
        failures.push(`${profile.name}/${target.name}:\n- ${summary.join('\n- ')}`);
      }

      await page.close();
    }

    await context.close();
  }
} finally {
  await browser.close();
}

if (failures.length) {
  throw new Error(`Golden Four accessibility/responsive violations:\n${failures.join('\n')}`);
}

console.log(JSON.stringify({
  ok: true,
  suite: 'Wave 13 Phase 2.5 Golden Four accessibility',
  profiles: profiles.map((profile) => profile.name),
  archetypes: ['executive_dashboard', 'domain_dashboard', 'collection', 'entity_workspace'],
  tags: wcagTags,
}, null, 2));
