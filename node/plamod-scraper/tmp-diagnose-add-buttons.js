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
const { selectInStockQty, snapshotRetailerCart } = require('./src/plamod-restock-cart');

async function clickAndCheck(page, base, sku, label, locator) {
  await page.goto(`${base}/retailer/products/${sku}`, { waitUntil: 'domcontentloaded' });
  await selectInStockQty(page, 10);
  const posts = [];
  const handler = (req) => {
    if (req.method() === 'POST' && /cart/i.test(req.url())) {
      posts.push({ url: req.url(), body: req.postData()?.slice(0, 500) || null });
    }
  };
  page.on('request', handler);
  await locator.click();
  await page.waitForTimeout(1500);
  page.off('request', handler);
  await page.goto(`${base}/retailer/cart`, { waitUntil: 'domcontentloaded' });
  const cart = await snapshotRetailerCart(page, { refresh: true, expectedSkus: [sku], maxAttempts: 4 });
  console.log(label, { posts, cartQty: cart[sku] ?? 0 });
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

  const block = page.locator('[data-ovs-instock-block="1"]');
  await page.goto(`${base}/retailer/products/${sku}`, { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    for (const el of document.querySelectorAll('div, section')) {
      const text = norm(el.textContent || '');
      if (/IN[- ]?STOCK/i.test(text) && el.querySelector('button[role="combobox"]') && text.length <= 600) {
        el.setAttribute('data-ovs-instock-block', '1');
        break;
      }
    }
  });

  await selectInStockQty(page, 10);
  const svgs = await block.locator('button svg').evaluateAll((nodes) =>
    nodes.map((svg, i) => ({ i, path: svg.querySelector('path')?.getAttribute('d')?.slice(0, 40) || null })),
  );
  console.log('svg paths in block buttons', svgs);

  await clickAndCheck(page, base, sku, 'button8-last', block.locator('button:not([disabled])').last());
  await clickAndCheck(page, base, sku, 'button5-first-icon', block.locator('button:not([disabled])').first());
  await clickAndCheck(page, base, sku, 'getByCartIcon', page.locator('button').filter({ has: page.locator('svg') }).nth(8));

  const cartLinks = await page.locator('a, button').evaluateAll((els) =>
    els
      .map((el, i) => ({
        i,
        tag: el.tagName,
        text: (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60),
        href: el.getAttribute('href'),
      }))
      .filter((x) => /cart|add/i.test(`${x.text} ${x.href || ''}`)),
  );
  console.log('cart-ish elements', cartLinks);

  await context.close();
}

main();
