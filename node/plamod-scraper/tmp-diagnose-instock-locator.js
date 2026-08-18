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
const {
  selectInStockQty,
  clickAddInStockToCart,
} = require('./src/plamod-restock-cart');

async function probeLocator(page) {
  const block = page.locator('div, section').filter({ hasText: /^IN-STOCK/i }).first();
  const text = await block.evaluate((el) => (el.textContent || '').replace(/\s+/g, ' ').trim()).catch(() => '');
  const comboCount = await block.locator('button[role="combobox"]').count();
  const btnCount = await block.locator('button:not([disabled])').count();
  return { text: text.slice(0, 200), comboCount, btnCount };
}

async function main() {
  const sku = process.argv[2] || '5058815';
  const qty = Number.parseInt(process.argv[3] || '10', 10);
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'en-CA' });
  const page = await context.newPage();
  await ensureLoggedInQuick(page, baseUrl, context);
  await page.goto(`${baseUrl}/retailer/products/${encodeURIComponent(sku)}`, {
    waitUntil: 'domcontentloaded',
    timeout: 30000,
  });
  await page.waitForTimeout(2000);

  console.log('locatorProbe', await probeLocator(page));

  try {
    const pick = await selectInStockQty(page, qty);
    console.log('selectInStockQty', pick);
    const clicked = await clickAddInStockToCart(page);
    console.log('clickAddInStockToCart', clicked);
  } catch (e) {
    console.error('error', e.message);
  }

  await browser.close();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
