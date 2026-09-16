const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
  const baseUrl = 'https://plamod.com';
  const profileDir =
    process.env.PLAMOD_PERSISTENT_PROFILE_DIR || path.resolve(__dirname, '..', '.pw-user-data');
  const context = await chromium.launchPersistentContext(profileDir, {
    headless: true,
    viewport: { width: 1400, height: 900 },
  });
  const page = await context.newPage();
  await page.goto(`${baseUrl}/retailer/manufacturers/1`, { timeout: 45_000, waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  const info = await page.evaluate(() => {
    const norm = (value) => String(value || '').replace(/\s+/g, ' ').trim();
    const hits = [];
    document.querySelectorAll('button, a, [role="tab"], span, div, label').forEach((el) => {
      const text = norm(el.textContent);
      if (!/in-stock|in stock|preorder|pre-order/i.test(text) || text.length > 80) {
        return;
      }
      hits.push({
        tag: el.tagName,
        role: el.getAttribute('role'),
        cls: String(el.className || '').slice(0, 120),
        text: text.slice(0, 80),
        parent: el.parentElement
          ? `${el.parentElement.tagName}.${String(el.parentElement.className || '').slice(0, 80)}`
          : '',
        siblings: el.parentElement
          ? Array.from(el.parentElement.children).map(
              (child) => `${child.tagName}:${norm(child.textContent).slice(0, 48)}`,
            )
          : [],
      });
    });
    hits.sort((a, b) => a.text.length - b.text.length);
    return {
      url: window.location.href,
      title: document.title,
      hits: hits.slice(0, 30),
    };
  });
  console.log(JSON.stringify(info, null, 2));
  await context.close();
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
