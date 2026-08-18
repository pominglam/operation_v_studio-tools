const path = require('path');
const { chromium } = require('playwright');
const { ensureLoggedInQuick } = require('./src/plamod');
const { markInStockBlock, getInStockBlock, scrapeRetailerCartQuantities } = require('./src/plamod-restock-cart');

(async () => {
  const baseUrl = 'https://plamod.com';
  const profile = process.env.PLAMOD_PERSISTENT_PROFILE_DIR || path.resolve(__dirname, '.pw-user-data');
  const context = await chromium.launchPersistentContext(profile, { headless: true });
  const page = context.pages()[0] || (await context.newPage());
  await ensureLoggedInQuick(page, baseUrl, context);
  await page.goto(`${baseUrl}/retailer/products/5058815`, { waitUntil: 'domcontentloaded' });
  await markInStockBlock(page);
  const combo = getInStockBlock(page).locator('button[role="combobox"]').first();
  await combo.click();
  await page.getByRole('option', { name: '0', exact: true }).click();
  await page.waitForTimeout(800);
  await page.goto(`${baseUrl}/retailer/cart`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  const cart = await scrapeRetailerCartQuantities(page);
  console.log(JSON.stringify({ qty: cart['5058815'] || 0 }));
  await context.close();
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
