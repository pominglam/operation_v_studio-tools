const path = require('path');

async function main() {
  const plamod = require('./src/plamod');
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  const profileDir = String(
    process.env.PLAMOD_PERSISTENT_PROFILE_DIR || path.resolve(__dirname, '..', '.pw-user-data'),
  );
  const manufacturerId = '1';
  const tabLabel = 'In-Stock';

  // eslint-disable-next-line global-require
  const { chromium } = require('playwright');
  const context = await chromium.launchPersistentContext(profileDir, {
    timeout: 30_000,
    headless: true,
    viewport: { width: 1400, height: 900 },
    locale: 'en-CA',
    timezoneId: 'America/Toronto',
    userAgent:
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    args: ['--disable-blink-features=AutomationControlled'],
  });

  try {
    const page = await context.newPage();
    await plamod.ensureLoggedIn(page, baseUrl, context, 'tmp-discovery');
    await page.goto(`${baseUrl}/retailer/manufacturers/${manufacturerId}`, { timeout: 45_000 });
    await page.waitForTimeout(1200);

    const tabFn = plamod.clickExactManufacturerStatusTab || (async () => false);
    await tabFn(page, tabLabel);
    await plamod.clearAllManufacturerFilters(page);
    await page.waitForTimeout(1500);

    const brandItems = await plamod.scrapeManufacturerSidebarFilterItems(page, 'BRAND');
    console.log('BRAND without PMK', brandItems.length, brandItems.slice(0, 3));

    const catItems = await plamod.scrapeManufacturerSidebarFilterItems(page, 'CATEGORY');
    const pmk = catItems.find((i) => /plastic model kits/i.test(i.name));
    console.log('CATEGORY PMK', pmk);

    const withPmk = await plamod.ensureManufacturerPlasticModelKitsOnly(page);
    console.log('PMK selected', withPmk);
    await page.waitForTimeout(1000);
    const brandAfter = await plamod.scrapeManufacturerSidebarFilterItems(page, 'BRAND');
    console.log('BRAND after PMK', brandAfter.length);
  } finally {
    await context.close().catch(() => undefined);
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
