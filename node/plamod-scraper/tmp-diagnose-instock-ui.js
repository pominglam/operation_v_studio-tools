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

async function inspectSku(page, sku) {
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  await page.goto(`${baseUrl}/retailer/products/${encodeURIComponent(sku)}`, {
    waitUntil: 'domcontentloaded',
    timeout: 30000,
  });
  await page.waitForTimeout(2000);

  return page.evaluate(() => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const blocks = [...document.querySelectorAll('div, section')].filter((el) => {
      const t = norm(el.textContent || '');
      return t.length > 20 && t.length < 2500 && /IN[- ]?STOCK/i.test(t);
    });
    blocks.sort((a, b) => norm(a.textContent).length - norm(b.textContent).length);

    return {
      url: window.location.href,
      blocks: blocks.slice(0, 6).map((block, idx) => ({
        idx,
        text: norm(block.textContent).slice(0, 500),
        startsWithInStock: /^IN[- ]?STOCK/i.test(norm(block.textContent || '')),
        combos: [...block.querySelectorAll('button[role="combobox"]')].map((b) => norm(b.textContent)),
        buttons: [...block.querySelectorAll('button')].map((b, i) => ({
          i,
          text: norm(b.textContent),
          disabled: b.disabled,
          role: b.getAttribute('role'),
          aria: b.getAttribute('aria-label'),
        })),
        inputs: [...block.querySelectorAll('input')].map((input) => ({
          type: input.type,
          value: input.value,
          aria: input.getAttribute('aria-label'),
          name: input.name,
        })),
      })),
    };
  });
}

async function main() {
  const skus = process.argv.slice(2);
  if (skus.length === 0) {
    skus.push('5058815', '5058009');
  }

  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'en-CA' });
  const page = await context.newPage();
  await ensureLoggedInQuick(page, baseUrl, context);

  for (const sku of skus) {
    const info = await inspectSku(page, sku);
    console.log(`\n=== ${sku} ===`);
    console.log(JSON.stringify(info, null, 2));
  }

  await browser.close();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
