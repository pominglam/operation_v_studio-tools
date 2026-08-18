/* eslint-disable no-console */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const { ensureLoggedInQuick } = require('./src/plamod');

async function main() {
  const sku = process.argv[2] || '5058815';
  const baseUrl = 'https://plamod.com';
  const browser = await chromium.launch({ headless: true });
  const page = await (await browser.newContext()).newPage();
  await ensureLoggedInQuick(page, baseUrl, page.context());
  await page.goto(`${baseUrl}/retailer/products/${sku}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);

  const matches = await page.evaluate(() => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const out = [];
    for (const el of document.querySelectorAll('div, section')) {
      const text = norm(el.textContent || '');
      if (!/^IN[- ]?STOCK/i.test(text)) continue;
      out.push({
        len: text.length,
        text: text.slice(0, 120),
        combos: el.querySelectorAll('button[role="combobox"]').length,
        enabledButtons: [...el.querySelectorAll('button')].filter((b) => !b.disabled && b.getAttribute('role') !== 'combobox').length,
      });
    }
    return out.sort((a, b) => a.len - b.len);
  });

  console.log(JSON.stringify(matches, null, 2));

  const first = page.locator('div, section').filter({ hasText: /^IN-STOCK/i }).first();
  const firstText = await first.evaluate((el) => (el.textContent || '').replace(/\s+/g, ' ').trim());
  console.log('playwrightFirst', firstText.slice(0, 200));

  await browser.close();
}

main();
