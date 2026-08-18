/* eslint-disable no-console */
const { chromium } = require('playwright');
const { ensureLoggedInQuick } = require('./src/plamod');

async function main() {
  const base = 'https://plamod.com';
  const sku = '5058815';
  const browser = await chromium.launch({ headless: true });
  const page = await (await browser.newContext()).newPage();
  await ensureLoggedInQuick(page, base, page.context());
  await page.goto(`${base}/retailer/search?tab=instock&q=${sku}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);

  const info = await page.evaluate(() => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const card = [...document.querySelectorAll('a[href*="/retailer/products/5058815"]')][0];
    if (!card) return { error: 'no card' };
    let container = card;
    for (let d = 0; d < 12; d += 1) {
      if (!container.parentElement) break;
      container = container.parentElement;
      const text = norm(container.textContent || '');
      if (/IN[- ]?STOCK/i.test(text) && /MOQ/i.test(text)) {
        return {
          text: text.slice(0, 300),
          combos: [...container.querySelectorAll('button[role="combobox"]')].map((b) => norm(b.textContent)),
          buttons: [...container.querySelectorAll('button')].map((b, i) => ({
            i,
            text: norm(b.textContent),
            disabled: b.disabled,
            role: b.getAttribute('role'),
          })),
          inputs: [...container.querySelectorAll('input')].map((i) => ({ type: i.type, value: i.value })),
        };
      }
    }
    return { error: 'no instock container' };
  });

  console.log(JSON.stringify(info, null, 2));
  await browser.close();
}

main();
