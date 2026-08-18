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

async function main() {
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'en-CA' });
  const page = await context.newPage();
  await ensureLoggedInQuick(page, baseUrl, context);
  await page.goto(`${baseUrl}/retailer/cart`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(2500);
  const dump = await page.evaluate(() => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const anchors = Array.from(document.querySelectorAll('a[href*="/retailer/products/5068381"]'));
    return anchors.map((anchor, idx) => {
      let container = anchor;
      const chain = [];
      for (let depth = 0; depth < 10; depth += 1) {
        chain.push({
          depth,
          tag: container.tagName,
          text: norm(container.textContent || '').slice(0, 400),
          combo: norm(container.querySelector('button[role="combobox"]')?.textContent || ''),
          buttons: Array.from(container.querySelectorAll('button')).map((b) => norm(b.textContent || '[icon]')),
        });
        if (!container.parentElement) break;
        container = container.parentElement;
      }
      return { idx, chain };
    });
  });
  console.log(JSON.stringify(dump, null, 2));
  await browser.close();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
