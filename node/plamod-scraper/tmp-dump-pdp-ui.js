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
const { selectInStockQty } = require('./src/plamod-restock-cart');

async function main() {
  const sku = '5058815';
  const base = 'https://plamod.com';
  const context = await chromium.launchPersistentContext(path.resolve(__dirname, '.pw-user-data'), {
    headless: true,
    viewport: { width: 1400, height: 900 },
  });
  const page = context.pages()[0] || (await context.newPage());
  await ensureLoggedInQuick(page, base, context);
  await page.goto(`${base}/retailer/products/${sku}`, { waitUntil: 'networkidle', timeout: 60000 }).catch(() => undefined);
  await selectInStockQty(page, 10);

  const dump = await page.evaluate(() => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const buttons = [...document.querySelectorAll('button')].map((b, i) => {
      const svg = b.querySelector('svg');
      const paths = svg ? [...svg.querySelectorAll('path')].map((p) => p.getAttribute('d')) : [];
      return {
        i,
        text: norm(b.textContent),
        disabled: b.disabled,
        role: b.getAttribute('role'),
        paths,
        rect: b.getBoundingClientRect(),
        html: b.outerHTML.slice(0, 220),
      };
    });
    const sections = [...document.querySelectorAll('div, section')]
      .map((el) => norm(el.textContent))
      .filter((t) => /IN[- ]?STOCK|OFFER|PRE[- ]?ORDER|add/i.test(t) && t.length < 300)
      .slice(0, 20);
    return { buttons, sections };
  });
  console.log(JSON.stringify(dump, null, 2));
  await context.close();
}

main();
