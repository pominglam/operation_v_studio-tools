/* eslint-disable no-console */
const fs = require('fs');
const path = require('path');
const envPath = path.resolve(__dirname, '..', '..', '.env');
if (fs.existsSync(envPath)) {
  for (const line of fs.readFileSync(envPath, 'utf8').split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#') || !trimmed.includes('=')) continue;
    const idx = trimmed.indexOf('=');
    process.env[trimmed.slice(0, idx).trim()] = trimmed.slice(idx + 1).trim().replace(/^['"]|['"]$/g, '');
  }
}
const { chromium } = require('playwright');
const { ensureLoggedInQuick } = require('./src/plamod');
const { scrapeRetailerCartQuantities } = require('./src/plamod-restock-cart');

async function main() {
  const base = 'https://plamod.com';
  const context = await chromium.launchPersistentContext(path.resolve(__dirname, '.pw-user-data'), {
    headless: true,
    viewport: { width: 1400, height: 900 },
  });
  const page = context.pages()[0] || (await context.newPage());
  await ensureLoggedInQuick(page, base, context);
  await page.goto(`${base}/retailer/cart`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);

  const refresh = page.getByRole('button', { name: /refresh/i });
  if ((await refresh.count()) > 0) await refresh.first().click();
  await page.waitForTimeout(1500);

  const scraped = await scrapeRetailerCartQuantities(page);
  console.log('scraped map', scraped);

  const dump = await page.evaluate(() => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const anchors = [...document.querySelectorAll('a[href*="/retailer/products/"]')].map((a) => ({
      href: a.getAttribute('href'),
      text: norm(a.textContent).slice(0, 80),
    }));
    const bodyText = norm(document.body.innerText).slice(0, 3000);
    return { anchors: anchors.slice(0, 20), bodyText };
  });
  console.log(JSON.stringify(dump, null, 2));
  await context.close();
}

main();
