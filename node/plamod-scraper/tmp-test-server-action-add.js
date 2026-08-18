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
const { markInStockBlock, selectInStockQty, snapshotRetailerCart } = require('./src/plamod-restock-cart');

async function main() {
  const sku = process.argv[2] || '5058815';
  const qty = Number.parseInt(process.argv[3] || '10', 10);
  const base = 'https://plamod.com';
  const context = await chromium.launchPersistentContext(path.resolve(__dirname, '.pw-user-data'), {
    headless: true,
    viewport: { width: 1400, height: 900 },
  });
  const page = context.pages()[0] || (await context.newPage());
  await ensureLoggedInQuick(page, base, context);
  await page.goto(`${base}/retailer/products/${sku}`, { waitUntil: 'domcontentloaded' });
  await markInStockBlock(page);

  const productId = await page.evaluate(() => {
    const scripts = [...document.querySelectorAll('script')].map((s) => s.textContent || '');
    for (const text of scripts) {
      const m = text.match(/"productId"\s*:\s*(\d+)/);
      if (m) return Number.parseInt(m[1], 10);
    }
    return null;
  });
  console.log('productId', productId);

  const posts = [];
  page.on('request', (req) => {
    if (req.method() === 'POST' && req.url().includes(`/retailer/products/${sku}`)) {
      posts.push(req.postData());
    }
  });

  await selectInStockQty(page, qty);

  // Try invoking add via page.evaluate fetch to server action endpoint
  const actionResult = await page.evaluate(async ({ skuArg, productIdArg, qtyArg }) => {
    const res = await fetch(`/retailer/products/${skuArg}`, {
      method: 'POST',
      headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
      body: JSON.stringify([{ productId: productIdArg, quantity: qtyArg }]),
    });
    return { status: res.status, text: (await res.text()).slice(0, 500) };
  }, { skuArg: sku, productIdArg: productId, qtyArg: qty });
  console.log('direct action', actionResult);
  console.log('posts during select', posts);

  await page.waitForTimeout(1500);
  await page.goto(`${base}/retailer/cart`, { waitUntil: 'domcontentloaded' });
  const cart = await snapshotRetailerCart(page, { refresh: true, expectedSkus: [sku], maxAttempts: 4 });
  console.log('cartQty', cart[sku] ?? 0);
  await context.close();
}

main();
