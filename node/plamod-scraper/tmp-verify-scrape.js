/* eslint-disable no-console */
const { scrapeRetailerCartQuantities } = require('./src/plamod-restock-cart');
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

(async () => {
  const context = await chromium.launchPersistentContext(path.resolve(__dirname, '.pw-user-data'), {
    headless: true,
    viewport: { width: 1400, height: 900 },
  });
  const page = context.pages()[0] || (await context.newPage());
  await ensureLoggedInQuick(page, 'https://plamod.com', context);
  await page.goto('https://plamod.com/retailer/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  const map = await scrapeRetailerCartQuantities(page);
  console.log(JSON.stringify(map));
  await context.close();
})();
