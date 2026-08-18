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
const { scrapeRetailerCartQuantities } = require('./src/plamod-restock-cart');

async function main() {
  const sku = process.argv[2] || '5058815';
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'en-CA' });
  const page = await context.newPage();
  await ensureLoggedInQuick(page, baseUrl, context);
  await page.goto(`${baseUrl}/retailer/cart`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(2500);

  const scraped = await scrapeRetailerCartQuantities(page);
  console.log('scrapedMap', scraped);
  console.log('targetSkuQty', scraped[sku] ?? 'NOT FOUND');

  const dump = await page.evaluate((targetSku) => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const skuFromHref = (href) => {
      const clean = String(href || '').split('?')[0];
      const parts = clean.split('/').filter(Boolean);
      const skuVal = parts[parts.length - 1] || '';
      return /^[0-9A-Za-z_-]+$/.test(skuVal) ? skuVal : '';
    };

    const anchors = [...document.querySelectorAll('a[href*="/retailer/products/"]')].filter((a) =>
      skuFromHref(a.getAttribute('href') || '') === targetSku,
    );

    return anchors.map((anchor, idx) => {
      let container = anchor;
      const chain = [];
      for (let depth = 0; depth < 12; depth += 1) {
        const text = norm(container.textContent || '');
        chain.push({
          depth,
          tag: container.tagName,
          text: text.slice(0, 350),
          hasSkuLabel: /SKU\s*:/i.test(text),
          hasInStock: /IN[- ]?STOCK/i.test(text),
          combo: norm(container.querySelector('button[role="combobox"]')?.textContent || ''),
          totalMatch: text.match(/TOTAL0*(\d+)/i)?.[1] ?? null,
        });
        if (!container.parentElement) break;
        container = container.parentElement;
      }
      return { idx, chain };
    });
  }, sku);

  console.log(JSON.stringify(dump, null, 2));
  await browser.close();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
