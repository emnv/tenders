import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const targetUrl = 'https://www.ontario.ca/page/ontarios-highway-programs';
const timeoutMs = Number.parseInt(process.env.ONTARIO_TIMEOUT_MS ?? '60000', 10);
const outputPath = process.env.ONTARIO_DISCOVER_OUTPUT || path.resolve('storage/app/ontario-highway-discover.json');

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1400, height: 900 } });
const page = await context.newPage();
page.setDefaultTimeout(timeoutMs);

const matches = [];
page.on('response', async (response) => {
  try {
    const url = response.url();
    const contentType = response.headers()['content-type'] || '';
    if (
      /interactive-table|ontario-interactive-table|table|dataset|data/i.test(url) ||
      contentType.includes('application/json') ||
      contentType.includes('text/csv')
    ) {
      const status = response.status();
      const body = await response.text();
      if (body && body.length > 1000) {
        matches.push({ url, status, contentType, size: body.length, sample: body.slice(0, 2000) });
      }
    }
  } catch {
    // ignore
  }
});

try {
  await page.goto(targetUrl, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(15000);

  fs.mkdirSync(path.dirname(outputPath), { recursive: true });
  fs.writeFileSync(outputPath, JSON.stringify(matches, null, 2), 'utf8');
} finally {
  await browser.close();
}
