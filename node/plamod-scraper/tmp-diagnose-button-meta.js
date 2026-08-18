/* eslint-disable no-console */
const { chromium } = require('playwright');
const { ensureLoggedInQuick } = require('./src/plamod');

async function main() {
  const base = 'https://plamod.com';
  const browser = await chromium.launch({ headless: true });
  const page = await (await browser.newContext()).newPage();
  await ensureLoggedInQuick(page, base, page.context());
  await page.goto(`${base}/retailer/products/5058815`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);

  const details = await page.evaluate(() => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const block = [...document.querySelectorAll('div, section')]
      .filter((el) => /IN[- ]?STOCK/i.test(norm(el.textContent || '')) && el.querySelector('button[role="combobox"]'))
      .sort((a, b) => norm(a.textContent).length - norm(b.textContent).length)[0];
    if (!block) return null;
    return {
      text: norm(block.textContent).slice(0, 150),
      buttons: [...block.querySelectorAll('button')].map((b, i) => ({
        i,
        text: norm(b.textContent),
        disabled: b.disabled,
        role: b.getAttribute('role'),
        aria: b.getAttribute('aria-label'),
        title: b.getAttribute('title'),
        className: b.className,
        html: b.outerHTML.slice(0, 180),
      })),
    };
  });

  console.log(JSON.stringify(details, null, 2));
  await browser.close();
}

main();
