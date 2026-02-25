import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const targetUrl = 'https://bcbid.gov.bc.ca/page.aspx/en/rfp/request_browse_public';
const headless = process.env.BCBID_HEADLESS !== 'false';
const timeoutMs = Number.parseInt(process.env.BCBID_TIMEOUT_MS ?? '60000', 10);
const cookieFile = process.env.BCBID_COOKIE_FILE || 'storage/app/bc-bid-cookie.txt';
const credentialsFile = process.env.BCBID_CREDENTIALS_FILE || 'storage/app/bc-bid-credentials.json';
const userDataDir = process.env.BCBID_USER_DATA_DIR || 'storage/app/bcbid-profile';
const userAgent = process.env.BCBID_USER_AGENT || 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';
const locale = process.env.BCBID_LOCALE || 'en-CA';
const timezoneId = process.env.BCBID_TIMEZONE || 'America/Vancouver';
const stealth = process.env.BCBID_STEALTH !== 'false';

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

const readCsrfToken = async () => {
  const fromInput = await page.$eval('input[name="CSRFToken"]', (el) => el.value).catch(() => null);
  if (fromInput && String(fromInput).trim() !== '') {
    return String(fromInput).trim();
  }

  const fromGlobal = await page.evaluate(() => {
    if (typeof window !== 'undefined' && typeof window.CSRFToken === 'string') {
      return window.CSRFToken;
    }
    return null;
  }).catch(() => null);

  if (fromGlobal && String(fromGlobal).trim() !== '') {
    return String(fromGlobal).trim();
  }

  return null;
};

try {
  if (stealth) {
    await page.addInitScript(() => {
      Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
      Object.defineProperty(navigator, 'languages', { get: () => ['en-CA', 'en'] });
      Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });
      window.chrome = window.chrome || { runtime: {} };
    });
  }

  await page.goto(targetUrl, { waitUntil: 'networkidle' });
  await page.waitForSelector('#body_x_grid_grd', { timeout: 20000 }).catch(() => null);

  const cookies = await context.cookies();
  const bcCookies = cookies.filter((c) => c.domain.includes('bcbid.gov.bc.ca'));
  const cookieHeader = bcCookies.map((c) => `${c.name}=${c.value}`).join('; ');
  const sessionCookie = bcCookies.find((c) => c.name === 'ASP.NET_SessionId');
  const csrfToken = await readCsrfToken();

  if (!sessionCookie?.value || !csrfToken) {
    console.error('Unable to extract ASP.NET_SessionId or CSRFToken. Run with BCBID_HEADLESS=false and complete browser checks first.');
    process.exitCode = 1;
  } else {
    const output = {
      session_id: sessionCookie.value,
      csrf_token: csrfToken,
      cookie_header: cookieHeader,
      user_agent: await page.evaluate(() => navigator.userAgent),
      generated_at: new Date().toISOString(),
    };

    fs.mkdirSync(path.dirname(cookieFile), { recursive: true });
    fs.writeFileSync(cookieFile, cookieHeader, 'utf8');

    fs.mkdirSync(path.dirname(credentialsFile), { recursive: true });
    fs.writeFileSync(credentialsFile, JSON.stringify(output, null, 2), 'utf8');

    console.log(`BCBID_SESSION_ID="${output.session_id}"`);
    console.log(`BCBID_CSRF_TOKEN="${output.csrf_token}"`);
    console.log(`BC_BID_USER_AGENT="${output.user_agent}"`);
    console.log(`Credentials written to: ${credentialsFile}`);
  }
} finally {
  await context.close();
}
