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

async function main() {
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  const sku = process.argv[2] || '5068381';
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 1400, height: 900 },
    locale: 'en-CA',
  });
  const page = await context.newPage();

  await ensureLoggedInQuick(page, baseUrl, context);

  const cartUrls = [`${baseUrl}/retailer/cart`, `${baseUrl}/retailer/shopping-cart`, `${baseUrl}/cart`];
  for (const url of cartUrls) {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => undefined);
    await page.waitForTimeout(1500);
    console.log('URL', page.url(), 'title', await page.title());
  }

  const pdpUrl = `${baseUrl}/retailer/products/${encodeURIComponent(sku)}`;
  await page.goto(pdpUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(2000);

  const pdpInfo = await page.evaluate(() => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const buttons = Array.from(document.querySelectorAll('button, a[role="button"], input[type="submit"]'))
      .map((el) => ({
        tag: el.tagName,
        text: norm(el.textContent || el.value || ''),
        aria: el.getAttribute('aria-label') || '',
        type: el.getAttribute('type') || '',
        disabled: Boolean(el.disabled),
      }));
    const inputs = Array.from(document.querySelectorAll('input'))
      .map((el) => ({
        name: el.getAttribute('name') || '',
        type: el.getAttribute('type') || '',
        placeholder: el.getAttribute('placeholder') || '',
        aria: el.getAttribute('aria-label') || '',
        value: el.value,
      }));
    const inStockBlocks = Array.from(document.querySelectorAll('div, section')).filter((el) => {
      const t = norm(el.textContent || '');
      return t.length < 800 && /IN[- ]?STOCK/i.test(t) && /MOQ/i.test(t);
    }).map((el) => norm(el.textContent || '').slice(0, 400));
    return { buttons, inputs, inStockBlocks };
  });

  console.log('PDP sku', retailerPdpSkuFromUrl(page.url()), 'requested', sku);
  console.log('PDP inStockBlocks', JSON.stringify(pdpInfo.inStockBlocks, null, 2));
  console.log('PDP buttons sample', JSON.stringify(pdpInfo.buttons.filter((b) => b.text.length < 80).slice(0, 40), null, 2));

  // Try increment qty and find add button
  const qtyButtons = pdpInfo.buttons.filter((b) => /^[+\-]$/.test(b.text) || /increment|decrement|plus|minus/i.test(`${b.text} ${b.aria}`));
  console.log('qtyButtons', qtyButtons);

  const plus = page.locator('button').filter({ hasText: /^\+$/ }).first();
  if (await plus.count()) {
    await plus.click();
    await page.waitForTimeout(800);
  }

  const afterQty = await page.evaluate(() => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    return Array.from(document.querySelectorAll('button'))
      .map((el) => norm(el.textContent || ''))
      .filter((t) => /cart|add|stock|order|checkout|continue/i.test(t));
  });
  console.log('buttons after + click', afterQty);

  await page.goto(`${baseUrl}/retailer/cart`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(2500);
  const cartInfo = await page.evaluate(() => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const rows = [];
    document.querySelectorAll('a[href*="/retailer/products/"]').forEach((a) => {
      const sku = (a.getAttribute('href') || '').split('/').pop() || '';
      let container = a.closest('tr, li, article, div');
      for (let i = 0; i < 8 && container; i += 1) {
        const text = norm(container.textContent || '');
        if (text.includes('SKU') || /\d+\s*x/i.test(text) || /qty/i.test(text)) {
          rows.push({ sku, text: text.slice(0, 300) });
          break;
        }
        container = container.parentElement;
      }
    });
    return {
      url: location.href,
      title: document.title,
      body: norm(document.body.innerText || '').slice(0, 4000),
      rows,
      buttons: Array.from(document.querySelectorAll('button')).map((b) => norm(b.textContent || '')).filter(Boolean).slice(0, 30),
    };
  });
  console.log('CART', JSON.stringify(cartInfo, null, 2));

  await browser.close();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
