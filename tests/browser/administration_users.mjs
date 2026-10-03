import { chromium } from 'playwright-core';

const baseUrl = process.env.ADMIN_E2E_BASE_URL || 'http://127.0.0.1:8081';
const email = process.env.ADMIN_E2E_EMAIL || '';
const password = process.env.ADMIN_E2E_PASSWORD || '';
const executablePath = process.env.CHROME_PATH || '/usr/bin/google-chrome';
if (!email || !password) throw new Error('ADMIN_E2E credentials required.');

const browser = await chromium.launch({ headless: true, executablePath });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
const absolute = (path) => new URL(path, baseUrl).toString();

try {
  await page.goto(absolute('/auth/login'), { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.startsWith('/auth/login'), { timeout: 25000 }),
    page.locator('button[type="submit"]').click(),
  ]);

  let response = await page.goto(absolute('/admin/users'), { waitUntil: 'networkidle' });
  if (!response || response.status() !== 200) throw new Error('/admin/users must return 200.');
  if (await page.getByText('foreign-tenant-user@example.test', { exact: true }).count()) {
    throw new Error('Tenant isolation failure: foreign tenant user is visible.');
  }

  const createdEmail = 'ui-created-user@example.test';
  const create = page.locator('form[action="/admin/createUser"]');
  await create.locator('input[name="full_name"]').fill('UI Created User');
  await create.locator('input[name="email"]').fill(createdEmail);
  await create.locator('input[name="password"]').fill('Ui-Created-Ab9!');
  await create.locator('select[name="role"]').selectOption('manager');
  const createResponsePromise = page.waitForResponse((response) => response.url().includes('/admin/createUser') && response.request().method() === 'POST');
  await create.locator('button[type="submit"]').click();
  const createResponse = await createResponsePromise;
  if (createResponse.status() >= 400) throw new Error('Create user returned HTTP ' + createResponse.status() + ': ' + (await createResponse.text()).slice(0, 2000));
  await page.waitForLoadState('networkidle');
  if (!await page.getByText(createdEmail, { exact: true }).count()) {
    const body = (await page.locator('body').innerText()).slice(0, 5000);
    const statusMessage = new URL(page.url()).searchParams.get('status_message') || '';
    throw new Error('Created tenant user is not visible after create. Status: ' + statusMessage + '. Page: ' + body);
  }

  const card = page.getByText(createdEmail, { exact: true }).locator('xpath=ancestor::article[1]');
  const update = card.locator('form[action^="/admin/updateUser/"]');
  await update.locator('input[name="full_name"]').fill('UI Updated User');
  await Promise.all([
    page.waitForURL((url) => url.pathname === '/admin/users', { timeout: 25000 }),
    update.locator('button[type="submit"]').click(),
  ]);
  if (!await page.getByText('UI Updated User', { exact: true }).count()) throw new Error('Updated user name did not persist.');

  console.log('Administration users functional acceptance passed.');
} finally {
  await context.close();
  await browser.close();
}
