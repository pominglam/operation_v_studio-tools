/* eslint-disable no-console */
/**
 * Pull AliExpress cookies from a running Edge/Chrome instance via CDP and upload to our API.
 *
 * Usage:
 *   node scripts/refresh-cookies-from-edge-cdp.cjs --cdp=http://127.0.0.1:9222 --api=http://localhost:8020/api/v1/price-research/aliexpress/cookies
 *
 * Notes:
 * - You must start Edge with remote debugging enabled (see docs in README / assistant instructions).
 * - This never logs cookie contents; only counts.
 */
const { chromium } = require('playwright');

function getArg(name, fallback = null) {
  const prefix = `--${name}=`;
  const hit = process.argv.find((a) => a.startsWith(prefix));
  if (!hit) return fallback;
  return hit.slice(prefix.length) || fallback;
}

async function main() {
  const cdpUrl = getArg('cdp', 'http://127.0.0.1:9222');
  const apiUrl = getArg('api', 'http://localhost:8020/api/v1/price-research/aliexpress/cookies');

  const browser = await chromium.connectOverCDP(cdpUrl);
  const contexts = browser.contexts();
  const context = contexts[0];
  if (!context) {
    throw new Error('No browser context found via CDP (is Edge running with remote debugging?)');
  }

  // Best effort: ensure AliExpress is in the cookie jar by visiting once.
  try {
    const page = await context.newPage();
    await page.goto('https://www.aliexpress.com', { waitUntil: 'domcontentloaded', timeout: 10000 });
    await page.waitForTimeout(800);
    await page.close();
  } catch {
    // ignore
  }

  const cookies = await context.cookies(['https://www.aliexpress.com', 'https://aliexpress.com']);
  if (!Array.isArray(cookies) || cookies.length === 0) {
    console.error('No AliExpress cookies found in the connected browser session.');
    console.error('Make sure you are logged into AliExpress in that Edge window, then re-run.');
    process.exitCode = 2;
    await browser.close();
    return;
  }

  console.log(`Found ${cookies.length} cookie(s). Uploading…`);

  const res = await fetch(apiUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ cookies }),
  });

  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`Upload failed: HTTP ${res.status} ${text}`.slice(0, 400));
  }

  const json = await res.json().catch(() => ({}));
  console.log(`Uploaded. Server count: ${json?.count ?? cookies.length}`);

  await browser.close();
}

main().catch((e) => {
  console.error(e?.message || e);
  process.exit(1);
});



