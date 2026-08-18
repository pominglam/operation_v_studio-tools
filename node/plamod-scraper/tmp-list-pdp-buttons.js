/* eslint-disable no-console */
const fs = require('fs');
const path = require('path');
const envPath = path.resolve(__dirname, '..', '..', '.env');
if (fs.existsSync(envPath)) {
  for (const line of fs.readFileSync(envPath, 'utf8').split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#') || !trimmed.includes('=')) continue;
    const idx = trimmed.indexOf('=');
    const key = trimmed.slice(0, idx).trim();
    let value = trimmed.slice(idx + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }
    if (!process.env[key]) process.env[key] = value;
  }
}

const { chromium } = require('playwright');
const { ensureLoggedInQuick } = require('./src/plamod');
const { selectInStockQty, scrapeRetailerCartQuantities } = require('./src/plamod-restock-cart');

async function main() {
  const sku = '5058815';
  const base = 'https://plamod.com';
  const context = await chromium.launchPersistentContext(path.resolve(__dirname, '.pw-user-data'), {
    headless: true,
    viewport: { width: 1400, height: 900 },
  });
  const page = context.pages()[0] || (await context.newPage());
  await ensureLoggedInQuick(page, base, context);
  await page.goto(`${base}/retailer/products/${sku}`, { waitUntil: 'domcontentloaded' });
  await selectInStockQty(page, 10);

  const buttons = await page.locator('button').evaluateAll((btns) =>
    btns.map((b, i) => ({
      i,
      text: (b.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 80),
      aria: b.getAttribute('aria-label'),
      disabled: b.disabled,
      role: b.getAttribute('role'),
      visible: b.offsetParent !== null,
      html: b.outerHTML.slice(0, 160),
    })).filter((b) => b.visible && !b.disabled),
  );
  console.log(JSON.stringify(buttons, null, 2));

  await context.close();
}

main();
