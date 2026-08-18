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

async function main() {
  const base = 'https://plamod.com';
  const context = await chromium.launchPersistentContext(path.resolve(__dirname, '.pw-user-data'), {
    headless: true,
    viewport: { width: 1400, height: 900 },
  });
  const page = context.pages()[0] || (await context.newPage());
  await ensureLoggedInQuick(page, base, context);
  await page.goto(`${base}/retailer/cart`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);

  const rows = await page.evaluate(() => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const skuFromHref = (href) => {
      const parts = String(href || '').split('?')[0].split('/').filter(Boolean);
      const sku = parts[parts.length - 1] || '';
      return /^[0-9A-Za-z_-]+$/.test(sku) ? sku : '';
    };

    return [...document.querySelectorAll('a[href*="/retailer/products/"]')].map((anchor) => {
      const sku = skuFromHref(anchor.getAttribute('href') || '');
      let container = anchor;
      let best = null;
      for (let depth = 0; depth < 14; depth += 1) {
        if (!container.parentElement) break;
        container = container.parentElement;
        const text = norm(container.textContent || '');
        if (!new RegExp(`SKU\\s*:\\s*${sku}(?:\\b|$)`, 'i').test(text)) continue;
        const combo = norm(container.querySelector('button[role="combobox"]')?.textContent || '');
        best = { depth, text: text.slice(0, 350), combo };
        break;
      }
      return { sku, ...best };
    }).filter((r, i, arr) => r.sku && arr.findIndex((x) => x.sku === r.sku) === i);
  });

  console.log(JSON.stringify(rows, null, 2));
  await context.close();
}

main();
