/* eslint-disable no-console */
const { chromium } = require('playwright');
const { ensureLoggedInQuick } = require('./src/plamod');

async function firstBlockText(page) {
  const block = page.locator('div, section').filter({ hasText: /^IN-STOCK/i }).first();
  return block.evaluate((el) => ({
    len: (el.textContent || '').replace(/\s+/g, ' ').trim().length,
    text: (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 80),
    combos: el.querySelectorAll('button[role="combobox"]').length,
    buttons: [...el.querySelectorAll('button')].filter((b) => !b.disabled && b.getAttribute('role') !== 'combobox').length,
  }));
}

async function main() {
  const base = 'https://plamod.com';
  const browser = await chromium.launch({ headless: true });
  const page = await (await browser.newContext()).newPage();
  await ensureLoggedInQuick(page, base, page.context());
  await page.goto(`${base}/retailer/products/5058815`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);

  console.log('initial', await firstBlockText(page));
  const block = page.locator('div, section').filter({ hasText: /^IN-STOCK/i }).first();
  await block.locator('button[role="combobox"]').first().click();
  await page.waitForTimeout(200);
  console.log('afterComboOpen', await firstBlockText(page));
  await page.keyboard.press('Escape');
  await browser.close();
}

main();
