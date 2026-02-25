import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline';
import { chromium } from 'playwright';

const targetUrl = 'https://bcbid.gov.bc.ca/page.aspx/en/rfp/request_browse_public';
const headless = process.env.BCBID_HEADLESS !== 'false';
const timeoutMs = Number.parseInt(process.env.BCBID_TIMEOUT_MS ?? '60000', 10);
const outputDir = process.env.BCBID_OUTPUT_DIR || path.resolve('storage/app/bc-bid-playwright');
const manifestPath = process.env.BCBID_MANIFEST || path.join(outputDir, 'manifest.json');
const maxPages = Number.parseInt(process.env.BCBID_PAGES ?? '1', 10);
const manual = process.env.BCBID_MANUAL === 'true';
const userDataDir = process.env.BCBID_USER_DATA_DIR || path.resolve('storage/app/bcbid-profile');
const userAgent = process.env.BCBID_USER_AGENT || 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';
const locale = process.env.BCBID_LOCALE || 'en-CA';
const timezoneId = process.env.BCBID_TIMEZONE || 'America/Vancouver';
const stealth = process.env.BCBID_STEALTH !== 'false';

const waitForEnter = () =>
  new Promise((resolve) => {
    const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
    rl.question('Complete the browser check in the opened window, then press Enter here to continue... ', () => {
      rl.close();
      resolve();
    });
  });

fs.mkdirSync(userDataDir, { recursive: true });

const context = await chromium.launchPersistentContext(userDataDir, {
  headless,
  viewport: { width: 1400, height: 900 },
  userAgent,
  locale,
  timezoneId,
  args: [
    '--disable-blink-features=AutomationControlled',
    '--no-sandbox',
    '--disable-dev-shm-usage',
  ],
});

const page = await context.newPage();
page.setDefaultTimeout(timeoutMs);

try {
  if (stealth) {
    await page.addInitScript(() => {
      Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
      Object.defineProperty(navigator, 'languages', { get: () => ['en-CA', 'en'] });
      Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });
      window.chrome = window.chrome || { runtime: {} };
    });
  }

  let loaded = false;
  for (let attempt = 0; attempt < 3; attempt += 1) {
    await page.goto(targetUrl, { waitUntil: 'networkidle' });

    await page.waitForFunction(
      () => !document.title.includes('Browser check'),
      null,
      { timeout: 60000 }
    ).catch(() => null);

    await page.waitForSelector('#body_x_grid_grd', { timeout: 20000 }).catch(() => null);

    const title = await page.title();
    if (!/Browser check/i.test(title)) {
      loaded = true;
      break;
    }

    await page.waitForTimeout(8000);
  }

  if (manual) {
    await waitForEnter();
  }

  if (!loaded) {
    await page.waitForSelector('#body_x_grid_grd', { timeout: 20000 }).catch(() => null);
  }

  fs.mkdirSync(outputDir, { recursive: true });

  const pages = [];
  const readFirstRowId = async () =>
    page.$eval('#body_x_grid_grd tbody tr[data-id]', (el) => el.getAttribute('data-id')).catch(() => null);

  const writePage = async (index) => {
    const html = await page.content();
    const pagePath = path.join(outputDir, `page-${index}.html`);
    fs.writeFileSync(pagePath, html, 'utf8');
    pages.push(pagePath);
  };

  await writePage(0);

  for (let pageIndex = 1; pageIndex < maxPages; pageIndex += 1) {
    const buttonSelector = `.pager button[data-page-index="${pageIndex}"]`;
    const button = await page.$(buttonSelector);
    if (!button) {
      break;
    }

    const beforeId = await readFirstRowId();
    await button.click();
    await page.waitForTimeout(1000);
    await page.waitForFunction(
      (prevId) => {
        const firstRow = document.querySelector('#body_x_grid_grd tbody tr[data-id]');
        return firstRow && firstRow.getAttribute('data-id') !== prevId;
      },
      beforeId,
      { timeout: 15000 }
    ).catch(() => null);

    await writePage(pageIndex);
  }

  fs.writeFileSync(manifestPath, JSON.stringify({ pages }, null, 2), 'utf8');
} finally {
  await context.close();
}
