import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const targetUrl = 'https://www.ontario.ca/page/ontarios-highway-programs';
const timeoutMs = Number.parseInt(process.env.ONTARIO_TIMEOUT_MS ?? '60000', 10);
const outputPath = process.env.ONTARIO_OUTPUT || path.resolve('storage/app/ontario-highway-programs.html');

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  viewport: { width: 1400, height: 900 },
});

const page = await context.newPage();
page.setDefaultTimeout(timeoutMs);

try {
  await page.goto(targetUrl, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('div[role="treegrid"]', { timeout: 20000 }).catch(() => null);
  await page.waitForSelector('div.ag-center-cols-container div[role="row"]', { timeout: 20000 }).catch(() => null);

  const html = await page.content();
  fs.mkdirSync(path.dirname(outputPath), { recursive: true });
  fs.writeFileSync(outputPath, html, 'utf8');
} finally {
  await browser.close();
}
