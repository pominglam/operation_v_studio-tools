const path = require('node:path');
const fs = require('node:fs');
const { exportManufacturerPreordersCsv } = require('../src/plamod');
const { chromium } = require('playwright');

async function main() {
  const baseUrl = 'https://plamod.com';
  const profileDir = path.resolve(__dirname, '..', '.pw-user-data');
  const context = await chromium.launchPersistentContext(profileDir, {
    headless: true,
    viewport: { width: 1400, height: 900 },
  });
  const page = await context.newPage();
  const hits = [];

  page.on('response', async (res) => {
    const url = res.url();
    if (!url.includes('plamod.com')) return;
    const ct = res.headers()['content-type'] || '';
    if (!ct.includes('json') && !ct.includes('text/plain') && !ct.includes('text/x-component')) return;
    try {
      const body = await res.text();
      if (!/sku|product|preorder|0225768/i.test(body)) return;
      const skuCount = (body.match(/"sku"/gi) || []).length;
      if (skuCount < 3 && !body.includes('0225768')) return;
      hits.push({ status: res.status(), url, ct, skuCount, preview: body.slice(0, 800) });
    } catch {
      // ignore
    }
  });

  // piggyback login via export's first step by visiting sign-in flow manually is heavy; use export for login only
  await context.close();

  const out = await exportManufacturerPreordersCsv({ manufacturerId: 1, tab: 'Preorder', category: 'Plastic Model Kits' });
  console.log(JSON.stringify(out, null, 2));
}

main();
