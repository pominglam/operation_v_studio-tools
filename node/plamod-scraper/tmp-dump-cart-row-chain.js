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
  const sku = process.argv[2] || '5058815';
  const base = 'https://plamod.com';
  const context = await chromium.launchPersistentContext(path.resolve(__dirname, '.pw-user-data'), {
    headless: true,
    viewport: { width: 1400, height: 900 },
  });
  const page = context.pages()[0] || (await context.newPage());
  await ensureLoggedInQuick(page, base, context);
  await page.goto(`${base}/retailer/cart`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);

  const dump = await page.evaluate((targetSku) => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const anchor = [...document.querySelectorAll('a[href*="/retailer/products/"]')].find((a) =>
      (a.getAttribute('href') || '').includes(targetSku),
    );
    if (!anchor) return { error: 'no anchor' };
    const chain = [];
    let container = anchor;
    for (let depth = 0; depth < 12; depth += 1) {
      const text = norm(container.textContent || '');
      chain.push({
        depth,
        tag: container.tagName,
        text: text.slice(0, 400),
        combo: norm(container.querySelector('button[role="combobox"]')?.textContent || ''),
        buttons: [...container.querySelectorAll('button')].map((b) => norm(b.textContent || '[icon]')),
      });
      if (!container.parentElement) break;
      container = container.parentElement;
    }
    return { chain };
  }, sku);

  console.log(JSON.stringify(dump, null, 2));
  await context.close();
}

main();
