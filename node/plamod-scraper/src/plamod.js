const path = require('node:path');
const fs = require('node:fs');

function requiredEnv(name) {
  const v = process.env[name];
  if (!v || String(v).trim() === '') {
    throw new Error(`${name} is required`);
  }
  const s = String(v).trim();

  // When docker compose uses `env_file: { format: raw }`, quoted values may be
  // passed through *with the quotes included*. Normalize here so credentials
  // match what a user would type in the browser.
  if (s.length >= 2) {
    const first = s[0];
    const last = s[s.length - 1];
    const isMatchingQuote =
      (first === "'" && last === "'") ||
      (first === '"' && last === '"') ||
      (first === '`' && last === '`');
    if (isMatchingQuote) {
      return s.slice(1, -1);
    }
  }

  return s;
}

function storageRoot() {
  // Must be a path that is shared with the Laravel container.
  // We default to "storage/app/private" (matches Laravel's `local` disk root in this repo).
  return String(process.env.PLAMOD_STORAGE_ROOT || path.resolve(__dirname, '..', '..', '..', 'storage', 'app', 'private'));
}

function safeSkuDir(sku) {
  return sku.replace(/[^a-zA-Z0-9_-]/g, '_');
}

function nowStamp() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}${pad(d.getSeconds())}`;
}

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function debugRoot() {
  return path.join(storageRoot(), 'plamod', 'debug');
}

function cleanupPersistentProfileLocks(profileDir) {
  const candidates = [
    path.join(profileDir, 'SingletonLock'),
    path.join(profileDir, 'SingletonCookie'),
    path.join(profileDir, 'SingletonSocket'),
    `${profileDir}-lock`,
    path.join(profileDir, '.pw-user-data-lock'),
  ];
  for (const p of candidates) {
    try {
      if (fs.existsSync(p)) fs.rmSync(p, { force: true });
    } catch {
      // ignore
    }
  }
}

async function safeCloseContext(context) {
  if (!context) return;
  await Promise.race([
    context.close(),
    new Promise((resolve) => setTimeout(resolve, 5_000)),
  ]).catch(() => undefined);
}

async function writeDebugSnapshot(page, sku, tag = null) {
  const dir = path.join(debugRoot(), safeSkuDir(sku));
  ensureDir(dir);
  const stamp = nowStamp();
  const base = tag ? `${stamp}-${safeSkuDir(tag)}` : stamp;

  const metaAbs = path.join(dir, `${base}.json`);
  const meta = await Promise.all([
    Promise.resolve().then(() => page.url()).catch(() => null),
    page.title().catch(() => null),
  ]).then(([url, title]) => ({ url, title })).catch(() => ({ url: null, title: null }));
  fs.writeFileSync(metaAbs, JSON.stringify(meta, null, 2), { encoding: 'utf8' });

  const htmlAbs = path.join(dir, `${base}.html`);
  const pngAbs = path.join(dir, `${base}.png`);

  let html = await page.content().catch(() => null);
  if (html) {
    // Never persist plaintext passwords to disk (even in debug artifacts).
    html = String(html)
      .replace(/(name="password"[^>]*value=")[^"]*(")/gi, '$1***$2')
      .replace(/(type="password"[^>]*value=")[^"]*(")/gi, '$1***$2');
  }
  if (html) {
    fs.writeFileSync(htmlAbs, html, { encoding: 'utf8' });
  }

  await page.screenshot({ path: pngAbs, fullPage: true }).catch(() => undefined);

  return {
    debug_html_storage_path: path.posix.join('plamod', 'debug', safeSkuDir(sku), `${base}.html`),
    debug_png_storage_path: path.posix.join('plamod', 'debug', safeSkuDir(sku), `${base}.png`),
  };
}

async function gotoWithTimeout(page, url, timeoutMs) {
  try {
    return await page.goto(url, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
  } catch (e) {
    const msg = String(e?.message || '');
    // Plamod sometimes triggers a fast follow-up navigation which can abort the initial request.
    // Treat that as non-fatal and continue with whatever URL the page ended up on.
    if (msg.includes('net::ERR_ABORTED')) {
      await page.waitForLoadState('domcontentloaded', { timeout: timeoutMs }).catch(() => undefined);
      return null;
    }
    throw e;
  }
}

async function fillFirst(page, selectors, value) {
  for (const sel of selectors) {
    const el = await page.$(sel);
    if (!el) continue;
    await el.fill(value);
    return true;
  }
  return false;
}

async function clickFirst(page, selectors) {
  for (const sel of selectors) {
    const el = await page.$(sel);
    if (!el) continue;
    await el.click();
    return true;
  }
  return false;
}

async function findFirstHandle(page, selectors) {
  for (const sel of selectors) {
    const el = await page.$(sel);
    if (el) return el;
  }
  return null;
}

async function looksLikeSignInPage(page) {
  const url = page.url();
  if (url.includes('/retailer-sign-in')) return true;

  // Sometimes Plamod appears to serve the sign-in UI from other URLs (no redirect).
  // Detect by presence of the sign-in form inputs / heading.
  const hasCompany = await page.$('input[name="company"]').then((x) => !!x).catch(() => false);
  const hasUser = await page.$('input[name="username"]').then((x) => !!x).catch(() => false);
  const hasPass = await page.$('input[name="password"], input[type="password"]').then((x) => !!x).catch(() => false);
  if (hasCompany && hasUser && hasPass) return true;

  const h1 = await page.textContent('h1').catch(() => '');
  if (String(h1 || '').toLowerCase().includes('retailer sign in')) return true;

  return false;
}

async function extractInlineLoginError(page) {
  // The UI can show errors inline (e.g. "Sign In Failed" + "An error occurred. Please try again.")
  // Try a few strategies and keep it short.
  const bodyText = await page
    .evaluate(() => {
      const t = document?.body?.innerText || '';
      return String(t);
    })
    .catch(() => '');

  const lines = String(bodyText)
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter(Boolean);

  // Prefer the known phrasing when present.
  const idx = lines.findIndex((l) => /sign in failed/i.test(l));
  if (idx >= 0) {
    return lines.slice(idx, idx + 3).join(' | ').slice(0, 240);
  }

  const errLine = lines.find((l) => /error occurred|try again|failed/i.test(l));
  return errLine ? errLine.slice(0, 240) : null;
}

async function ensureLoggedIn(page, baseUrl, context, debugSku) {
  const company = requiredEnv('PLAMOD_COMPANY');
  const username = requiredEnv('PLAMOD_USERNAME');
  const password = requiredEnv('PLAMOD_PASSWORD');
  const forceLogin = String(process.env.PLAMOD_FORCE_LOGIN || 'false').toLowerCase() === 'true';

  // Best-effort: check if already authenticated by visiting a known retailer PDP.
  // NOTE: `/retailer/products` currently returns 404 on Plamod (at least in headless),
  // so we must probe a real PDP instead.
  const probeUrl = debugSku
    ? `${baseUrl}/retailer/products/${encodeURIComponent(debugSku)}`
    : `${baseUrl}/retailer-sign-in`;
  await gotoWithTimeout(page, probeUrl, 45_000);
  if (debugSku) {
    await writeDebugSnapshot(page, debugSku, 'auth-precheck-retailer-pdp').catch(() => undefined);
  }
  const preUrl = page.url();
  const preLooksAuthed =
    preUrl.includes('/retailer/products/') &&
    preUrl.includes(encodeURIComponent(debugSku || '')) &&
    !(await looksLikeSignInPage(page));
  if (!forceLogin && preLooksAuthed) {
    return;
  }

  // Login page
  await gotoWithTimeout(page, `${baseUrl}/retailer-sign-in`, 15_000);
  // Wait for the Next.js page bundle to load so the form is hydrated.
  await page
    .waitForResponse((r) => String(r.url()).includes('/_next/static/chunks/app/(public)/retailer-sign-in/page-') && r.status() === 200, {
      timeout: 15_000,
    })
    .catch(() => undefined);

  // Company field can be either:
  // - an input + "Save" button (first-time / not remembered), OR
  // - a combobox button already set to the company (remembered).
  let okCompany = await fillFirst(page, [
    'input[name="company"]',
    'input[name="company_code"]',
    'input[autocomplete="organization"]',
    'input[placeholder*="Company" i]',
  ], company);

  if (!okCompany) {
    const comboText = await page.textContent('button[role="combobox"]').then((v) => String(v || '').trim()).catch(() => '');
    if (comboText && comboText.toLowerCase().includes(company.toLowerCase())) {
      okCompany = true;
    }
  }

  // Snapshot after setting company (before Save, if any).
  if (debugSku) {
    await writeDebugSnapshot(page, debugSku, 'login-after-fill-company').catch(() => undefined);
  }

  // If a Save button exists, click it (some flows require this).
  const clickedSave = await clickFirst(page, [
    'button:has-text("Save")',
    'button:has-text("SAVE")',
  ]);
  if (clickedSave) {
    await page.waitForTimeout(1200);
    if (debugSku) {
      await writeDebugSnapshot(page, debugSku, 'login-after-click-save').catch(() => undefined);
    }
  }

  const okUser = await fillFirst(page, [
    'input[name="email"]',
    'input[name="username"]',
    'input[type="email"]',
    'input[autocomplete="username"]',
    'input[placeholder*="Email" i]',
    'input[placeholder*="Username" i]',
  ], username);

  const okPass = await fillFirst(page, [
    'input[name="password"]',
    'input[type="password"]',
    'input[autocomplete="current-password"]',
  ], password);
  if (debugSku) {
    await writeDebugSnapshot(page, debugSku, 'login-after-fill-user-pass').catch(() => undefined);
  }

  if (!okCompany || !okUser || !okPass) {
    throw new Error('Could not find all login fields on Plamod sign-in page');
  }

  // Sanity check: ensure our fills actually stuck (do NOT log values).
  // NOTE: after clicking "Save", Plamod may replace the company input with a combobox button.
  const companyLen = await page.inputValue('input[name="company"]').then((v) => v.trim().length).catch(() => 0);
  const companyComboText = await page.textContent('button[role="combobox"]').then((v) => String(v || '').trim()).catch(() => '');
  const companyOk = companyLen > 0 || (companyComboText.length > 0 && !/enter your company/i.test(companyComboText));

  const userLen = await page.inputValue('input[name="username"]').then((v) => v.trim().length).catch(() => 0);
  const userOk = userLen > 0;

  if (!companyOk || !userOk) {
    throw new Error('Plamod login failed: could not populate company/username fields.');
  }

  // Submit: prefer Enter on password field (React forms often bind submit there).
  const passInput = await page.$('input[name="password"], input[type="password"]');
  let clicked = false;
  if (passInput) {
    await passInput.press('Enter').catch(() => undefined);
    clicked = true;
  }
  if (!clicked) {
    clicked = await clickFirst(page, [
      'button[type="submit"]',
      'button:has-text("Sign in")',
      'button:has-text("Login")',
      'button:has-text("Sign In")',
    ]);
  }
  if (!clicked) {
    throw new Error('Could not find sign-in submit button on Plamod sign-in page');
  }

  // Wait for login POST + redirect into retailer area.
  await Promise.race([
    page.waitForURL((url) => String(url).includes('/retailer/') && !String(url).includes('retailer-sign-in'), {
      timeout: 25_000,
    }),
    page.waitForResponse(
      (r) => String(r.url()).includes('/retailer-sign-in') && r.request().method() === 'POST' && r.status() < 500,
      { timeout: 25_000 },
    ),
    page.waitForFunction(() => /sign in failed|an error occurred/i.test(document?.body?.innerText || ''), {
      timeout: 25_000,
    }),
  ]).catch(() => undefined);
  await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => undefined);
  await page.waitForTimeout(1500);

  if (debugSku) {
    await writeDebugSnapshot(page, debugSku, 'login-after-click-sign-in').catch(() => undefined);
  }

  // If the UI already shows an inline failure, throw NOW (so debug snapshot captures it).
  const immediateInlineError = await extractInlineLoginError(page);
  if (immediateInlineError && /sign in failed|error occurred|try again/i.test(immediateInlineError)) {
    const cookieCount = context
      ? await context.cookies().then((cs) => cs.length).catch(() => 0)
      : 0;
    throw new Error(`Plamod login failed: ${immediateInlineError} (url: ${page.url()}, cookies: ${cookieCount})`);
  }

  // Validate auth by hitting the retailer PDP probe and ensuring it doesn't show the sign-in UI.
  await gotoWithTimeout(page, probeUrl, 45_000);

  const postLoginUrl = page.url();
  const postLooksAuthed = postLoginUrl.includes('/retailer/') && !(await looksLikeSignInPage(page));
  if (!postLooksAuthed) {
    const inlineError = await extractInlineLoginError(page);
    const cookieCount = context
      ? await context.cookies().then((cs) => cs.length).catch(() => 0)
      : 0;

    throw new Error(
      inlineError
        ? `Plamod login failed: ${inlineError} (url: ${postLoginUrl}, cookies: ${cookieCount})`
        : `Plamod login failed: not in retailer area after submit (url: ${postLoginUrl}, cookies: ${cookieCount})`,
    );
  }
}

async function ensureLoggedInQuick(page, baseUrl, context) {
  const company = requiredEnv('PLAMOD_COMPANY');
  const username = requiredEnv('PLAMOD_USERNAME');
  const password = requiredEnv('PLAMOD_PASSWORD');
  const retailerSearchUrl = `${baseUrl}/retailer/search?tab=preorder`;

  await gotoWithTimeout(page, retailerSearchUrl, 30_000);
  await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => undefined);
  await page.waitForTimeout(500);
  if (!(await looksLikeSignInPage(page))) {
    return;
  }

  await gotoWithTimeout(page, `${baseUrl}/retailer-sign-in`, 15_000);
  await page
    .waitForResponse((r) => String(r.url()).includes('/_next/static/chunks/app/(public)/retailer-sign-in/page-') && r.status() === 200, {
      timeout: 15_000,
    })
    .catch(() => undefined);

  let okCompany = await fillFirst(page, [
    'input[name="company"]',
    'input[name="company_code"]',
    'input[autocomplete="organization"]',
    'input[placeholder*="Company" i]',
  ], company);

  if (!okCompany) {
    const comboText = await page.textContent('button[role="combobox"]').then((v) => String(v || '').trim()).catch(() => '');
    if (comboText && comboText.toLowerCase().includes(company.toLowerCase())) {
      okCompany = true;
    }
  }

  const clickedSave = await clickFirst(page, ['button:has-text("Save")', 'button:has-text("SAVE")']);
  if (clickedSave) {
    await page.waitForTimeout(800);
  }

  const okUser = await fillFirst(page, [
    'input[name="email"]',
    'input[name="username"]',
    'input[type="email"]',
    'input[autocomplete="username"]',
    'input[placeholder*="Email" i]',
    'input[placeholder*="Username" i]',
  ], username);

  const okPass = await fillFirst(page, [
    'input[name="password"]',
    'input[type="password"]',
    'input[autocomplete="current-password"]',
  ], password);

  if (!okCompany || !okUser || !okPass) {
    throw new Error('Could not find all login fields on Plamod sign-in page');
  }

  const passInput = await page.$('input[name="password"], input[type="password"]');
  if (passInput) {
    await passInput.press('Enter').catch(() => undefined);
  } else {
    await clickFirst(page, [
      'button[type="submit"]',
      'button:has-text("Sign in")',
      'button:has-text("Login")',
      'button:has-text("Sign In")',
    ]);
  }

  await Promise.race([
    page.waitForURL((url) => String(url).includes('/retailer/') && !String(url).includes('retailer-sign-in'), {
      timeout: 25_000,
    }),
    page.waitForResponse(
      (r) => String(r.url()).includes('/retailer-sign-in') && r.request().method() === 'POST' && r.status() < 500,
      { timeout: 25_000 },
    ),
  ]).catch(() => undefined);
  await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => undefined);
  await page.waitForTimeout(800);

  await gotoWithTimeout(page, retailerSearchUrl, 30_000);
  await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => undefined);
  if (await looksLikeSignInPage(page)) {
    const inlineError = await extractInlineLoginError(page);
    const cookieCount = context
      ? await context.cookies().then((cs) => cs.length).catch(() => 0)
      : 0;
    throw new Error(
      inlineError
        ? `Plamod login failed: ${inlineError} (url: ${page.url()}, cookies: ${cookieCount})`
        : `Plamod login failed: retailer search stayed on sign-in (url: ${page.url()}, cookies: ${cookieCount})`,
    );
  }
}

/** @type {{ context: any, page: any, lastUsed: number } | null} */
let warmSearchSession = null;
/** @type {Promise<void>} */
let searchSessionChain = Promise.resolve();

async function withSearchSessionMutex(fn) {
  const run = searchSessionChain.then(fn, fn);
  searchSessionChain = run.then(
    () => undefined,
    () => undefined,
  );
  return run;
}

async function acquireWarmSearchSession(baseUrl, profileDir) {
  if (warmSearchSession) {
    try {
      await warmSearchSession.page.evaluate(() => true);
      if (Date.now() - warmSearchSession.lastUsed < 20 * 60 * 1000) {
        warmSearchSession.lastUsed = Date.now();
        return warmSearchSession;
      }
    } catch {
      await safeCloseContext(warmSearchSession.context).catch(() => undefined);
      warmSearchSession = null;
    }
  }

  // eslint-disable-next-line global-require
  const { chromium } = require('playwright');
  cleanupPersistentProfileLocks(profileDir);
  const context = await chromium.launchPersistentContext(profileDir, {
    timeout: 30_000,
    headless: true,
    acceptDownloads: true,
    viewport: { width: 1400, height: 900 },
    locale: 'en-CA',
    timezoneId: 'America/Toronto',
    userAgent:
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    args: ['--disable-blink-features=AutomationControlled'],
  });
  const page = await context.newPage();
  await ensureLoggedInQuick(page, baseUrl, context);
  warmSearchSession = { context, page, lastUsed: Date.now() };
  return warmSearchSession;
}

/** @type {{ context: any, page: any, lastUsed: number, manufacturerId: string } | null} */
let warmManufacturerSession = null;
/** @type {Promise<void>} */
let manufacturerSessionChain = Promise.resolve();

async function withManufacturerSessionMutex(fn) {
  const run = manufacturerSessionChain.then(fn, fn);
  manufacturerSessionChain = run.then(
    () => undefined,
    () => undefined,
  );
  return run;
}

async function closeWarmManufacturerSession() {
  if (!warmManufacturerSession) {
    return;
  }
  await safeCloseContext(warmManufacturerSession.context).catch(() => undefined);
  warmManufacturerSession = null;
}

async function acquireWarmManufacturerSession(baseUrl, profileDir, manufacturerId) {
  const id = String(manufacturerId ?? 1).trim();
  if (warmManufacturerSession) {
    try {
      await warmManufacturerSession.page.evaluate(() => true);
      if (
        Date.now() - warmManufacturerSession.lastUsed < 20 * 60 * 1000 &&
        warmManufacturerSession.manufacturerId === id
      ) {
        warmManufacturerSession.lastUsed = Date.now();
        return warmManufacturerSession;
      }
    } catch {
      await closeWarmManufacturerSession();
    }
  }

  // eslint-disable-next-line global-require
  const { chromium } = require('playwright');
  cleanupPersistentProfileLocks(profileDir);
  const context = await chromium.launchPersistentContext(profileDir, {
    timeout: 30_000,
    headless: true,
    acceptDownloads: true,
    viewport: { width: 1400, height: 900 },
    locale: 'en-CA',
    timezoneId: 'America/Toronto',
    userAgent:
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    args: ['--disable-blink-features=AutomationControlled'],
  });
  const page = await context.newPage();
  await ensureLoggedInQuick(page, baseUrl, context);
  warmManufacturerSession = { context, page, lastUsed: Date.now(), manufacturerId: id };
  return warmManufacturerSession;
}

function retailerPdpSkuFromUrl(url) {
  const match = String(url || '').match(/\/retailer\/products\/([^/?#]+)/i);
  if (!match?.[1]) {
    return '';
  }
  try {
    return decodeURIComponent(match[1]).trim();
  } catch {
    return String(match[1]).trim();
  }
}

function normalizePlamodSku(value) {
  return String(value || '').trim();
}

function isOnRetailerPdpForSku(url, sku) {
  const current = normalizePlamodSku(retailerPdpSkuFromUrl(url));
  const expected = normalizePlamodSku(sku);
  if (current === '' || expected === '') {
    return false;
  }

  return current.toLowerCase() === expected.toLowerCase();
}

async function ensureOnRetailerPdp(page, baseUrl, sku, context) {
  if (isOnRetailerPdpForSku(page.url(), sku)) {
    return;
  }

  if (page.url().includes('/retailer-sign-in')) {
    await ensureLoggedIn(page, baseUrl, context, sku);
  }

  const pdpUrl = `${baseUrl}/retailer/products/${encodeURIComponent(sku)}`;
  await gotoWithTimeout(page, pdpUrl, 20_000);

  if (page.url().includes('/retailer-sign-in')) {
    throw new Error('Plamod login failed: retailer PDP redirected back to sign-in.');
  }

  if (!isOnRetailerPdpForSku(page.url(), sku)) {
    throw new Error(`Plamod PDP navigation mismatch: expected sku=${sku} url=${page.url()}`);
  }
}

async function extractMetadata(page) {
  // Keep it best-effort and resilient to markup changes.
  const title =
    (await page.textContent('h1').catch(() => null)) ||
    (await page.textContent('[data-testid="product-title"]').catch(() => null)) ||
    null;

  const description =
    (await page.innerHTML('[data-testid="product-description"]').catch(() => null)) ||
    (await page.innerHTML('.product-description').catch(() => null)) ||
    null;

  // Attributes table: try common patterns.
  const attributes = {};

  const rowsRaw = await page.$$('[data-testid="product-attributes"] tr, table tr').catch(() => []);
  const rows = Array.isArray(rowsRaw) ? rowsRaw.slice(0, 80) : [];
  for (const row of rows) {
    const tds = await row.$$('th, td');
    if (!tds || tds.length < 2) continue;
    const k = (await tds[0].innerText().catch(() => '')).trim();
    const v = (await tds[1].innerText().catch(() => '')).trim();
    if (!k || !v) continue;
    if (k.length > 120 || v.length > 5000) continue;
    attributes[k] = v;
  }

  return {
    title: title ? String(title).trim() : null,
    description_html: description ? String(description).trim() : null,
    attributes,
  };
}

async function downloadPlamodZipForSku({ sku }) {
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  const profileDir = String(process.env.PLAMOD_PERSISTENT_PROFILE_DIR || path.resolve(__dirname, '..', '..', '.pw-user-data'));
  const pdpUrl = `${baseUrl}/retailer/products/${encodeURIComponent(sku)}`;

  const root = storageRoot();
  const rawDir = path.join(root, 'plamod', 'raw_zips', safeSkuDir(sku));
  ensureDir(rawDir);
  const zipFilename = `${nowStamp()}.zip`;
  const zipPath = path.join(rawDir, zipFilename);
  const zipStoragePath = path.posix.join('plamod', 'raw_zips', safeSkuDir(sku), zipFilename);

  // Require Playwright only at runtime (keeps tests lighter if needed).
  // eslint-disable-next-line global-require
  const { chromium } = require('playwright');

  const started = Date.now();

  cleanupPersistentProfileLocks(profileDir);

  // eslint-disable-next-line no-console
  console.log(`[plamod] playwright launchPersistentContext sku=${sku}`);
  const context = await chromium.launchPersistentContext(profileDir, {
    timeout: 30_000,
    headless: true,
    acceptDownloads: true,
    viewport: { width: 1400, height: 900 },
    locale: 'en-CA',
    timezoneId: 'America/Toronto',
    userAgent:
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    args: ['--disable-blink-features=AutomationControlled'],
  });

  try {
    // Best-effort: reduce naive bot detection that checks navigator.webdriver.
    await context.addInitScript(() => {
      // eslint-disable-next-line no-undef
      Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
    });

    // eslint-disable-next-line no-console
    console.log(`[plamod] newPage sku=${sku}`);
    const page = await context.newPage();
    const netlog = { responses: [], request_failed: [], console: [] };
    const pushBounded = (arr, item, max = 30) => {
      arr.push(item);
      if (arr.length > max) arr.splice(0, arr.length - max);
    };
    page.on('response', (res) => {
      const url = String(res.url() || '');
      if (!url.startsWith('http')) return;
      const req = res.request();
      const u = new URL(url);
      pushBounded(netlog.responses, {
        method: req.method(),
        status: res.status(),
        url: `${u.origin}${u.pathname}`,
      });
    });
    page.on('requestfailed', (req) => {
      const url = String(req.url() || '');
      if (!url.startsWith('http')) return;
      const failure = req.failure();
      const u = new URL(url);
      pushBounded(netlog.request_failed, {
        method: req.method(),
        url: `${u.origin}${u.pathname}`,
        error_text: failure?.errorText || null,
      });
    });
    page.on('console', (msg) => {
      const type = msg.type();
      if (type === 'log') return;
      pushBounded(netlog.console, {
        type,
        text: msg.text(),
      });
    });

    try {
      try {
        await ensureLoggedIn(page, baseUrl, context, sku);
      } catch (e) {
        const debug = await writeDebugSnapshot(page, sku);
        return {
          ok: false,
          error_message: e?.message || 'Plamod login failed',
          debug: {
            current_url: page.url(),
            title: await page.title().catch(() => ''),
            on_sign_in: page.url().includes('/retailer-sign-in'),
            login_error: await extractInlineLoginError(page).catch(() => null),
            netlog,
            ...debug,
          },
          duration_ms: Date.now() - started,
        };
      }

      // eslint-disable-next-line no-console
      console.log(`[plamod] logged-in sku=${sku} url=${page.url()}`);

      // eslint-disable-next-line no-console
      console.log(`[plamod] goto pdp sku=${sku}`);
      await gotoWithTimeout(page, pdpUrl, 20_000);
      try {
        await ensureOnRetailerPdp(page, baseUrl, sku, context);
      } catch (e) {
        const cookieNames = await context.cookies().then((cs) => cs.map((c) => c.name)).catch(() => []);
        const localStorageKeys = await page
          .evaluate(() => {
            try {
              return Object.keys(localStorage || {});
            } catch {
              return [];
            }
          })
          .catch(() => []);
        const debug = await writeDebugSnapshot(page, sku);
        return {
          ok: false,
          error_message: e?.message || 'Plamod navigation failed',
          debug: {
            current_url: page.url(),
            title: await page.title().catch(() => ''),
            on_sign_in: page.url().includes('/retailer-sign-in'),
            cookie_names: cookieNames.slice(0, 50),
            local_storage_keys: Array.isArray(localStorageKeys) ? localStorageKeys.slice(0, 50) : [],
            login_error: await extractInlineLoginError(page).catch(() => null),
            netlog,
            ...debug,
          },
          duration_ms: Date.now() - started,
        };
      }
      // eslint-disable-next-line no-console
      console.log(`[plamod] on pdp sku=${sku} url=${page.url()}`);
    } catch (e) {
      // Catch-all: ensure we still return structured debug instead of crashing the service.
      const debug = await writeDebugSnapshot(page, sku, 'unexpected-error').catch(() => ({}));
      return {
        ok: false,
        error_message: e?.message || 'Plamod scraper unexpected error',
        debug: {
          current_url: page.url(),
          title: await page.title().catch(() => ''),
          on_sign_in: page.url().includes('/retailer-sign-in'),
          login_error: await extractInlineLoginError(page).catch(() => null),
          netlog,
          ...(debug || {}),
        },
        duration_ms: Date.now() - started,
      };
    }

    // eslint-disable-next-line no-console
    console.log(`[plamod] extract metadata sku=${sku}`);
    const metadata = await Promise.race([
      extractMetadata(page),
      new Promise((resolve) => setTimeout(() => resolve({ title: null, description_html: null, attributes: {} }), 12_000)),
    ]);
    // eslint-disable-next-line no-console
    console.log(`[plamod] metadata done sku=${sku}`);

    // Find and click download zip.
    // Product page may lazy-render; wait briefly for the download CTA.
    await page.waitForTimeout(800);
    // eslint-disable-next-line no-console
    console.log(`[plamod] find download handle sku=${sku}`);
    const downloadHandle = await findFirstHandle(page, [
      'a:has-text("Download ZIP")',
      'a:has-text("Download Zip")',
      'a:has-text("Download zip")',
      'button:has-text("Download ZIP")',
      'button:has-text("Download Zip")',
      'button:has-text("Download zip")',
      '[data-testid="download-zip"]',
      'a[href*=".zip" i]',
      'text=/download\\s*zip/i',
    ]);

    if (!downloadHandle) {
      const debug = await writeDebugSnapshot(page, sku);
      const currentUrl = page.url();
      const title = await page.title().catch(() => '');
      const onSignIn = currentUrl.includes('/retailer-sign-in');

      return {
        ok: false,
        error_message: 'Could not find "Download ZIP" button/link on Plamod PDP',
        debug: {
          current_url: currentUrl,
          title,
          on_sign_in: onSignIn,
          ...debug,
        },
        duration_ms: Date.now() - started,
      };
    }

    async function attemptDownload(attemptNo) {
      // Use Promise.all so *both* the click and download event are always awaited/handled.
      // This prevents unhandled rejections if the click throws after the download wait starts.
      // eslint-disable-next-line no-console
      console.log(`[plamod] click download zip sku=${sku} attempt=${attemptNo}`);
      const [download] = await Promise.all([
        page.waitForEvent('download', { timeout: 60_000 }),
        downloadHandle.click(),
      ]);
      // eslint-disable-next-line no-console
      console.log(`[plamod] download started sku=${sku} attempt=${attemptNo} suggested=${download.suggestedFilename()}`);
      return download;
    }

    let download;
    try {
      download = await attemptDownload(1);
    } catch (e) {
      // If the first click didn't produce a download event, retry once. This is commonly flaky on Plamod.
      const msg = String(e?.message || '');
      if (!/timeout/i.test(msg)) {
        throw e;
      }
      // eslint-disable-next-line no-console
      console.log(`[plamod] download event timeout; retrying sku=${sku}`);
      await page.waitForTimeout(800);
      download = await attemptDownload(2);
    }

    await Promise.race([
      download.saveAs(zipPath),
      new Promise((_, reject) => setTimeout(() => reject(new Error('download_save_timeout')), 60_000)),
    ]);

    const failure = await download.failure().catch(() => null);
    if (failure) {
      const debug = await writeDebugSnapshot(page, sku, 'download-failed');
      return {
        ok: false,
        error_message: `Download failed: ${failure}`,
        debug: {
          current_url: page.url(),
          title: await page.title().catch(() => ''),
          on_sign_in: page.url().includes('/retailer-sign-in'),
          ...debug,
        },
        duration_ms: Date.now() - started,
      };
    }

    // eslint-disable-next-line no-console
    console.log(`[plamod] download saved sku=${sku} path=${zipStoragePath}`);

    return {
      ok: true,
      sku,
      pdp_url: pdpUrl,
      zip_storage_path: zipStoragePath,
      metadata,
      duration_ms: Date.now() - started,
    };
  } finally {
    await safeCloseContext(context);
  }
}

function parseSimpleCsvFile(csvPath) {
  const raw = fs.readFileSync(csvPath, 'utf8');
  const lines = raw.split(/\r?\n/).filter((line) => line.trim() !== '');
  if (lines.length === 0) {
    return { header: [], rows: new Map() };
  }

  const parseLine = (line) => {
    /** @type {string[]} */
    const out = [];
    let cur = '';
    let inQuotes = false;
    for (let i = 0; i < line.length; i += 1) {
      const ch = line[i];
      if (ch === '"') {
        inQuotes = !inQuotes;
        continue;
      }
      if (ch === ',' && !inQuotes) {
        out.push(cur);
        cur = '';
        continue;
      }
      cur += ch;
    }
    out.push(cur);
    return out;
  };

  const header = parseLine(lines[0]);
  /** @type {Map<string, string[]>} */
  const rows = new Map();
  for (let i = 1; i < lines.length; i += 1) {
    const cols = parseLine(lines[i]);
    const sku = String(cols[0] || '').trim();
    if (sku !== '') {
      rows.set(sku, cols);
    }
  }

  return { header, rows };
}

function writeSimpleCsvFile(csvPath, header, rowsMap) {
  const esc = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`;
  const lines = [header.map(esc).join(',')];
  for (const cols of rowsMap.values()) {
    lines.push(cols.map(esc).join(','));
  }
  fs.writeFileSync(csvPath, `${lines.join('\n')}\n`, 'utf8');
}

function supplementCsvRowsFromVisibleGrid(page, header, rowsMap) {
  return page.evaluate(
    ({ headerColumns }) => {
      const norm = (value) => String(value || '').replace(/\s+/g, ' ').trim();
      const skuFromHref = (href) => {
        const clean = String(href || '').split('?')[0];
        const parts = clean.split('/').filter(Boolean);
        const sku = parts[parts.length - 1] || '';
        return /^[0-9A-Za-z_-]+$/.test(sku) ? sku : '';
      };
      const cardText = (anchor) => {
        const title = norm(anchor.textContent || '');
        if (title.length >= 4) {
          return title;
        }
        const row =
          anchor.closest('tr, li, article, [class*="product"], [class*="result"], [class*="row"]') ||
          anchor.parentElement;
        return norm(row?.textContent || '').slice(0, 240);
      };

      /** @type {Array<{sku: string, product_name: string, image_url: string}>} */
      const hits = [];
      const seen = new Set();
      document
        .querySelectorAll('h3 a[href*="/retailer/products/"], a[href*="/retailer/products/"], a[href*="/products/"]')
        .forEach((anchor) => {
          const href = anchor.getAttribute('href') || '';
          const sku = skuFromHref(href);
          const productName = cardText(anchor);
          if (!sku || !productName || seen.has(sku)) {
            return;
          }
          const card =
            anchor.closest('tr, li, article, [class*="product"], [class*="result"], [class*="row"]') ||
            anchor.parentElement;
          const imageUrl = norm(
            card?.querySelector('img[src*="plamod"], img[src*="images.plamod"]')?.getAttribute('src') || '',
          );
          seen.add(sku);
          hits.push({ sku, product_name: productName, image_url: imageUrl });
        });

      return hits.map((hit) =>
        headerColumns.map((name) => {
          const column = String(name || '').trim().toLowerCase();
          if (column === 'sku') {
            return hit.sku;
          }
          if (column === 'product name') {
            return hit.product_name;
          }
          if (column === 'image url') {
            return hit.image_url;
          }
          return '';
        }),
      );
    },
    { headerColumns: header },
  );
}

async function scrollLoadPreorderGrid(page) {
  let prevVisible = 0;
  for (let i = 0; i < 40; i += 1) {
    await page.evaluate(() => {
      window.scrollTo(0, document.body.scrollHeight);
      const nodes = document.querySelectorAll('[data-radix-scroll-area-viewport], .overflow-auto, main');
      nodes.forEach((node) => {
        if (node instanceof HTMLElement) {
          node.scrollTop = node.scrollHeight;
        }
      });
    });
    await page.waitForTimeout(700);
    const visible = await page
      .evaluate(() => document.querySelectorAll('a[href*="/retailer/products/"]').length)
      .catch(() => 0);
    if (visible > 0 && visible === prevVisible && i > 6) {
      break;
    }
    prevVisible = visible;
  }

  await page.evaluate(() => window.scrollTo(0, 0)).catch(() => undefined);
  await page.waitForTimeout(500);
  return prevVisible;
}

async function clickExactManufacturerStatusTab(page, tabLabel) {
  const clicked = await page
    .evaluate((label) => {
      const norm = (value) => String(value || '').replace(/\s+/g, ' ').trim();
      const buttons = Array.from(document.querySelectorAll('button'));
      const hit = buttons.find((button) => {
        const spans = Array.from(button.querySelectorAll('span'));
        if (spans.some((span) => norm(span.textContent) === label)) {
          return true;
        }
        return norm(button.textContent) === label;
      });
      if (!hit) {
        return false;
      }
      hit.click();
      return true;
    }, tabLabel)
    .catch(() => false);

  if (!clicked) {
    return false;
  }

  await page.waitForLoadState('networkidle', { timeout: 45_000 }).catch(() => undefined);
  await page.waitForTimeout(1500);
  return true;
}

/** Lighter tab click for sidebar filter discovery (no networkidle wait). */
async function clickExactManufacturerStatusTabForFilters(page, tabLabel) {
  const clicked = await page
    .evaluate((label) => {
      const norm = (value) => String(value || '').replace(/\s+/g, ' ').trim();
      const buttons = Array.from(document.querySelectorAll('button'));
      const hit = buttons.find((button) => {
        const spans = Array.from(button.querySelectorAll('span'));
        if (spans.some((span) => norm(span.textContent) === label)) {
          return true;
        }
        return norm(button.textContent) === label;
      });
      if (!hit) {
        return false;
      }
      hit.click();
      return true;
    }, tabLabel)
    .catch(() => false);

  if (!clicked) {
    return false;
  }

  await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => undefined);
  await page.waitForTimeout(1000);
  return true;
}

async function readManufacturerTabBadge(page, tabLabel) {
  return page
    .evaluate((label) => {
      const norm = (value) => String(value || '').replace(/\s+/g, ' ').trim();
      const buttons = Array.from(document.querySelectorAll('button'));
      const hit = buttons.find((button) => {
        const spans = Array.from(button.querySelectorAll('span'));
        return spans.some((span) => norm(span.textContent) === label) || norm(button.textContent).startsWith(label);
      });
      return hit ? norm(hit.textContent) : null;
    }, tabLabel)
    .catch(() => null);
}

const TIER_2B_CATEGORY_LINES = ['SD Cross Silhouette', 'SD G Generation', 'SD EX-Standard', 'SD BB'];

function slugFilterName(value) {
  return String(value || '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 48);
}

function normalizeManufacturerFilterName(value) {
  return String(value || '')
    .replace(/\s+/g, ' ')
    .replace(/\s*\/\s*/g, ' / ')
    .replace(/\s+\d+(?:\s+\d+)?\s*$/u, '')
    .trim()
    .toLowerCase();
}

async function clearAllManufacturerFilters(page) {
  for (const selector of [
    'button:has-text("Clear Categories")',
    'button:has-text("Clear Category")',
    'button:has-text("Clear Series")',
    'button:has-text("Clear Filters")',
    'button:has-text("Clear Filter")',
  ]) {
    const clicked = await clickFirst(page, [selector]);
    if (clicked) {
      await page.waitForTimeout(500);
    }
  }
}

async function resetManufacturerSidebarScroll(page) {
  await page
    .evaluate(() => {
      document.querySelectorAll('[data-radix-scroll-area-viewport]').forEach((node) => {
        if (node instanceof HTMLElement) {
          node.scrollTop = 0;
        }
      });
    })
    .catch(() => undefined);
}

async function scrollManufacturerSidebarStep(page) {
  await page
    .evaluate(() => {
      document.querySelectorAll('[data-radix-scroll-area-viewport]').forEach((node) => {
        if (node instanceof HTMLElement) {
          node.scrollTop += 480;
        }
      });
    })
    .catch(() => undefined);
  await page.waitForTimeout(220);
}

async function findAndSelectManufacturerFilter(page, filterTab, targetName) {
  const normalizedTarget = normalizeManufacturerFilterName(targetName);
  if (!normalizedTarget) {
    return false;
  }

  const tabClicked = await clickManufacturerSidebarFilterTab(page, filterTab);
  if (!tabClicked) {
    return false;
  }

  await resetManufacturerSidebarScroll(page);
  await page.waitForTimeout(400);

  const idPrefix = filterTab === 'SERIES' ? 'series-' : 'category-';

  for (let round = 0; round < 52; round += 1) {
    const selected = await page
      .evaluate(({ normalizedTarget, idPrefix }) => {
        const norm = (value) =>
          String(value || '')
            .replace(/\s+/g, ' ')
            .replace(/\s*\/\s*/g, ' / ')
            .replace(/\s+\d+(?:\s+\d+)?\s*$/u, '')
            .trim()
            .toLowerCase();

        const clickFilterControl = (control) => {
          if (!(control instanceof HTMLElement)) {
            return false;
          }
          const buttonId = control.getAttribute('for');
          const button = buttonId ? document.getElementById(buttonId) : null;
          if (button instanceof HTMLElement) {
            button.click();
            return true;
          }
          control.click();
          return true;
        };

        const labels = Array.from(document.querySelectorAll(`label[for^="${idPrefix}"]`));
        for (const label of labels) {
          const candidates = [
            norm(label.getAttribute('title') || ''),
            norm(label.textContent || ''),
          ].filter(Boolean);
          if (candidates.some((candidate) => candidate === normalizedTarget)) {
            return clickFilterControl(label);
          }
        }

        const buttons = Array.from(document.querySelectorAll(`button[id^="${idPrefix}"]`));
        for (const button of buttons) {
          const candidate = norm(button.id.replace(new RegExp(`^${idPrefix}`), ''));
          if (candidate === normalizedTarget && button instanceof HTMLElement) {
            button.click();
            return true;
          }
        }

        const checkboxRows = Array.from(document.querySelectorAll('input[type="checkbox"]'));
        for (const checkbox of checkboxRows) {
          const row = checkbox.closest('.flex.items-center.space-x-2, .flex.items-center.justify-between, label');
          if (!row) {
            continue;
          }
          const label = row.querySelector('label[title], span[title], label, span.text-sm');
          const candidates = [
            norm(label?.getAttribute('title') || ''),
            norm(label?.textContent || row.textContent || ''),
          ].filter(Boolean);
          if (!candidates.some((candidate) => candidate === normalizedTarget)) {
            continue;
          }
          if (checkbox instanceof HTMLInputElement && !checkbox.checked) {
            checkbox.click();
          }
          return true;
        }

        return false;
      }, { normalizedTarget, idPrefix })
      .catch(() => false);

    if (selected) {
      await page.keyboard.press('Escape').catch(() => undefined);
      await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => undefined);
      await page.waitForTimeout(800);
      return true;
    }

    await scrollManufacturerSidebarStep(page);
  }

  return false;
}

async function ensureManufacturerCategoryLine(page, categoryName) {
  return findAndSelectManufacturerFilter(page, 'CATEGORY', categoryName);
}

async function ensureManufacturerSeries(page, seriesName) {
  return findAndSelectManufacturerFilter(page, 'SERIES', seriesName);
}

async function ensureManufacturerPlasticModelKitsOnly(page) {
  return ensureManufacturerCategoryLine(page, 'Plastic Model Kits');
}

async function clickManufacturerSidebarFilterTab(page, tabLabel) {
  const clicked = await page
    .evaluate((label) => {
      const norm = (value) => String(value || '').replace(/\s+/g, ' ').trim();
      const buttons = Array.from(document.querySelectorAll('button'));
      const sidebarButtons = buttons.filter((button) => {
        const text = norm(button.textContent);
        return text === 'CATEGORY' || text === 'SERIES';
      });
      const hit = sidebarButtons.find((button) => norm(button.textContent) === label);
      if (!hit) {
        return false;
      }
      hit.click();
      return true;
    }, tabLabel)
    .catch(() => false);

  if (!clicked) {
    return false;
  }

  await page.waitForTimeout(1000);
  return true;
}

function attachManufacturerFilterPayloadCapture(page) {
  /** @type {string[]} */
  const payloads = [];

  page.on('response', async (response) => {
    const url = response.url();
    if (!/plamod\.com/i.test(url)) {
      return;
    }

    const contentType = response.headers()['content-type'] || '';
    if (!contentType.includes('json') && !contentType.includes('text/x-component') && !contentType.includes('text/plain')) {
      return;
    }

    try {
      const body = await response.text();
      if (body.includes('"series":[{"id"') || body.includes('"categories":[{"id"')) {
        payloads.push(body);
      }
    } catch {
      // ignore read failures
    }
  });

  return payloads;
}

async function scrollManufacturerSidebarFilters(page) {
  for (let i = 0; i < 48; i += 1) {
    await page
      .evaluate(() => {
        document.querySelectorAll('[data-radix-scroll-area-viewport]').forEach((node) => {
          if (node instanceof HTMLElement) {
            node.scrollTop += 520;
          }
        });
      })
      .catch(() => undefined);
    await page.waitForTimeout(220);
  }
}

function parseEmbeddedManufacturerFilterItemsFromText(text, filterKey) {
  const norm = (value) => String(value || '').replace(/\s+/g, ' ').trim();
  const body = String(text || '');
  const anchor = body.indexOf(`"${filterKey}":[{"id"`);
  if (anchor < 0) {
    return [];
  }

  const slice = body.slice(anchor, anchor + 80_000);
  const re = /\{"id":(\d+),"name":"((?:\\.|[^"\\])*)"/g;
  /** @type {Array<{name: string, preorder_count: number|null, other_count: number|null}>} */
  const out = [];
  const seen = new Set();
  let match = re.exec(slice);
  while (match) {
    const name = norm(String(match[2] || '').replace(/\\"/g, '"'));
    if (name && !seen.has(name)) {
      seen.add(name);
      out.push({ name, preorder_count: null, other_count: null });
    }
    match = re.exec(slice);
  }
  return out;
}

async function extractManufacturerFilterItemsFromResponses(page, filterKey) {
  return page
    .evaluate((key) => {
      const norm = (value) => String(value || '').replace(/\s+/g, ' ').trim();
      const parseFromText = (text) => {
        const body = String(text || '');
        const anchor = body.indexOf(`"${key}":[{"id"`);
        if (anchor < 0) {
          return [];
        }
        const slice = body.slice(anchor, anchor + 80_000);
        const re = /\{"id":(\d+),"name":"((?:\\.|[^"\\])*)"/g;
        /** @type {Array<{name: string, preorder_count: number|null, other_count: number|null}>} */
        const out = [];
        const seen = new Set();
        let match = re.exec(slice);
        while (match) {
          const name = norm(String(match[2] || '').replace(/\\"/g, '"'));
          if (name && !seen.has(name)) {
            seen.add(name);
            out.push({ name, preorder_count: null, other_count: null });
          }
          match = re.exec(slice);
        }
        return out;
      };

      /** @type {Array<{name: string, preorder_count: number|null, other_count: number|null}>} */
      const merged = [];
      const seen = new Set();
      const add = (items) => {
        for (const item of items) {
          const name = norm(item?.name || '');
          if (!name || seen.has(name)) {
            continue;
          }
          seen.add(name);
          merged.push(item);
        }
      };

      add(parseFromText(document.documentElement?.outerHTML || ''));
      for (const script of Array.from(document.querySelectorAll('script'))) {
        add(parseFromText(script.textContent || ''));
      }
      return merged;
    }, filterKey)
    .catch(() => []);
}

async function extractEmbeddedManufacturerFilterItems(page, filterKey) {
  return page
    .evaluate((key) => {
      const norm = (value) => String(value || '').replace(/\s+/g, ' ').trim();
      const html = document.documentElement?.outerHTML || '';
      const anchor = html.indexOf(`"${key}":[{"id"`);
      if (anchor < 0) {
        return [];
      }

      const slice = html.slice(anchor, anchor + 80_000);
      const re = /\{"id":(\d+),"name":"((?:\\.|[^"\\])*)"/g;
      /** @type {Array<{name: string, preorder_count: number|null, other_count: number|null}>} */
      const out = [];
      const seen = new Set();
      let match = re.exec(slice);
      while (match) {
        const name = norm(String(match[2] || '').replace(/\\"/g, '"'));
        if (name && !seen.has(name)) {
          seen.add(name);
          out.push({ name, preorder_count: null, other_count: null });
        }
        match = re.exec(slice);
      }
      return out;
    }, filterKey)
    .catch(() => []);
}

function mergeManufacturerFilterItems(primary, secondary) {
  const countsByName = new Map(
    (secondary || []).map((item) => [String(item.name || '').trim(), item]),
  );
  const merged = (primary || []).map((item) => {
    const hit = countsByName.get(String(item.name || '').trim());
    if (!hit) {
      return item;
    }
    return {
      name: item.name,
      preorder_count: hit.preorder_count ?? item.preorder_count ?? null,
      other_count: hit.other_count ?? item.other_count ?? null,
    };
  });
  const seen = new Set(merged.map((item) => item.name));
  for (const item of secondary || []) {
    const name = String(item.name || '').trim();
    if (name && !seen.has(name)) {
      merged.push(item);
      seen.add(name);
    }
  }
  return merged;
}

async function scrapeManufacturerSidebarFilterItems(page, filterTab) {
  const tabClicked = await clickManufacturerSidebarFilterTab(page, filterTab);
  if (!tabClicked) {
    return [];
  }

  await scrollManufacturerSidebarFilters(page);

  await page.waitForTimeout(500);

  return page
    .evaluate(() => {
      const norm = (value) => String(value || '').replace(/\s+/g, ' ').trim();
      /** @type {Array<{name: string, preorder_count: number|null, other_count: number|null}>} */
      const out = [];
      const seen = new Set();

      const parseBadgeNumbers = (row) => {
        if (!row) {
          return { preorder_count: null, other_count: null };
        }
        const badges = Array.from(row.querySelectorAll('div.inline-flex, span.rounded-full, span[class*="badge"]'))
          .map((node) => norm(node.textContent || ''))
          .filter((text) => /^\d+$/.test(text))
          .map((text) => Number.parseInt(text, 10));
        return {
          preorder_count: Number.isFinite(badges[0]) ? badges[0] : null,
          other_count: Number.isFinite(badges[1]) ? badges[1] : null,
        };
      };

      const ingestSidebarRow = (rowRoot) => {
        const sidebar = rowRoot?.closest('.overflow-y-auto');
        if (!sidebar) {
          return;
        }

        const label = rowRoot.querySelector('label[title], label[for^="series-"], label[for^="category-"]');
        const span = rowRoot.querySelector('span[title], span.text-sm');
        let name = norm(label?.getAttribute('title') || span?.getAttribute('title') || label?.textContent || span?.textContent || '');
        name = name.replace(/\s+\d+(?:\s+\d+)?\s*$/u, '').trim();
        if (!name || seen.has(name)) {
          return;
        }
        seen.add(name);

        const rowWrap = rowRoot.closest('.flex.items-center.justify-between') || rowRoot.parentElement;
        out.push({ name, ...parseBadgeNumbers(rowWrap) });
      };

      document.querySelectorAll('label[for^="series-"], label[for^="category-"]').forEach((label) => {
        ingestSidebarRow(label.closest('.flex.items-center') || label.parentElement);
      });

      document.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
        ingestSidebarRow(checkbox.closest('.flex.items-center.space-x-2') || checkbox.parentElement);
      });

      document.querySelectorAll('label').forEach((label) => {
        if (!(label instanceof HTMLLabelElement)) {
          return;
        }
        const checkbox = label.querySelector('input[type="checkbox"]');
        if (!checkbox) {
          return;
        }
        const badgeWrap = label.cloneNode(true);
        badgeWrap.querySelectorAll('input').forEach((node) => node.remove());
        let name = norm(badgeWrap.textContent || '');
        name = name.replace(/\s+\d+(?:\s+\d+)?\s*$/u, '').trim();
        if (!name || seen.has(name)) {
          return;
        }
        seen.add(name);
        out.push({ name, ...parseBadgeNumbers(label) });
      });

      return out;
    })
    .catch(() => []);
}

function createManufacturerSkuCollector() {
  /** @type {Map<string, Record<string, string>>} */
  const rowsBySku = new Map();

  const ingestBody = (body) => {
    const text = String(body || '');
    if (!/"sku"|\/retailer\/products\//i.test(text)) {
      return;
    }

    const skuMatches = [...text.matchAll(/"sku"\s*:\s*"([^"]+)"/gi)];
    for (const match of skuMatches) {
      const sku = String(match[1] || '').trim();
      if (!sku) {
        continue;
      }
      if (!rowsBySku.has(sku)) {
        rowsBySku.set(sku, { sku });
      }
    }

    const nameMatches = [...text.matchAll(/"productName"\s*:\s*"([^"]+)"/gi)];
    for (const match of nameMatches) {
      const name = String(match[1] || '').trim();
      if (!name) {
        continue;
      }
      const existing = [...rowsBySku.values()].find((row) => row.product_name === name);
      if (existing) {
        continue;
      }
    }

    const pairMatches = [...text.matchAll(/"sku"\s*:\s*"([^"]+)"[\s\S]{0,400}?"productName"\s*:\s*"([^"]+)"/gi)];
    for (const match of pairMatches) {
      const sku = String(match[1] || '').trim();
      const productName = String(match[2] || '').trim();
      if (!sku) {
        continue;
      }
      const row = rowsBySku.get(sku) || { sku };
      if (productName) {
        row.product_name = productName;
      }
      rowsBySku.set(sku, row);
    }

    const hrefMatches = [...text.matchAll(/\/retailer\/products\/([0-9A-Za-z_-]+)/g)];
    for (const match of hrefMatches) {
      const sku = String(match[1] || '').trim();
      if (!sku || rowsBySku.has(sku)) {
        continue;
      }
      rowsBySku.set(sku, { sku });
    }
  };

  return { rowsBySku, ingestBody };
}

function attachPlamodNetworkCapture(page, skuCollector = null) {
  /** @type {Array<{status: number, url: string, content_type: string, body_preview: string}>} */
  const captured = [];

  page.on('response', async (response) => {
    const url = response.url();
    if (!/plamod\.com/i.test(url)) {
      return;
    }
    if (!/(api|graphql|search|product|manufacturer|preorder|export|csv|retailer)/i.test(url)) {
      return;
    }

    const contentType = response.headers()['content-type'] || '';
    let bodyPreview = '';
    try {
      if (
        contentType.includes('json') ||
        contentType.includes('text/csv') ||
        contentType.includes('text/plain') ||
        contentType.includes('text/x-component')
      ) {
        const fullBody = await response.text();
        skuCollector?.ingestBody(fullBody);
        bodyPreview = fullBody.slice(0, 1200);
      }
    } catch {
      bodyPreview = '';
    }

    captured.push({
      status: response.status(),
      url,
      content_type: contentType,
      body_preview: bodyPreview,
    });
  });

  return captured;
}

function extractManufacturerPreorderCardsFromDocument() {
  const norm = (value) => String(value || '').replace(/\s+/g, ' ').trim();

  const skuFromHref = (href) => {
    const clean = String(href || '').split('?')[0];
    const parts = clean.split('/').filter(Boolean);
    const sku = parts[parts.length - 1] || '';
    return /^[0-9A-Za-z_-]+$/.test(sku) ? sku : '';
  };

  /** @type {Array<Record<string, string>>} */
  const rows = [];
  const seen = new Set();

  const productAnchors = Array.from(document.querySelectorAll('a[href*="/retailer/products/"]'));
  for (const anchor of productAnchors) {
    const sku = skuFromHref(anchor.getAttribute('href') || '');
    if (!sku || seen.has(sku)) {
      continue;
    }

    let container = anchor;
    for (let depth = 0; depth < 14; depth += 1) {
      if (!container.parentElement) {
        break;
      }
      container = container.parentElement;
      if ((container.textContent || '').includes('SKU:')) {
        break;
      }
    }

    const fields = {};
    container.querySelectorAll('.text-gray-500').forEach((labelNode) => {
      const key = norm(labelNode.textContent).replace(/:$/, '');
      const valueNode = labelNode.nextElementSibling;
      if (!key || !valueNode) {
        return;
      }
      fields[key] = norm(valueNode.textContent);
    });

    const manufacturer =
      norm(container.querySelector('a[href*="/retailer/manufacturers/"]')?.textContent || '') || '';
    const category =
      norm(container.querySelector('a[href*="/retailer/search?categories="]')?.textContent || '') || '';
    const series =
      norm(container.querySelector('a[href*="/retailer/search?series="]')?.textContent || '') || fields.Series || '';
    const productName = norm(anchor.textContent) || fields['Product Name'] || sku;
    const imageUrl = norm(container.querySelector('img[src*="plamod"], img[src*="images.plamod"]')?.getAttribute('src') || '');

    let pricePreorder = '';
    let quantityPreorder = '';
    const preorderHeader = Array.from(container.querySelectorAll('thead td, thead th, td, th')).find((node) =>
      /preorder/i.test(norm(node.textContent)),
    );
    if (preorderHeader) {
      const table = preorderHeader.closest('table');
      const priceNode = table?.querySelector('[class*="price"], .font-bold, .text-lg');
      if (priceNode) {
        pricePreorder = norm(priceNode.textContent).replace(/[^0-9.]/g, '');
      }
      const qtyCells = table ? Array.from(table.querySelectorAll('td div.text-center, td .font-bold')) : [];
      const qty = qtyCells.map((n) => norm(n.textContent)).find((v) => /^\d+$/.test(v));
      if (qty) {
        quantityPreorder = qty;
      }
    }

    const containerText = norm(container.textContent || '');
    let poDueDate = '';
    let etaDate = '';
    const closing =
      containerText.match(/Closing(?:\s+\w+)?\s*\(\s*([A-Za-z]{3,9}\s+\d{1,2})\s*\)/i) ||
      containerText.match(/Closing(?:\s+\w+)?\s+([A-Za-z]{3,9}\s+\d{1,2})/i);
    if (closing?.[1]) {
      poDueDate = norm(closing[1]);
    }
    const eta = containerText.match(/ETA:\s*([A-Za-z]{3,9}\s+\d{1,2})/i);
    if (eta?.[1]) {
      etaDate = norm(eta[1]);
    }

    const stockPrice = fields['Stock Price'] || fields.Stock || '';
    const priceStock = stockPrice ? norm(stockPrice).replace(/[^0-9.]/g, '') : '';

    seen.add(sku);
    rows.push({
      sku,
      barcode: fields.Barcode || '',
      product_name: productName,
      series,
      release_date: fields['Release Date'] || fields.Release || '',
      manufacturer,
      category,
      price_stock: priceStock,
      price_preorder: pricePreorder,
      quantity_preorder: quantityPreorder,
      po_due_date: poDueDate,
      eta_date: etaDate,
      image_url: imageUrl,
    });
  }

  return rows;
}

async function scrapeManufacturerPreorderRows(page, expectedCount = 0, networkRowsBySku = null) {
  /** @type {Map<string, Record<string, string>>} */
  const rowsBySku = networkRowsBySku || new Map();
  let staleRounds = 0;

  await page.evaluate(() => window.scrollTo(0, 0)).catch(() => undefined);
  await page.waitForTimeout(500);

  for (let round = 0; round < 320; round += 1) {
    const batch = await page.evaluate(extractManufacturerPreorderCardsFromDocument);
    let newRows = 0;
    for (const row of batch) {
      if (!row?.sku) {
        continue;
      }
      const hadSku = rowsBySku.has(row.sku);
      const existing = rowsBySku.get(row.sku) || {};
      rowsBySku.set(row.sku, { ...existing, ...row });
      if (!hadSku) {
        newRows += 1;
      }
    }

    if (expectedCount > 0 && rowsBySku.size >= expectedCount) {
      break;
    }

    if (newRows === 0) {
      staleRounds += 1;
      if (staleRounds >= 16) {
        break;
      }
    } else {
      staleRounds = 0;
    }

    await page
      .evaluate(() => {
        window.scrollBy(0, Math.max(500, Math.floor(window.innerHeight * 0.85)));
        const nodes = document.querySelectorAll('[data-radix-scroll-area-viewport], .overflow-auto, main');
        nodes.forEach((node) => {
          if (node instanceof HTMLElement) {
            node.scrollTop += Math.max(500, Math.floor(window.innerHeight * 0.85));
          }
        });
      })
      .catch(() => undefined);
    await page.waitForTimeout(500);
  }

  return rowsBySku;
}

function manufacturerRowsToCsv(header, rowsBySku) {
  const esc = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`;
  const lines = [header.map(esc).join(',')];
  for (const row of rowsBySku.values()) {
    lines.push(
      [
        row.sku,
        row.barcode,
        row.product_name,
        row.series,
        row.release_date,
        row.manufacturer,
        row.category,
        row.price_stock || '',
        row.price_preorder,
        row.price_backorder || '',
        row.quantity_preorder,
        row.po_due_date || '',
        row.eta_date || '',
        row.image_url,
      ]
        .map(esc)
        .join(','),
    );
  }
  return `${lines.join('\n')}\n`;
}

function simpleCsvRowsToRecordMap(parsed, csvHeader) {
  const header = parsed.header.length > 0 ? parsed.header : csvHeader;
  const idx = (name) => header.findIndex((h) => String(h).toLowerCase() === name.toLowerCase());

  /** @type {Map<string, Record<string, string>>} */
  const map = new Map();
  for (const [sku, cols] of parsed.rows.entries()) {
    map.set(sku, {
      sku,
      barcode: String(cols[idx('Barcode')] ?? ''),
      product_name: String(cols[idx('Product Name')] ?? sku),
      series: String(cols[idx('Series')] ?? ''),
      release_date: String(cols[idx('Release Date')] ?? ''),
      manufacturer: String(cols[idx('Manufacturer')] ?? ''),
      category: String(cols[idx('Category')] ?? ''),
      price_stock: String(cols[idx('Price Stock')] ?? ''),
      price_preorder: String(cols[idx('Price Preorder')] ?? ''),
      price_backorder: String(cols[idx('Price Backorder')] ?? ''),
      quantity_preorder: String(cols[idx('Quantity Preorder')] ?? ''),
      po_due_date: String(cols[idx('PO Due Date')] ?? ''),
      eta_date: String(cols[idx('ETA Date')] ?? ''),
      image_url: String(cols[idx('Image URL')] ?? ''),
    });
  }

  return map;
}

function manufacturerRowNeedsPdpEnrich(row) {
  return (
    !String(row?.price_preorder || '').trim() ||
    !String(row?.image_url || '').trim()
  );
}

function mergeManufacturerRow(existing, patch) {
  const merged = { ...existing };
  for (const [key, value] of Object.entries(patch)) {
    if (value === null || value === undefined) {
      continue;
    }
    const text = String(value).trim();
    if (text === '') {
      continue;
    }
    merged[key] = text;
  }
  return merged;
}

async function scrapePreorderPdpFields(page, baseUrl, context, sku) {
  await ensureOnRetailerPdp(page, baseUrl, sku, context);
  await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => undefined);
  await page.waitForTimeout(1200);

  const patch = await page.evaluate(() => {
    const norm = (value) => String(value || '').replace(/\s+/g, ' ').trim();
    const fields = {};
    document.querySelectorAll('.text-gray-500').forEach((labelNode) => {
      const key = norm(labelNode.textContent).replace(/:$/, '');
      const valueNode = labelNode.nextElementSibling;
      if (!key || !valueNode) {
        return;
      }
      fields[key] = norm(valueNode.textContent);
    });

    const text = norm(document.body.innerText || '');
    let poDueDate = '';
    let etaDate = '';
    const closing =
      text.match(/Closing(?:\s+\w+)?\s*\(\s*([A-Za-z]{3,9}\s+\d{1,2})\s*\)/i) ||
      text.match(/Closing(?:\s+\w+)?\s+([A-Za-z]{3,9}\s+\d{1,2})/i);
    if (closing?.[1]) {
      poDueDate = norm(closing[1]);
    }
    const eta = text.match(/ETA:\s*([A-Za-z]{3,9}\s+\d{1,2})/i);
    if (eta?.[1]) {
      etaDate = norm(eta[1]);
    }

    let pricePreorder = '';
    let quantityPreorder = '';
    let offerBlock = null;
    for (const el of document.querySelectorAll('div')) {
      const blockText = norm(el.textContent || '');
      if (/PREORDER OFFER/i.test(blockText) && /PO\s*PRICE/i.test(blockText) && blockText.length < 700) {
        offerBlock = el;
        break;
      }
    }
    if (offerBlock) {
      const offerText = norm(offerBlock.textContent || '');
      const poHit = offerText.match(/PO\s*PRICE\s*\$?\s*([0-9]+\.[0-9]{2})/i);
      if (poHit?.[1]) {
        pricePreorder = poHit[1];
      }
      const totalLabel = Array.from(offerBlock.querySelectorAll('div, span')).find(
        (node) => norm(node.textContent) === 'TOTAL',
      );
      if (totalLabel) {
        const qtyNode =
          totalLabel.previousElementSibling ||
          totalLabel.parentElement?.querySelector('.text-2xl, .text-xl, .text-3xl, .font-bold, .font-semibold');
        const qtyText = norm(qtyNode?.textContent || '');
        if (/^\d+$/.test(qtyText)) {
          quantityPreorder = qtyText;
        }
      }
    }

    if (!pricePreorder) {
      const poPriceLabel = Array.from(document.querySelectorAll('div, span, p, td, th')).find((node) =>
        /^PO\s*PRICE$/i.test(norm(node.textContent)),
      );
      if (poPriceLabel?.parentElement) {
        const hit = norm(poPriceLabel.parentElement.textContent).match(/\$\s*([0-9]+(?:\.[0-9]{2})?)/);
        if (hit?.[1]) {
          pricePreorder = hit[1];
        }
      }
    }

    if (!quantityPreorder) {
      const totalLabel = Array.from(document.querySelectorAll('div, span')).find((node) => norm(node.textContent) === 'TOTAL');
      if (totalLabel?.previousElementSibling) {
        const qtyText = norm(totalLabel.previousElementSibling.textContent || '');
        if (/^\d+$/.test(qtyText)) {
          quantityPreorder = qtyText;
        }
      }
    }

    if (!pricePreorder) {
      const poFromText = text.match(/PO\s*PRICE[^$]{0,40}\$\s*([0-9]+\.[0-9]{2})/i);
      if (poFromText?.[1]) {
        pricePreorder = poFromText[1];
      }
    }

    const stockMatch =
      text.match(/Stock Price[^$]{0,40}\$\s*([0-9]+\.[0-9]{2})/i) || text.match(/Stock Price\s*\$?\s*([0-9]+(?:\.[0-9]{2})?)/i);
    const stockPrice =
      (stockMatch?.[1] || '') || (fields['Stock Price'] ? norm(fields['Stock Price']).replace(/[^0-9.]/g, '') : '');
    const releaseMatch = text.match(/Release Date\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i);
    const barcodeMatch = text.match(/Barcode\s+(\d{10,14})/i);
    const imageUrl = norm(document.querySelector('img[src*="plamod"], img[src*="images.plamod"]')?.getAttribute('src') || '');
    const series =
      fields.Series || norm(document.querySelector('a[href*="/retailer/search?series="]')?.textContent || '');
    const productName = norm(document.querySelector('h1')?.textContent || '') || fields['Product Name'] || '';

    return {
      page_sku: fields.SKU || fields.Sku || '',
      barcode: barcodeMatch?.[1] || fields.Barcode || '',
      product_name: productName,
      series,
      release_date: releaseMatch?.[1] || fields['Release Date'] || fields.Release || '',
      manufacturer: norm(document.querySelector('a[href*="/retailer/manufacturers/"]')?.textContent || ''),
      category: norm(document.querySelector('a[href*="/retailer/search?categories="]')?.textContent || ''),
      price_stock: stockPrice,
      price_preorder: pricePreorder,
      quantity_preorder: quantityPreorder,
      po_due_date: poDueDate,
      eta_date: etaDate,
      image_url: imageUrl,
    };
  });

  const pageSku = normalizePlamodSku(patch.page_sku || retailerPdpSkuFromUrl(page.url()));
  if (pageSku !== '' && pageSku.toLowerCase() !== normalizePlamodSku(sku).toLowerCase()) {
    throw new Error(`PDP sku mismatch: requested=${sku} page=${pageSku}`);
  }

  delete patch.page_sku;
  return patch;
}

function manufacturerRowEnrichPriority(row) {
  let score = 0;
  if (!String(row?.price_preorder || '').trim()) {
    score += 10;
  }
  if (!String(row?.image_url || '').trim()) {
    score += 8;
  }
  if (String(row?.barcode || '').trim()) {
    score += 2;
  }
  if (String(row?.product_name || '').trim()) {
    score += 1;
  }
  return score;
}

async function enrichSparseManufacturerRowsFromPdp(page, baseUrl, context, rowsBySku) {
  const maxEnrich = Number.parseInt(process.env.PLAMOD_MANUFACTURER_PDP_ENRICH_MAX || '30', 10);
  const candidates = [...rowsBySku.entries()]
    .filter(([, row]) => manufacturerRowNeedsPdpEnrich(row))
    .sort(([skuA, rowA], [skuB, rowB]) => {
      const scoreDiff = manufacturerRowEnrichPriority(rowB) - manufacturerRowEnrichPriority(rowA);
      if (scoreDiff !== 0) {
        return scoreDiff;
      }
      return String(skuA).localeCompare(String(skuB));
    });

  let enriched = 0;
  let consecutiveLoginFailures = 0;
  const enrichBudgetMs = Number.parseInt(process.env.PLAMOD_MANUFACTURER_PDP_ENRICH_BUDGET_MS || '120000', 10);
  const enrichStarted = Date.now();
  for (const [sku, row] of candidates) {
    if (enriched >= maxEnrich) {
      break;
    }
    if (Date.now() - enrichStarted > enrichBudgetMs) {
      // eslint-disable-next-line no-console
      console.log(`[plamod] pdp enrich stopping early budget_ms=${enrichBudgetMs}`);
      break;
    }
    try {
      const patch = await scrapePreorderPdpFields(page, baseUrl, context, sku);
      consecutiveLoginFailures = 0;
      const merged = mergeManufacturerRow(row, patch);
      rowsBySku.set(sku, merged);
      if (!manufacturerRowNeedsPdpEnrich(merged)) {
        enriched += 1;
        // eslint-disable-next-line no-console
        console.log(
          `[plamod] pdp enrich ok sku=${sku} price=${merged.price_preorder || '-'} qty=${merged.quantity_preorder || '-'}`,
        );
      } else {
        // eslint-disable-next-line no-console
        console.log(
          `[plamod] pdp enrich sparse sku=${sku} price=${patch.price_preorder || '-'} qty=${patch.quantity_preorder || '-'}`,
        );
      }
    } catch (e) {
      const message = String(e?.message || 'unknown');
      // eslint-disable-next-line no-console
      console.log(`[plamod] pdp enrich failed sku=${sku} msg=${message}`);
      if (/login failed|sign-in/i.test(message)) {
        consecutiveLoginFailures += 1;
        if (consecutiveLoginFailures >= 3) {
          // eslint-disable-next-line no-console
          console.log('[plamod] pdp enrich stopping early after login cascade');
          break;
        }
      } else {
        consecutiveLoginFailures = 0;
      }
    }
  }

  // eslint-disable-next-line no-console
  console.log(
    `[plamod] manufacturer pdp enrich done enriched=${enriched} candidates=${candidates.length} skipped=${Math.max(0, candidates.length - enriched)} total=${rowsBySku.size}`,
  );
}

async function tryDownloadCsvViaCapturedResponses(page, captured, destPath) {
  for (const entry of [...captured].reverse()) {
    if (!/csv|export/i.test(entry.url) && !entry.content_type.includes('csv')) {
      continue;
    }
    if (!entry.body_preview || entry.body_preview.length < 20) {
      continue;
    }
    if (!entry.body_preview.includes('SKU') && !entry.body_preview.includes('Product Name')) {
      continue;
    }
    fs.writeFileSync(destPath, entry.body_preview, 'utf8');
    return true;
  }
  return false;
}

async function downloadPreordersCsvFromPage(page, csvHandle, destPath) {
  async function attemptCsvDownload(attemptNo) {
    // eslint-disable-next-line no-console
    console.log(`[plamod] click preorders csv attempt=${attemptNo}`);
    const [download] = await Promise.all([
      page.waitForEvent('download', { timeout: 60_000 }),
      csvHandle.click(),
    ]);
    return download;
  }

  let download;
  try {
    download = await attemptCsvDownload(1);
  } catch (e) {
    const msg = String(e?.message || '');
    if (!/timeout/i.test(msg)) {
      throw e;
    }
    // eslint-disable-next-line no-console
    console.log('[plamod] preorders csv download timeout; retrying');
    await page.waitForTimeout(1000);
    download = await attemptCsvDownload(2);
  }

  await Promise.race([
    download.saveAs(destPath),
    new Promise((_, reject) => setTimeout(() => reject(new Error('download_save_timeout')), 60_000)),
  ]);

  const failure = await download.failure().catch(() => null);
  if (failure) {
    throw new Error(`CSV download failed: ${failure}`);
  }
}

async function exportPlamodPreordersCsv() {
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  const profileDir = String(process.env.PLAMOD_PERSISTENT_PROFILE_DIR || path.resolve(__dirname, '..', '..', '.pw-user-data'));
  const probeSku = '5060358';
  const debugSku = 'preorders-export';

  const root = storageRoot();
  const rawDir = path.join(root, 'plamod', 'preorder_exports');
  ensureDir(rawDir);
  const csvFilename = `${nowStamp()}.csv`;
  const csvPath = path.join(rawDir, csvFilename);
  const csvStoragePath = path.posix.join('plamod', 'preorder_exports', csvFilename);

  // eslint-disable-next-line global-require
  const { chromium } = require('playwright');

  const started = Date.now();
  cleanupPersistentProfileLocks(profileDir);

  // eslint-disable-next-line no-console
  console.log('[plamod] export preorders csv start');
  const context = await chromium.launchPersistentContext(profileDir, {
    timeout: 30_000,
    headless: true,
    acceptDownloads: true,
    viewport: { width: 1400, height: 900 },
    locale: 'en-CA',
    timezoneId: 'America/Toronto',
    userAgent:
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    args: ['--disable-blink-features=AutomationControlled'],
  });

  try {
    const page = await context.newPage();
    await ensureLoggedIn(page, baseUrl, context, probeSku);
    await gotoWithTimeout(page, `${baseUrl}/retailer/preorders`, 45_000);

    if (await looksLikeSignInPage(page)) {
      const prevForce = process.env.PLAMOD_FORCE_LOGIN;
      process.env.PLAMOD_FORCE_LOGIN = 'true';
      try {
        await ensureLoggedIn(page, baseUrl, context, debugSku);
      } finally {
        if (prevForce === undefined) {
          delete process.env.PLAMOD_FORCE_LOGIN;
        } else {
          process.env.PLAMOD_FORCE_LOGIN = prevForce;
        }
      }
      await gotoWithTimeout(page, `${baseUrl}/retailer/preorders`, 45_000);
    }

    await page.waitForLoadState('networkidle', { timeout: 45_000 }).catch(() => undefined);
    await page.waitForTimeout(1200);

    // Reset filters/sorts: CSV export reflects the current on-screen preorders view.
    if (page.url().includes('?')) {
      await gotoWithTimeout(page, `${baseUrl}/retailer/preorders`, 45_000);
      await page.waitForLoadState('networkidle', { timeout: 45_000 }).catch(() => undefined);
      await page.waitForTimeout(1200);
    }

    await clickFirst(page, [
      'button:has-text("Clear filters")',
      'button:has-text("Clear Filters")',
      'button:has-text("Reset")',
      'button:has-text("All")',
    ]);

    if (await looksLikeSignInPage(page)) {
      const debug = await writeDebugSnapshot(page, debugSku, 'sign-in');
      return {
        ok: false,
        error_message: 'Plamod login failed for preorders page. Check PLAMOD_COMPANY, PLAMOD_USERNAME, and PLAMOD_PASSWORD.',
        debug,
        duration_ms: Date.now() - started,
      };
    }

    // Plamod CSV export is tab-scoped: "New Preorders" and "Offer Sheets" are separate lists.
    const tabLabels = ['New Preorders', 'Offer Sheets'];
    /** @type {string[]} */
    let mergedHeader = [];
    /** @type {Map<string, string[]>} */
    const mergedRows = new Map();

    for (const tabLabel of tabLabels) {
      const clicked = await clickFirst(page, [`button:has-text("${tabLabel}")`]);
      if (!clicked) {
        // eslint-disable-next-line no-console
        console.log(`[plamod] preorders tab not found: ${tabLabel}`);
        continue;
      }

      await page.waitForLoadState('networkidle', { timeout: 45_000 }).catch(() => undefined);
      await page.waitForTimeout(1500);
      const visible = await scrollLoadPreorderGrid(page);
      await writeDebugSnapshot(page, debugSku, `preorders-before-csv-${tabLabel.replace(/\s+/g, '-')}`).catch(() => undefined);

      const csvHandle = await findFirstHandle(page, [
        'a:has-text("CSV")',
        'button:has-text("CSV")',
        'a[href*="csv" i]',
        'a[href*="export" i]',
        '[data-testid="export-csv"]',
        'text=/^CSV$/i',
      ]);

      if (!csvHandle) {
        // eslint-disable-next-line no-console
        console.log(`[plamod] csv control missing on tab: ${tabLabel}`);
        continue;
      }

      const partialPath = `${csvPath}.${tabLabel.replace(/\s+/g, '_')}.partial.csv`;
      try {
        await downloadPreordersCsvFromPage(page, csvHandle, partialPath);
      } catch (e) {
        // eslint-disable-next-line no-console
        console.log(`[plamod] tab csv download failed tab=${tabLabel} msg=${String(e?.message || 'unknown')}`);
        continue;
      }

      let parsed = parseSimpleCsvFile(partialPath);
      if (mergedHeader.length === 0 && parsed.header.length > 0) {
        mergedHeader = parsed.header;
      }

      const activeHeader = parsed.header.length > 0 ? parsed.header : mergedHeader;
      if (activeHeader.length > 0 && visible > parsed.rows.size + 2) {
        const supplementRows = await supplementCsvRowsFromVisibleGrid(page, activeHeader, parsed.rows);
        let added = 0;
        for (const cols of supplementRows) {
          const skuIdx = activeHeader.findIndex((h) => String(h).trim().toLowerCase() === 'sku');
          const sku = skuIdx >= 0 ? String(cols[skuIdx] || '').trim() : '';
          if (!sku || parsed.rows.has(sku)) {
            continue;
          }
          parsed.rows.set(sku, cols);
          added += 1;
        }
        if (added > 0) {
          // eslint-disable-next-line no-console
          console.log(`[plamod] hub dom supplement tab=${tabLabel} added=${added} csv_rows=${parsed.rows.size} visible_links=${visible}`);
        }
      }

      for (const [sku, cols] of parsed.rows.entries()) {
        if (!mergedRows.has(sku)) {
          mergedRows.set(sku, cols);
        }
      }
      fs.unlinkSync(partialPath);
      // eslint-disable-next-line no-console
      console.log(`[plamod] tab csv merged tab=${tabLabel} rows=${parsed.rows.size} visible_links=${visible}`);
    }

    if (mergedRows.size === 0) {
      const debug = await writeDebugSnapshot(page, debugSku, 'missing-csv');
      return {
        ok: false,
        error_message: 'Could not export any preorder CSV rows from Plamod tabs',
        debug,
        duration_ms: Date.now() - started,
      };
    }

    writeSimpleCsvFile(csvPath, mergedHeader, mergedRows);
    const stat = fs.statSync(csvPath);

    // eslint-disable-next-line no-console
    console.log(`[plamod] export preorders csv saved path=${csvStoragePath} bytes=${stat.size} rows=${mergedRows.size}`);

    return {
      ok: true,
      csv_storage_path: csvStoragePath,
      bytes: stat.size,
      duration_ms: Date.now() - started,
    };
  } catch (e) {
    const page = context.pages()[0];
    const debug = page ? await writeDebugSnapshot(page, debugSku, 'exception').catch(() => ({})) : {};
    return {
      ok: false,
      error_message: String(e?.message || 'Unknown error'),
      debug,
      duration_ms: Date.now() - started,
    };
  } finally {
    await safeCloseContext(context);
  }
}

/**
 * List SERIES and Tier-2b CATEGORY filters on a manufacturer preorder page.
 *
 * @param {{ manufacturerId?: number|string, tab?: string }} opts
 */
async function listManufacturerPreorderFilters(opts = {}) {
  const manufacturerId = String(opts.manufacturerId ?? 1).trim();
  const tabLabel = String(opts.tab ?? 'Preorder').trim();
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  const profileDir = String(process.env.PLAMOD_PERSISTENT_PROFILE_DIR || path.resolve(__dirname, '..', '..', '.pw-user-data'));
  const probeSku = '5060358';
  const debugSku = `manufacturer-${manufacturerId}-filters`;

  // eslint-disable-next-line global-require
  const { chromium } = require('playwright');

  const started = Date.now();
  cleanupPersistentProfileLocks(profileDir);

  // eslint-disable-next-line no-console
  console.log(`[plamod] list manufacturer preorder filters start id=${manufacturerId} tab=${tabLabel}`);
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
    const captured = attachPlamodNetworkCapture(page);
    const filterPayloads = attachManufacturerFilterPayloadCapture(page);
    const manufacturerUrl = `${baseUrl}/retailer/manufacturers/${manufacturerId}`;

    await ensureLoggedIn(page, baseUrl, context, probeSku);
    await gotoWithTimeout(page, manufacturerUrl, 45_000);

    if (await looksLikeSignInPage(page)) {
      const prevForce = process.env.PLAMOD_FORCE_LOGIN;
      process.env.PLAMOD_FORCE_LOGIN = 'true';
      try {
        await ensureLoggedIn(page, baseUrl, context, debugSku);
      } finally {
        if (prevForce === undefined) {
          delete process.env.PLAMOD_FORCE_LOGIN;
        } else {
          process.env.PLAMOD_FORCE_LOGIN = prevForce;
        }
      }
      await gotoWithTimeout(page, manufacturerUrl, 45_000);
    }

    if (await looksLikeSignInPage(page)) {
      return {
        ok: false,
        error_message: 'Plamod login failed while listing manufacturer filters.',
        duration_ms: Date.now() - started,
      };
    }

    await page.waitForLoadState('networkidle', { timeout: 45_000 }).catch(() => undefined);
    await page.waitForTimeout(1200);

    const tabClicked = await clickExactManufacturerStatusTab(page, tabLabel);
    if (!tabClicked) {
      return {
        ok: false,
        error_message: `Could not find manufacturer status tab: ${tabLabel}`,
        duration_ms: Date.now() - started,
      };
    }

    await ensureManufacturerPlasticModelKitsOnly(page).catch(() => undefined);

    const pageHtml = await page.content().catch(() => '');
    const seriesFromDom = await scrapeManufacturerSidebarFilterItems(page, 'SERIES');
    await writeDebugSnapshot(page, debugSku, 'after-series-scrape').catch(() => undefined);
    let seriesFromEmbed = parseEmbeddedManufacturerFilterItemsFromText(pageHtml, 'series');
    if (seriesFromEmbed.length === 0) {
      seriesFromEmbed = await extractEmbeddedManufacturerFilterItems(page, 'series');
    }
    if (seriesFromEmbed.length === 0) {
      seriesFromEmbed = await extractManufacturerFilterItemsFromResponses(page, 'series');
    }
    if (seriesFromEmbed.length === 0) {
      for (const body of filterPayloads) {
        const parsed = parseEmbeddedManufacturerFilterItemsFromText(body, 'series');
        if (parsed.length > 0) {
          seriesFromEmbed = parsed;
          break;
        }
      }
    }
    if (seriesFromEmbed.length === 0) {
      for (const entry of captured) {
        const parsed = parseEmbeddedManufacturerFilterItemsFromText(entry.body_preview || '', 'series');
        if (parsed.length > 0) {
          seriesFromEmbed = parsed;
          break;
        }
      }
    }
    const series = mergeManufacturerFilterItems(
      seriesFromEmbed.length > 0 ? seriesFromEmbed : seriesFromDom,
      seriesFromDom,
    );

    const categoryFromDom = await scrapeManufacturerSidebarFilterItems(page, 'CATEGORY');
    let categoryFromEmbed = parseEmbeddedManufacturerFilterItemsFromText(pageHtml, 'categories');
    if (categoryFromEmbed.length === 0) {
      categoryFromEmbed = await extractEmbeddedManufacturerFilterItems(page, 'categories');
    }
    if (categoryFromEmbed.length === 0) {
      for (const body of filterPayloads) {
        const parsed = parseEmbeddedManufacturerFilterItemsFromText(body, 'categories');
        if (parsed.length > 0) {
          categoryFromEmbed = parsed;
          break;
        }
      }
    }
    const categoryItems = mergeManufacturerFilterItems(
      categoryFromEmbed.length > 0 ? categoryFromEmbed : categoryFromDom,
      categoryFromDom,
    );
    const categoryByName = new Map(categoryItems.map((item) => [item.name, item]));
    const category_lines = TIER_2B_CATEGORY_LINES.map((name) => {
      const hit = categoryByName.get(name);
      return hit || { name, preorder_count: null, other_count: null };
    });

    if (series.length === 0) {
      await writeDebugSnapshot(page, debugSku, 'empty-series').catch(() => undefined);
      if (filterPayloads.length > 0) {
        const payloadDir = path.join(debugRoot(), safeSkuDir(debugSku));
        ensureDir(payloadDir);
        const payloadDebug = filterPayloads
          .map((body, idx) => {
            const seriesIdx = body.indexOf('series');
            return `--- payload ${idx} len=${body.length} seriesIdx=${seriesIdx}\n${body.slice(Math.max(0, seriesIdx - 80), seriesIdx + 1200)}`;
          })
          .join('\n\n');
        fs.writeFileSync(path.join(payloadDir, `${nowStamp()}-filter-payloads.txt`), payloadDebug, { encoding: 'utf8' });
      }
    }

    // eslint-disable-next-line no-console
    console.log(
      `[plamod] list manufacturer preorder filters done series=${series.length} dom=${seriesFromDom.length} embed=${seriesFromEmbed.length} payloads=${filterPayloads.length} category_lines=${category_lines.length}`,
    );

    return {
      ok: true,
      manufacturer_id: manufacturerId,
      tab: tabLabel,
      series,
      category_lines,
      duration_ms: Date.now() - started,
    };
  } catch (e) {
    return {
      ok: false,
      error_message: String(e?.message || 'Unknown error'),
      duration_ms: Date.now() - started,
    };
  } finally {
    await safeCloseContext(context);
  }
}

async function ensureOnManufacturerPage(page, baseUrl, manufacturerId) {
  const manufacturerUrl = `${baseUrl}/retailer/manufacturers/${manufacturerId}`;
  if (!page.url().includes(`/retailer/manufacturers/${manufacturerId}`)) {
    await gotoWithTimeout(page, manufacturerUrl, 30_000);
  }
  await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => undefined);
  await page.waitForTimeout(600);
  if (await looksLikeSignInPage(page)) {
    throw new Error('Plamod login failed on manufacturer page.');
  }
}

/**
 * @param {import('playwright').Page} page
 * @param {import('playwright').BrowserContext} context
 * @param {{
 *   manufacturerId: string,
 *   tabLabel: string,
 *   seriesName: string,
 *   categoryLineName: string,
 *   categoryLabel: string|null,
 *   baseUrl: string,
 *   debugSku: string,
 *   csvPath: string,
 *   csvStoragePath: string,
 *   started: number,
 * }} opts
 */
async function runManufacturerPreorderExportOnPage(page, context, opts) {
  const {
    manufacturerId,
    tabLabel,
    seriesName,
    categoryLineName,
    categoryLabel,
    baseUrl,
    debugSku,
    csvPath,
    csvStoragePath,
    started,
  } = opts;
  const manufacturerUrl = `${baseUrl}/retailer/manufacturers/${manufacturerId}`;
  const csvHeader = [
    'SKU',
    'Barcode',
    'Product Name',
    'Series',
    'Release Date',
    'Manufacturer',
    'Category',
    'Price Stock',
    'Price Preorder',
    'Price Backorder',
    'Quantity Preorder',
    'PO Due Date',
    'ETA Date',
    'Image URL',
  ];

  const skuCollector = createManufacturerSkuCollector();
  const captured = attachPlamodNetworkCapture(page, skuCollector);

  await gotoWithTimeout(page, manufacturerUrl, 45_000);
  await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => undefined);
  await page.waitForTimeout(800);
  if (await looksLikeSignInPage(page)) {
    throw new Error('Plamod login failed on manufacturer page.');
  }

  const tabClicked = await clickExactManufacturerStatusTab(page, tabLabel);
  if (!tabClicked) {
    const debug = await writeDebugSnapshot(page, debugSku, 'missing-tab');
    return {
      ok: false,
      error_message: `Could not find manufacturer status tab: ${tabLabel}`,
      debug,
      duration_ms: Date.now() - started,
    };
  }

  await clearAllManufacturerFilters(page);

  if (seriesName) {
    const seriesOk = await ensureManufacturerSeries(page, seriesName);
    if (!seriesOk) {
      const debug = await writeDebugSnapshot(page, debugSku, 'missing-series');
      return {
        ok: false,
        error_message: `Could not select manufacturer series filter: ${seriesName}`,
        debug,
        manufacturer_url: manufacturerUrl,
        duration_ms: Date.now() - started,
      };
    }
  } else if (categoryLineName) {
    await ensureManufacturerCategoryLine(page, categoryLineName);
  } else if (categoryLabel) {
    await ensureManufacturerCategoryLine(page, categoryLabel);
  }

  const tabBadgeText = await readManufacturerTabBadge(page, tabLabel);
  const expectedCount = Number.parseInt(String(tabBadgeText || '').replace(/[^\d]/g, ''), 10) || 0;
  await writeDebugSnapshot(page, debugSku, 'before-export').catch(() => undefined);

  if (expectedCount === 0 && (seriesName || categoryLineName)) {
    // eslint-disable-next-line no-console
    console.log(
      `[plamod] manufacturer filter badge is zero; still attempting export series=${seriesName || '(none)'} category_line=${categoryLineName || '(none)'}`,
    );
  }

  let parsed = { header: csvHeader, rows: new Map() };
  let exportMode = 'scrape';

  const csvHandle = await findFirstHandle(page, [
    'a:has-text("CSV")',
    'button:has-text("CSV")',
    'a[href*="csv" i]',
    'a[href*="export" i]',
    '[data-testid="export-csv"]',
    'text=/^CSV$/i',
  ]);

  if (csvHandle) {
    try {
      await downloadPreordersCsvFromPage(page, csvHandle, csvPath);
      parsed = parseSimpleCsvFile(csvPath);
      exportMode = 'csv_button';
    } catch (e) {
      // eslint-disable-next-line no-console
      console.log(`[plamod] manufacturer csv button download failed msg=${String(e?.message || 'unknown')}`);
    }
  }

  if (parsed.rows.size === 0 && (await tryDownloadCsvViaCapturedResponses(page, captured, csvPath))) {
    parsed = parseSimpleCsvFile(csvPath);
    exportMode = 'captured_response';
  }

  /** @type {Map<string, Record<string, string>>} */
  let rowsBySku = new Map();

  if (parsed.rows.size === 0) {
    const scrapedRows = await scrapeManufacturerPreorderRows(page, expectedCount, skuCollector.rowsBySku);
    if (scrapedRows.size === 0) {
      const debug = await writeDebugSnapshot(page, debugSku, 'missing-rows');
      return {
        ok: false,
        error_message: 'Could not export manufacturer preorder rows (no CSV control and scrape returned zero rows)',
        debug,
        manufacturer_url: manufacturerUrl,
        tab_badge_text: tabBadgeText,
        expected_row_count: expectedCount,
        captured_responses: captured.length,
        duration_ms: Date.now() - started,
      };
    }

    rowsBySku = scrapedRows;
    exportMode = 'dom_scrape';
  } else {
    rowsBySku = simpleCsvRowsToRecordMap(parsed, csvHeader);
  }

  await enrichSparseManufacturerRowsFromPdp(page, baseUrl, context, rowsBySku);
  fs.writeFileSync(csvPath, manufacturerRowsToCsv(csvHeader, rowsBySku), 'utf8');
  parsed = parseSimpleCsvFile(csvPath);
  exportMode = `${exportMode}+pdp_enrich`;

  const visible = parsed.rows.size;
  if (parsed.rows.size === 0) {
    const debug = await writeDebugSnapshot(page, debugSku, 'empty-csv');
    return {
      ok: false,
      error_message: 'Manufacturer preorder CSV export returned zero rows',
      debug,
      tab_badge_text: tabBadgeText,
      visible_product_links: visible,
      duration_ms: Date.now() - started,
    };
  }

  const stat = fs.statSync(csvPath);
  const skus = Array.from(parsed.rows.keys());
  const hasVignaSku = parsed.rows.has('0225768');
  const hasVignaName = skus.some((sku) => {
    const cols = parsed.rows.get(sku) || [];
    const nameIdx = parsed.header.findIndex((h) => String(h).toLowerCase() === 'product name');
    const name = nameIdx >= 0 ? String(cols[nameIdx] || '') : '';
    return /vigna-?ghina/i.test(name);
  });

  // eslint-disable-next-line no-console
  console.log(
    `[plamod] export manufacturer preorders csv saved path=${csvStoragePath} bytes=${stat.size} rows=${parsed.rows.size} vigna_sku=${hasVignaSku} vigna_name=${hasVignaName}`,
  );

  return {
    ok: true,
    manufacturer_id: manufacturerId,
    tab: tabLabel,
    category: categoryLabel,
    series: seriesName || null,
    category_line: categoryLineName || null,
    manufacturer_url: manufacturerUrl,
    export_mode: exportMode,
    csv_storage_path: csvStoragePath,
    bytes: stat.size,
    row_count: parsed.rows.size,
    expected_row_count: expectedCount,
    has_vigna_sku: hasVignaSku,
    has_vigna_name: hasVignaName,
    tab_badge_text: tabBadgeText,
    visible_product_links: visible,
    duration_ms: Date.now() - started,
  };
}

/**
 * Export preorder CSV from a Plamod manufacturer page (e.g. BANDAI HOBBY = id 1).
 *
 * @param {{ manufacturerId?: number|string, tab?: string, category?: string|null, series?: string|null, categoryLine?: string|null, warmSession?: boolean }} opts
 */
async function exportManufacturerPreordersCsv(opts = {}) {
  const manufacturerId = String(opts.manufacturerId ?? 1).trim();
  const tabLabel = String(opts.tab ?? 'Preorder').trim();
  const seriesName = typeof opts.series === 'string' && opts.series.trim() ? opts.series.trim() : '';
  const categoryLineName =
    typeof opts.categoryLine === 'string' && opts.categoryLine.trim() ? opts.categoryLine.trim() : '';
  const categoryLabel =
    seriesName || categoryLineName
      ? null
      : opts.category === null
        ? null
        : String(opts.category ?? 'Plastic Model Kits').trim();
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  const profileDir = String(process.env.PLAMOD_PERSISTENT_PROFILE_DIR || path.resolve(__dirname, '..', '..', '.pw-user-data'));
  const debugSku = `manufacturer-${manufacturerId}-export`;
  const useWarmSession = opts.warmSession !== false;

  const root = storageRoot();
  const rawDir = path.join(root, 'plamod', 'manufacturer_preorder_exports');
  ensureDir(rawDir);
  const filterSlug = seriesName
    ? `series-${slugFilterName(seriesName)}`
    : categoryLineName
      ? `cat-${slugFilterName(categoryLineName)}`
      : 'all';
  const csvFilename = `mfr-${manufacturerId}-${filterSlug}-${nowStamp()}.csv`;
  const csvPath = path.join(rawDir, csvFilename);
  const csvStoragePath = path.posix.join('plamod', 'manufacturer_preorder_exports', csvFilename);
  const started = Date.now();

  // eslint-disable-next-line no-console
  console.log(
    `[plamod] export manufacturer preorders csv start id=${manufacturerId} tab=${tabLabel} series=${seriesName || '(none)'} category_line=${categoryLineName || '(none)'} category=${categoryLabel || '(none)'} warm=${useWarmSession}`,
  );

  const runExport = async (page, context) =>
    runManufacturerPreorderExportOnPage(page, context, {
      manufacturerId,
      tabLabel,
      seriesName,
      categoryLineName,
      categoryLabel,
      baseUrl,
      debugSku,
      csvPath,
      csvStoragePath,
      started,
    });

  if (!useWarmSession) {
    // eslint-disable-next-line global-require
    const { chromium } = require('playwright');
    cleanupPersistentProfileLocks(profileDir);
    const context = await chromium.launchPersistentContext(profileDir, {
      timeout: 30_000,
      headless: true,
      acceptDownloads: true,
      viewport: { width: 1400, height: 900 },
      locale: 'en-CA',
      timezoneId: 'America/Toronto',
      userAgent:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
      args: ['--disable-blink-features=AutomationControlled'],
    });

    try {
      const page = await context.newPage();
      await ensureLoggedInQuick(page, baseUrl, context);
      return await runExport(page, context);
    } catch (e) {
      const page = context.pages()[0];
      const debug = page ? await writeDebugSnapshot(page, debugSku, 'exception').catch(() => ({})) : {};
      return {
        ok: false,
        error_message: String(e?.message || 'Unknown error'),
        debug,
        duration_ms: Date.now() - started,
      };
    } finally {
      await safeCloseContext(context);
    }
  }

  return withManufacturerSessionMutex(async () => {
    try {
      const session = await acquireWarmManufacturerSession(baseUrl, profileDir, manufacturerId);
      const result = await runExport(session.page, session.context);
      session.lastUsed = Date.now();
      return result;
    } catch (e) {
      await closeWarmManufacturerSession();
      return {
        ok: false,
        error_message: String(e?.message || 'Unknown error'),
        duration_ms: Date.now() - started,
      };
    }
  });
}

function normalizeSearchText(value) {
  return String(value || '')
    .toLowerCase()
    .replace(/[#/-]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function searchTokens(query) {
  const stop = new Set(['re', 'hguc', 'hg', 'mg', 'rg', 'pg', 'eg', 'fm', 'hgbd', '100', '144', '60', 'the', 'ver', 'ka', 'custom', 'type']);
  return normalizeSearchText(query)
    .split(' ')
    .filter((t) => t.length >= 2 && !stop.has(t) && !/^\d+$/.test(t));
}

function scoreSearchHit(query, productName) {
  const q = normalizeSearchText(query);
  const p = normalizeSearchText(productName);
  if (!q || !p) return 0;
  if (p.includes(q) || q.includes(p)) return 100;
  const tokens = searchTokens(query);
  if (tokens.length === 0) return 0;
  let hits = 0;
  for (const token of tokens) {
    if (p.includes(token)) hits += 1;
  }
  return Math.round((hits / tokens.length) * 90);
}

function extractProductHitsFromDocument() {
  /** @type {Array<{sku: string, product_name: string}>} */
  const out = [];
  const seen = new Set();

  const skuFromHref = (href) => {
    const clean = String(href || '').split('?')[0];
    const parts = clean.split('/').filter(Boolean);
    const sku = parts[parts.length - 1] || '';
    return /^[0-9A-Za-z_-]+$/.test(sku) ? sku : '';
  };

  const cardText = (anchor) => {
    const title = (anchor.textContent || '').replace(/\s+/g, ' ').trim();
    if (title.length >= 4) return title;
    const row =
      anchor.closest('tr, li, article, [class*="product"], [class*="result"], [class*="row"]') ||
      anchor.parentElement;
    return (row?.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 240);
  };

  document.querySelectorAll('h3 a[href*="/retailer/products/"], a[href*="/retailer/products/"], a[href*="/products/"]').forEach((anchor) => {
    const href = anchor.getAttribute('href') || '';
    const sku = skuFromHref(href);
    const productName = cardText(anchor);
    if (!sku || !productName || seen.has(sku)) return;
    seen.add(sku);
    out.push({ sku, product_name: productName });
  });

  return out;
}

async function scrapeRetailerSearchPage(page, baseUrl, query, context, options = {}) {
  const searchUrl = `${baseUrl}/retailer/search?tab=preorder&q=${encodeURIComponent(query)}`;
  const directNav = Boolean(options.directNav);

  const openSearchResults = async () => {
    if (!directNav) {
      await gotoWithTimeout(page, searchUrl, 45_000);
    } else {
      await gotoWithTimeout(page, searchUrl, 30_000);
    }
    await page.waitForLoadState('domcontentloaded', { timeout: 20_000 }).catch(() => undefined);
    await page.waitForTimeout(directNav ? 500 : 800);
    return !page.url().includes('/retailer-sign-in');
  };

  let authed = await openSearchResults();
  if (!authed && context) {
    await ensureLoggedIn(page, baseUrl, context, 'search');
    authed = await openSearchResults();
  }
  if (!authed) {
    await writeDebugSnapshot(page, 'search', `auth-failed-${safeSkuDir(query).slice(0, 40)}`).catch(() => undefined);
    return null;
  }

  /** @type {Array<{sku: string, product_name: string}>} */
  let hits = await page.evaluate(extractProductHitsFromDocument);

  if (hits.length === 0) {
    await clickFirst(page, [
      'button:has-text("Preorder")',
      'a:has-text("Preorder")',
      '[role="tab"]:has-text("Preorder")',
      'label:has-text("Preorder")',
    ]);
    await page.waitForTimeout(1200);
    hits = await page.evaluate(extractProductHitsFromDocument);
  }

  let best = null;
  let bestScore = 0;
  for (const hit of hits) {
    const score = scoreSearchHit(query, hit.product_name);
    if (score > bestScore) {
      bestScore = score;
      best = hit;
    }
  }

  if (!best || bestScore < 40) {
    await writeDebugSnapshot(page, 'search', `no-hit-${safeSkuDir(query).slice(0, 40)}`).catch(() => undefined);
    return null;
  }

  let productName = best.product_name.trim();
  const skuMarker = productName.indexOf('SKU:');
  if (skuMarker > 0) {
    productName = productName.slice(0, skuMarker).trim();
  }
  const kitMatch = productName.match(
    /((?:RE|HGUC|HG|MG|RG|PG|FM|EG|HGBD|1\/\d+)\s+[^]+?)(?=(?:BANDAI|SKU:|Barcode:|$))/i,
  );
  if (kitMatch?.[1]) {
    productName = kitMatch[1].replace(/\s+/g, ' ').trim();
  }

  return {
    sku: best.sku,
    product_name: productName,
    plamod_pdp_url: `${baseUrl}/retailer/products/${encodeURIComponent(best.sku)}`,
    match_score: bestScore,
  };
}

/**
 * @param {string[]} queries
 */
async function searchRetailerPreorders(queries) {
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  const profileDir = String(process.env.PLAMOD_PERSISTENT_PROFILE_DIR || path.resolve(__dirname, '..', '..', '.pw-user-data'));

  const cleaned = [];
  for (const q of queries) {
    if (typeof q !== 'string') continue;
    const v = q.trim();
    if (v !== '') cleaned.push(v);
  }
  if (cleaned.length === 0) {
    return { ok: false, error_message: 'queries is required' };
  }

  const started = Date.now();

  return withSearchSessionMutex(async () => {
    /** @type {Record<string, any>} */
    const results = {};

    try {
      const session = await acquireWarmSearchSession(baseUrl, profileDir);
      const page = session.page;
      const context = session.context;

      for (let i = 0; i < cleaned.length; i += 1) {
        const query = cleaned[i];
        try {
          const hit = await scrapeRetailerSearchPage(page, baseUrl, query, context, {
            directNav: i > 0,
          });
          if (!hit?.sku) {
            results[query] = hit;
            continue;
          }
          try {
            const pdp = await scrapePreorderPdpFields(page, baseUrl, context, hit.sku);
            results[query] = { ...hit, ...pdp };
          } catch {
            results[query] = hit;
          }
        } catch (e) {
          results[query] = null;
        }
      }

      session.lastUsed = Date.now();

      return {
        ok: true,
        results,
        duration_ms: Date.now() - started,
      };
    } catch (e) {
      if (warmSearchSession) {
        await safeCloseContext(warmSearchSession.context).catch(() => undefined);
        warmSearchSession = null;
      }

      return {
        ok: false,
        error_message: String(e?.message || 'Unknown error'),
        results,
        duration_ms: Date.now() - started,
      };
    }
  });
}

async function resetPlamodScraperSessions() {
  await closeWarmManufacturerSession();
  if (warmSearchSession) {
    await safeCloseContext(warmSearchSession.context).catch(() => undefined);
    warmSearchSession = null;
  }

  return { ok: true };
}

async function debugScrapePreorderPdpFields(sku) {
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  const profileDir = String(process.env.PLAMOD_PERSISTENT_PROFILE_DIR || path.resolve(__dirname, '..', '..', '.pw-user-data'));

  return withManufacturerSessionMutex(async () => {
    const session = await acquireWarmManufacturerSession(baseUrl, profileDir, 1);
    return scrapePreorderPdpFields(session.page, baseUrl, session.context, String(sku || '').trim());
  });
}

async function enrichPreorderPdpFields(skus) {
  const started = Date.now();
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  const profileDir = String(process.env.PLAMOD_PERSISTENT_PROFILE_DIR || path.resolve(__dirname, '..', '..', '.pw-user-data'));
  const unique = [...new Set((Array.isArray(skus) ? skus : []).map((sku) => String(sku || '').trim()).filter(Boolean))];

  if (unique.length === 0) {
    return { ok: false, error_message: 'skus is required', duration_ms: Date.now() - started };
  }

  /** @type {Record<string, Record<string, string>|null>} */
  const results = {};

  return withManufacturerSessionMutex(async () => {
    const session = await acquireWarmManufacturerSession(baseUrl, profileDir, 1);
    for (const sku of unique) {
      try {
        const fields = await scrapePreorderPdpFields(session.page, baseUrl, session.context, sku);
        results[sku] = {
          image_url: String(fields?.image_url || '').trim(),
          product_name: String(fields?.product_name || '').trim(),
          price_preorder: String(fields?.price_preorder || '').trim(),
          quantity_preorder: String(fields?.quantity_preorder || '').trim(),
        };
      } catch (e) {
        results[sku] = null;
        // eslint-disable-next-line no-console
        console.log(`[plamod] enrich pdp failed sku=${sku} msg=${String(e?.message || 'unknown')}`);
      }
    }

    return {
      ok: true,
      results,
      enriched: Object.values(results).filter((row) => row && String(row.image_url || '').trim() !== '').length,
      duration_ms: Date.now() - started,
    };
  });
}

module.exports = {
  downloadPlamodZipForSku,
  exportPlamodPreordersCsv,
  listManufacturerPreorderFilters,
  exportManufacturerPreordersCsv,
  searchRetailerPreorders,
  resetPlamodScraperSessions,
  debugScrapePreorderPdpFields,
  enrichPreorderPdpFields,
  retailerPdpSkuFromUrl,
  isOnRetailerPdpForSku,
};


