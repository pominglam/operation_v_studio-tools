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
const { markInStockBlock, getInStockBlock } = require('./src/plamod-restock-cart');

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
  await markInStockBlock(page);
  const block = getInStockBlock(page);
  const combo = block.locator('button[role="combobox"]').first();
  await combo.click();
  await page.waitForTimeout(200);
  const posts = [];
  page.on('request', (req) => {
    if (req.method() === 'POST') {
      posts.push({
        url: req.url(),
        headers: req.headers(),
        body: req.postData(),
      });
    }
  });
  await page.getByRole('option', { name: '10', exact: true }).first().click();
  await page.waitForTimeout(2000);
  console.log(JSON.stringify(posts.filter((p) => p.url.includes('products')), null, 2));
  await context.close();
}

main();
