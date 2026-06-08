const path = require('node:path');
const fs = require('node:fs');
const { chromium } = require('playwright');

async function main() {
  const profileDir = path.resolve(__dirname, '..', '.pw-user-data');
  const context = await chromium.launchPersistentContext(profileDir, { headless: true, viewport: { width: 1400, height: 900 } });
  const page = await context.newPage();
  const samples = [];

  page.on('response', async (res) => {
    const ct = res.headers()['content-type'] || '';
    if (!ct.includes('text/x-component')) return;
    const body = await res.text();
    if (!/5054485|product|sku|manufacturer/i.test(body)) return;
    if (body.length < 500) return;
    samples.push({ url: res.url(), len: body.length, body: body.slice(0, 4000) });
  });

  await page.goto('https://plamod.com/retailer/manufacturers/1', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(3000);
  for (let i = 0; i < 30; i++) {
    await page.evaluate(() => window.scrollBy(0, 900));
    await page.waitForTimeout(600);
  }

  const out = path.resolve('/var/www/html/storage/app/private/plamod/debug/manufacturer-1-export', `${Date.now()}-rsc-samples.json`);
  fs.mkdirSync(path.dirname(out), { recursive: true });
  fs.writeFileSync(out, JSON.stringify(samples.slice(0, 15), null, 2));
  console.log('saved', out, 'count', samples.length);
  await context.close();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
