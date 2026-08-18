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
const { markInStockBlock, getInStockBlock, selectInStockQty, scrapeRetailerCartQuantities } = require('./src/plamod-restock-cart');

async function clickPlusButtons(page) {
  await markInStockBlock(page);
  const block = getInStockBlock(page);
  const buttons = block.locator('button:not([disabled])');
  const count = await buttons.count();
  for (let i = 0; i < count; i += 1) {
    const btn = buttons.nth(i);
    const paths = await btn.locator('svg path').evaluateAll((nodes) => nodes.map((n) => n.getAttribute('d')));
    const isPlus = paths.some((d) => d === 'M12 5v14') && paths.some((d) => d === 'M5 12h14');
    if (!isPlus) continue;
    const posts = [];
    const handler = (req) => {
      if (req.method() === 'POST') {
        posts.push({ url: req.url(), action: req.headers()['next-action'], body: req.postData() });
      }
    };
    page.on('request', handler);
    await btn.click();
    await page.waitForTimeout(1500);
    page.off('request', handler);
    console.log('plus click', i, posts);
  }
}

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
  console.log('before cart', await (async () => {
    await page.goto(`${base}/retailer/cart`, { waitUntil: 'domcontentloaded' });
    return scrapeRetailerCartQuantities(page);
  })());
  await page.goto(`${base}/retailer/products/${sku}`, { waitUntil: 'domcontentloaded' });
  await clickPlusButtons(page);
  await page.goto(`${base}/retailer/cart`, { waitUntil: 'domcontentloaded' });
  console.log('after cart', await scrapeRetailerCartQuantities(page));
  await context.close();
}

main();
