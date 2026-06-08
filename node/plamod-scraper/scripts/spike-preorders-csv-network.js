const path = require('node:path');
const fs = require('node:fs');
const { chromium } = require('playwright');

async function main() {
  const baseUrl = 'https://plamod.com';
  const profileDir = path.resolve(__dirname, '..', '.pw-user-data');
  const plamod = require('../src/plamod');

  plamod.cleanupPersistentProfileLocks(profileDir);
  const context = await chromium.launchPersistentContext(profileDir, {
    headless: true,
    acceptDownloads: true,
    viewport: { width: 1400, height: 900 },
  });
  const page = await context.newPage();
  const hits = [];

  page.on('response', async (res) => {
    const url = res.url();
    if (!/plamod\.com/i.test(url)) return;
    if (!/csv|export|preorder|download|api/i.test(url)) return;
    const ct = res.headers()['content-type'] || '';
    let body = '';
    try {
      body = (await res.text()).slice(0, 400);
    } catch {
      body = '';
    }
    hits.push({ status: res.status(), url, ct, body });
  });

  // Minimal path to preorders csv click - reuse export function's login by requiring ensureLoggedIn isn't exported.
  // Trigger full export and inspect network file written by manufacturer debug instead.
  await context.close();

  const out = await plamod.exportPlamodPreordersCsv();
  console.log('export', out.ok, out.csv_storage_path, out.bytes);
}

main().catch(console.error);
