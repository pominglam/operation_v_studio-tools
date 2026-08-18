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
const { ensureLoggedInQuick, retailerPdpSkuFromUrl } = require('./src/plamod');

async function scrapeCartRows(page) {
  return page.evaluate(() => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const skuFromHref = (href) => {
      const clean = String(href || '').split('?')[0];
      const parts = clean.split('/').filter(Boolean);
      const sku = parts[parts.length - 1] || '';
      return /^[0-9A-Za-z_-]+$/.test(sku) ? sku : '';
    };

    /** @type {Array<{sku: string, qty: number|null, text: string}>} */
    const rows = [];
    const seen = new Set();
    for (const anchor of document.querySelectorAll('a[href*="/retailer/products/"]')) {
      const sku = skuFromHref(anchor.getAttribute('href') || '');
      if (!sku || seen.has(sku)) continue;
      let container = anchor;
      for (let depth = 0; depth < 14; depth += 1) {
        if (!container.parentElement) break;
        container = container.parentElement;
        const text = norm(container.textContent || '');
        if (!/SKU\s*:/i.test(text)) continue;
        const inStockQty = text.match(/IN[- ]?STOCK[\s\S]{0,120}?(\d+)\s+TOTAL/i);
        const genericQty = text.match(/(\d+)\s+TOTAL\s+\d+/i);
        const comboQty = container.querySelector('button[role="combobox"]')?.textContent;
        const parsedCombo = Number.parseInt(norm(comboQty || ''), 10);
        const qtyRaw = inStockQty?.[1] || genericQty?.[1] || (Number.isFinite(parsedCombo) ? String(parsedCombo) : '');
        const qty = qtyRaw !== '' ? Number.parseInt(String(qtyRaw), 10) : null;
        rows.push({ sku, qty: Number.isFinite(qty) ? qty : null, text: text.slice(0, 500) });
        seen.add(sku);
        break;
      }
    }
    return rows;
  });
}

async function findInStockBlock(page) {
  return page.evaluate(() => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const blocks = Array.from(document.querySelectorAll('div, section')).filter((el) => {
      const t = norm(el.textContent || '');
      return t.length > 20 && t.length < 500 && /^IN[- ]?STOCK/i.test(t) && /MOQ/i.test(t) && /TOTAL/i.test(t);
    });
    blocks.sort((a, b) => norm(a.textContent).length - norm(b.textContent).length);
    const block = blocks[0];
    if (!block) return null;
    const buttons = Array.from(block.querySelectorAll('button')).map((btn, index) => ({
      index,
      text: norm(btn.textContent || ''),
      disabled: btn.disabled,
      html: btn.outerHTML.slice(0, 200),
    }));
    return { text: norm(block.textContent || ''), buttons };
  });
}

async function setInStockQty(page, targetQty) {
  const block = page.locator('div, section').filter({ hasText: /^IN-STOCK/i }).first();
  await block.waitFor({ state: 'visible', timeout: 15000 });

  const combo = block.locator('button[role="combobox"]').first();
  await combo.click();
  await page.waitForTimeout(300);

  const option = page.getByRole('option', { name: String(targetQty), exact: true });
  if ((await option.count()) > 0) {
    await option.first().click();
    await page.waitForTimeout(400);
    return targetQty;
  }

  // Fallback: pick closest numeric option <= target
  const options = page.getByRole('option');
  const count = await options.count();
  let best = 0;
  for (let i = 0; i < count; i += 1) {
    const text = ((await options.nth(i).textContent()) || '').trim();
    const n = Number.parseInt(text, 10);
    if (!Number.isFinite(n)) continue;
    if (n <= targetQty && n >= best) best = n;
  }
  if (best > 0) {
    await page.getByRole('option', { name: String(best), exact: true }).first().click();
    await page.waitForTimeout(400);
    return best;
  }

  await page.keyboard.press('Escape').catch(() => undefined);
  return 0;
}

async function clickAddInStockToCart(page) {
  const block = page.locator('div, section').filter({ hasText: /^IN-STOCK/i }).first();
  const candidates = [
    block.locator('button').filter({ hasText: /add to cart/i }),
    block.locator('button').filter({ hasText: /add/i }),
    page.locator('button').filter({ hasText: /add to cart/i }),
    page.locator('button').filter({ hasText: /^add$/i }),
  ];
  for (const loc of candidates) {
    if ((await loc.count()) > 0) {
      await loc.first().click();
      await page.waitForTimeout(1500);
      return 'clicked-visible-add';
    }
  }

  // Icon-only buttons: last enabled button in IN-STOCK block
  const enabled = block.locator('button:not([disabled])');
  const count = await enabled.count();
  if (count >= 3) {
    await enabled.nth(count - 1).click();
    await page.waitForTimeout(1500);
    return 'clicked-last-enabled-in-block';
  }
  return 'no-add-button-found';
}

async function main() {
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  const sku = process.argv[2] || '5068381';
  const qty = Number.parseInt(process.argv[3] || '1', 10);

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'en-CA' });
  const page = await context.newPage();

  await ensureLoggedInQuick(page, baseUrl, context);

  await page.goto(`${baseUrl}/retailer/products/${encodeURIComponent(sku)}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(2000);
  console.log('on pdp', retailerPdpSkuFromUrl(page.url()));

  const blockInfo = await findInStockBlock(page);
  console.log('inStockBlock', JSON.stringify(blockInfo, null, 2));

  const finalQty = await setInStockQty(page, qty);
  console.log('set qty', finalQty);

  const addResult = await clickAddInStockToCart(page);
  console.log('addResult', addResult);

  await page.goto(`${baseUrl}/retailer/cart`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(2500);
  const rows = await scrapeCartRows(page);
  console.log('cartRows', JSON.stringify(rows, null, 2));

  await browser.close();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
