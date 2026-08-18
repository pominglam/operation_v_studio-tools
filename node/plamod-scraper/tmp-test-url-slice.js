const path = require('path');
const { chromium } = require('playwright');

async function clickInStockTab(page) {
  return page.evaluate(() => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const tabs = Array.from(document.querySelectorAll('button, a, [role="tab"]'));
    const hit = tabs.find((el) => norm(el.textContent).includes('In-Stock') || norm(el.textContent).includes('In Stock'));
    if (!hit) {
      return false;
    }
    hit.click();
    return true;
  });
}

async function main() {
  const baseUrl = 'https://plamod.com';
  const profileDir = process.env.PLAMOD_PERSISTENT_PROFILE_DIR || path.resolve(__dirname, '..', '.pw-user-data');
  const context = await chromium.launchPersistentContext(profileDir, {
    headless: true,
    viewport: { width: 1400, height: 900 },
  });
  const page = await context.newPage();
  await page.goto(`${baseUrl}/retailer/manufacturers/1`, { timeout: 45000 });
  if ((await page.content()).includes('Sign in')) {
    console.error('Not logged in - run a sync first');
    process.exit(2);
  }

  for (const query of [
    'manufacturerCategoryId=1003',
    'manufacturerCategoryId=1&manufacturerCategoryId=1003',
    'manufacturerCategoryId=1003&manufacturerCategoryId=1',
  ]) {
    await page.goto(`${baseUrl}/retailer/manufacturers/1?${query}`, { timeout: 45000 });
    await page.waitForTimeout(1200);
    await clickInStockTab(page);
    await page.waitForTimeout(2500);
    const count = await page.locator('a[href*="/retailer/products/"]').count().catch(() => 0);
    const empty = await page.locator('text=/No products found/i').count().catch(() => 0);
    console.log(query, 'products=', count, 'empty_msg=', empty, 'url=', page.url());
  }

  await context.close();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
