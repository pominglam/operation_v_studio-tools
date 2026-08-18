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
  markInStockBlock,
  getInStockBlock,
  readInStockBlockState,
  selectInStockQty,
  scrapeRetailerCartQuantities,
  snapshotRetailerCart,
} = require('./src/plamod-restock-cart');

async function describeButtons(block) {
  return block.locator('button').evaluateAll((btns) =>
    btns.map((b, i) => ({
      i,
      text: (b.textContent || '').trim(),
      disabled: b.disabled,
      role: b.getAttribute('role'),
      ariaLabel: b.getAttribute('aria-label'),
      title: b.getAttribute('title'),
      className: b.className,
      outer: b.outerHTML.slice(0, 180),
    })),
  );
}

async function main() {
  const sku = '5058815';
  const base = 'https://plamod.com';
  const dir = path.resolve(__dirname, '.pw-user-data');
  const context = await chromium.launchPersistentContext(dir, {
    headless: true,
    viewport: { width: 1400, height: 900 },
  });
  const page = context.pages()[0] || (await context.newPage());
  await page.route('**/*', (route) => {
    const type = route.request().resourceType();
    if (type === 'image' || type === 'font' || type === 'media') route.abort();
    else route.continue();
  });

  page.on('response', (res) => {
    const url = res.url();
    if (/cart|basket|line/i.test(url) && res.request().method() !== 'GET') {
      console.log('HTTP', res.request().method(), res.status(), url.slice(0, 120));
    }
  });

  await ensureLoggedInQuick(page, base, context);
  await page.goto(`${base}/retailer/products/${sku}`, { waitUntil: 'domcontentloaded' });
  await markInStockBlock(page);
  const block = getInStockBlock(page);
  await selectInStockQty(page, 10);
  const before = await readInStockBlockState(block);
  console.log('before', before);
  console.log('buttons', await describeButtons(block));

  const buttons = block.locator('button:not([disabled])');
  const count = await buttons.count();
  for (let i = count - 1; i >= 0; i -= 1) {
    const btn = buttons.nth(i);
    const role = await btn.getAttribute('role');
    if (role === 'combobox') continue;

    const meta = await btn.evaluate((b) => ({
      text: (b.textContent || '').trim(),
      ariaLabel: b.getAttribute('aria-label'),
      title: b.getAttribute('title'),
      html: b.outerHTML.slice(0, 200),
    }));
    console.log('\n--- click candidate', i, meta);
    await btn.click();
    await page.waitForTimeout(800);
    const after = await readInStockBlockState(block);
    console.log('after block', after);
    await page.goto(`${base}/retailer/cart`, { waitUntil: 'domcontentloaded' });
    const cart = await snapshotRetailerCart(page, { refresh: true, expectedSkus: [sku], maxAttempts: 6 });
    console.log('cart qty', cart[sku] ?? 0);
    if ((cart[sku] ?? 0) > 0) {
      console.log('SUCCESS with button', i);
      break;
    }
    await page.goto(`${base}/retailer/products/${sku}`, { waitUntil: 'domcontentloaded' });
    await selectInStockQty(page, 10);
  }

  await context.close();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
