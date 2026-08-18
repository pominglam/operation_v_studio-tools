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

function instockExportProgressPath() {
  return path.join(storageRoot(), 'plamod', 'instock_export_progress.json');
}

function writeInstockExportProgress(patch) {
  ensureDir(path.join(storageRoot(), 'plamod'));
  let existing = {};
  try {
    if (fs.existsSync(instockExportProgressPath())) {
      existing = JSON.parse(fs.readFileSync(instockExportProgressPath(), 'utf8'));
    }
  } catch {
    existing = {};
  }
  const next = {
    ...existing,
    ...patch,
    updated_at: new Date().toISOString(),
  };
  fs.writeFileSync(instockExportProgressPath(), JSON.stringify(next), 'utf8');
}

function readInstockExportProgress() {
  try {
    if (!fs.existsSync(instockExportProgressPath())) {
      return { ok: true, active: false };
    }
    const data = JSON.parse(fs.readFileSync(instockExportProgressPath(), 'utf8'));
    return { ok: true, active: Boolean(data.active), ...data };
  } catch (e) {
    return { ok: false, active: false, error_message: String(e?.message || 'progress read failed') };
  }
}

function clearInstockExportProgress() {
  try {
    if (fs.existsSync(instockExportProgressPath())) {
      fs.unlinkSync(instockExportProgressPath());
    }
  } catch {
    // ignore
  }
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

  const h1 = await page.textContent('h1', { timeout: 500 }).catch(() => '');
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

  if (await looksLikeSignInPage(page)) {
    await ensureManufacturerSession(page, baseUrl, context);
  }

  const pdpUrl = `${baseUrl}/retailer/products/${encodeURIComponent(sku)}`;
  await gotoWithTimeout(page, pdpUrl, 20_000);

  if (await looksLikeSignInPage(page)) {
    await ensureManufacturerSession(page, baseUrl, context);
    await gotoWithTimeout(page, pdpUrl, 20_000);
  }

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
    'button:has-text("Clear Brands")',
    'button:has-text("Clear Brand")',
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

function manufacturerFilterIdPrefixes(filterTab) {
  if (filterTab === 'SERIES') {
    return ['series-', 'brand-', 'category-'];
  }
  if (filterTab === 'BRAND') {
    return ['brand-', 'series-', 'category-'];
  }
  return ['category-', 'series-', 'brand-'];
}

async function tryPlaywrightManufacturerFilterSelect(page, targetName) {
  const escaped = String(targetName || '')
    .replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    .replace(/\s+/g, '\\s+');
  const pattern = new RegExp(`^\\s*${escaped}(?:\\s+\\d+(?:\\s+\\d+)?)?\\s*$`, 'iu');

  for (const sidebar of [
    page.locator('[data-radix-scroll-area-viewport]').first(),
    page.locator('.overflow-y-auto').first(),
  ]) {
    for (let round = 0; round < 52; round += 1) {
      const checkbox = sidebar.getByRole('checkbox', { name: pattern }).first();
      if ((await checkbox.count().catch(() => 0)) > 0) {
        await checkbox.scrollIntoViewIfNeeded().catch(() => undefined);
        const state = await checkbox.getAttribute('data-state').catch(() => null);
        if (state !== 'checked') {
          await checkbox.click({ timeout: 5000 }).catch(() => undefined);
        }
        await page.waitForTimeout(1200);
        return true;
      }

      const label = sidebar.locator('label[title], span[title]').filter({ hasText: pattern }).first();
      if ((await label.count().catch(() => 0)) > 0) {
        await label.scrollIntoViewIfNeeded().catch(() => undefined);
        const row = label.locator('xpath=ancestor::div[contains(@class,"flex")][1]');
        const rowCheckbox = row.getByRole('checkbox').first();
        if ((await rowCheckbox.count().catch(() => 0)) > 0) {
          const state = await rowCheckbox.getAttribute('data-state').catch(() => null);
          if (state !== 'checked') {
            await rowCheckbox.click({ timeout: 5000 }).catch(() => undefined);
          }
        } else {
          await label.click({ timeout: 5000 }).catch(() => undefined);
        }
        await page.waitForTimeout(1200);
        return true;
      }

      await scrollManufacturerSidebarStep(page);
    }
  }

  return false;
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
  await scrollManufacturerSidebarFilters(page);
  await resetManufacturerSidebarScroll(page);
  await page.waitForTimeout(400);

  if (await tryPlaywrightManufacturerFilterSelect(page, targetName)) {
    return true;
  }

  const idPrefixes = manufacturerFilterIdPrefixes(filterTab);

  for (let round = 0; round < 52; round += 1) {
    const selected = await page
      .evaluate(({ normalizedTarget, idPrefixes }) => {
        const norm = (value) =>
          String(value || '')
            .replace(/\s+/g, ' ')
            .replace(/\s*\/\s*/g, ' / ')
            .replace(/\s+\d+(?:\s+\d+)?\s*$/u, '')
            .trim()
            .toLowerCase();

        const namesMatch = (left, right) => {
          if (!left || !right) {
            return false;
          }
          if (left === right) {
            return true;
          }
          return left.startsWith(right) || right.startsWith(left);
        };

        const clickFilterControl = (control) => {
          if (!(control instanceof HTMLElement)) {
            return false;
          }

          const row = control.closest('.flex.items-center.justify-between, .flex.items-center.space-x-2');
          const radixCheckbox = row?.querySelector('button[role="checkbox"]');
          if (radixCheckbox instanceof HTMLElement) {
            if (
              radixCheckbox.getAttribute('data-state') === 'checked' ||
              radixCheckbox.getAttribute('aria-checked') === 'true'
            ) {
              return true;
            }
            radixCheckbox.click();
            return true;
          }

          const buttonId = control.getAttribute('for');
          const button = buttonId ? document.getElementById(buttonId) : null;
          if (button instanceof HTMLElement) {
            if (
              button.getAttribute('data-state') === 'checked' ||
              button.getAttribute('aria-checked') === 'true'
            ) {
              return true;
            }
            button.click();
            return true;
          }
          control.click();
          return true;
        };

        const sidebars = Array.from(document.querySelectorAll('[data-radix-scroll-area-viewport], .overflow-y-auto'));

        for (const idPrefix of idPrefixes) {
          const labels = Array.from(document.querySelectorAll(`label[for^="${idPrefix}"]`));
          for (const label of labels) {
            const candidates = [
              norm(label.getAttribute('title') || ''),
              norm(label.textContent || ''),
            ].filter(Boolean);
            if (candidates.some((candidate) => namesMatch(candidate, normalizedTarget))) {
              return clickFilterControl(label);
            }
          }

          const buttons = Array.from(document.querySelectorAll(`button[id^="${idPrefix}"]`));
          for (const button of buttons) {
            const candidate = norm(button.id.replace(new RegExp(`^${idPrefix}`), ''));
            if (namesMatch(candidate, normalizedTarget) && button instanceof HTMLElement) {
              button.click();
              return true;
            }
          }
        }

        for (const sidebar of sidebars) {
          const checkboxRows = Array.from(sidebar.querySelectorAll('button[role="checkbox"], input[type="checkbox"]'));
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
            if (!candidates.some((candidate) => namesMatch(candidate, normalizedTarget))) {
              continue;
            }
            if (checkbox instanceof HTMLButtonElement) {
              if (
                checkbox.getAttribute('data-state') === 'checked' ||
                checkbox.getAttribute('aria-checked') === 'true'
              ) {
                return true;
              }
              checkbox.click();
              return true;
            }
            if (checkbox instanceof HTMLInputElement && !checkbox.checked) {
              checkbox.click();
            }
            return true;
          }
        }

        return false;
      }, { normalizedTarget, idPrefixes })
      .catch(() => false);

    if (selected) {
      await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => undefined);
      await page.waitForTimeout(1200);
      return true;
    }

    await scrollManufacturerSidebarStep(page);
  }

  return tryPlaywrightManufacturerFilterSelect(page, targetName);
}

async function ensureManufacturerCategoryLine(page, categoryName) {
  return findAndSelectManufacturerFilter(page, 'CATEGORY', categoryName);
}

async function ensureManufacturerSeries(page, seriesName) {
  return findAndSelectManufacturerFilter(page, 'SERIES', seriesName);
}

async function ensureManufacturerBrand(page, brandName) {
  return findAndSelectManufacturerFilter(page, 'BRAND', brandName);
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
        return text === 'CATEGORY' || text === 'SERIES' || text === 'BRAND';
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

async function waitForManufacturerStatusTabs(page, tabLabel, timeoutMs = 20_000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const found = await page
      .evaluate((label) => {
        const norm = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        return Array.from(document.querySelectorAll('button')).some((button) => {
          const spans = Array.from(button.querySelectorAll('span'));
          return spans.some((span) => norm(span.textContent) === label) || norm(button.textContent).startsWith(label);
        });
      }, tabLabel)
      .catch(() => false);
    if (found) {
      return true;
    }
    await page.waitForTimeout(400);
  }

  return false;
}

async function clickManufacturerStatusTabWhenReady(page, tabLabel) {
  await waitForManufacturerStatusTabs(page, tabLabel, 20_000);
  return clickExactManufacturerStatusTabForFilters(page, tabLabel);
}

async function gotoManufacturerWithCategoryFilters(page, baseUrl, manufacturerId, categoryIds, tabLabel = 'In-Stock') {
  const uniqueIds = [...new Set(categoryIds.map((id) => String(id || '').trim()).filter(Boolean))];
  const query = uniqueIds.map((id) => `manufacturerCategoryId=${encodeURIComponent(id)}`).join('&');
  const url = query
    ? `${baseUrl}/retailer/manufacturers/${manufacturerId}?${query}`
    : `${baseUrl}/retailer/manufacturers/${manufacturerId}`;

  await gotoWithTimeout(page, url, 45_000);
  await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => undefined);
  await page.waitForTimeout(1200);

  let tabClicked = await clickManufacturerStatusTabWhenReady(page, tabLabel);
  if (!tabClicked) {
    await gotoWithTimeout(page, `${baseUrl}/retailer/manufacturers/${manufacturerId}`, 45_000);
    await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => undefined);
    await page.waitForTimeout(800);
    tabClicked = await clickManufacturerStatusTabWhenReady(page, tabLabel);
    if (tabClicked && query) {
      await gotoWithTimeout(page, url, 45_000);
      await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => undefined);
      await page.waitForTimeout(1200);
      tabClicked = await clickManufacturerStatusTabWhenReady(page, tabLabel);
    }
  }

  if (!tabClicked) {
    throw new Error(`Could not find manufacturer status tab: ${tabLabel}`);
  }

  await page.waitForTimeout(800);
}

function parseManufacturerPmkCategoryIdFromHtml(html) {
  const text = String(html || '');
  const fromManufacturerCategory = text.match(
    /"manufacturerCategoryId":(\d+),"manufacturerCategoryName":"Plastic Model Kits"/,
  );
  if (fromManufacturerCategory) {
    return String(fromManufacturerCategory[1]);
  }

  const fromCategories = text.match(/\{"id":(\d+),"name":"Plastic Model Kits"/);
  if (fromCategories) {
    return String(fromCategories[1]);
  }

  return null;
}

async function resolveManufacturerPmkCategoryId(page) {
  for (const filterTab of ['CATEGORY', 'BRAND', 'SERIES']) {
    const items = await scrapeManufacturerSidebarFilterItems(page, filterTab);
    const hit = items.find(
      (item) => normalizeManufacturerFilterName(item.name) === normalizeManufacturerFilterName('Plastic Model Kits'),
    );
    if (hit?.category_id) {
      return String(hit.category_id);
    }
  }

  const html = await page.content().catch(() => '');
  const fromHtml = parseManufacturerPmkCategoryIdFromHtml(html);
  if (fromHtml) {
    return fromHtml;
  }

  const selected = await findAndSelectManufacturerFilter(page, 'CATEGORY', 'Plastic Model Kits');
  if (!selected) {
    return null;
  }

  await page.waitForTimeout(1000);

  const urlIds = [...page.url().matchAll(/manufacturerCategoryId=(\d+)/g)].map((match) => match[1]);
  if (urlIds.length > 0) {
    return String(urlIds[urlIds.length - 1]);
  }

  const fromChecked = await page
    .evaluate(() => {
      const checked = document.querySelector(
        'button[role="checkbox"][data-state="checked"], button[role="checkbox"][aria-checked="true"], input[type="checkbox"]:checked',
      );
      const id = checked?.getAttribute('id') || '';
      const match = id.match(/^(category|brand|series)-(\d+)$/);
      return match ? match[2] : null;
    })
    .catch(() => null);
  if (fromChecked) {
    return String(fromChecked);
  }

  const htmlAfter = await page.content().catch(() => '');
  return parseManufacturerPmkCategoryIdFromHtml(htmlAfter);
}

async function resolveManufacturerCategoryFilterId(page, filterName) {
  for (const filterTab of ['CATEGORY', 'BRAND', 'SERIES']) {
    const items = await scrapeManufacturerSidebarFilterItems(page, filterTab);
    const hit = items.find(
      (item) => normalizeManufacturerFilterName(item.name) === normalizeManufacturerFilterName(filterName),
    );
    if (hit?.category_id) {
      return String(hit.category_id);
    }
  }

  const selected = await findAndSelectManufacturerFilter(page, 'CATEGORY', filterName);
  if (!selected) {
    return null;
  }

  await page.waitForTimeout(600);
  return page
    .evaluate(() => {
      const checked = document.querySelector(
        'button[role="checkbox"][data-state="checked"], button[role="checkbox"][aria-checked="true"]',
      );
      const id = checked?.getAttribute('id') || '';
      const match = id.match(/^(category|brand|series)-(\d+)$/);
      return match ? match[2] : null;
    })
    .catch(() => null);
}

async function scrapeManufacturerSidebarFilterItems(page, filterTab) {
  const tabClicked = await clickManufacturerSidebarFilterTab(page, filterTab);
  if (!tabClicked) {
    // eslint-disable-next-line no-console
    console.log(`[plamod] sidebar filter tab click failed tab=${filterTab}`);
    return [];
  }

  await scrollManufacturerSidebarFilters(page);

  await page.waitForTimeout(500);

  const labelCount = await page
    .evaluate(
      () =>
        document.querySelectorAll(
          'label[for^="category-"], label[for^="brand-"], label[for^="series-"], span[title]',
        ).length,
    )
    .catch(() => 0);
  // eslint-disable-next-line no-console
  console.log(`[plamod] sidebar dom labels tab=${filterTab} count=${labelCount}`);

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
        const instockCount = Number.isFinite(badges[0]) ? badges[0] : null;
        return {
          instock_count: instockCount,
          preorder_count: Number.isFinite(badges[0]) ? badges[0] : null,
          other_count: Number.isFinite(badges[1]) ? badges[1] : null,
        };
      };

      const idPrefixes = ['series-', 'category-', 'brand-'];

      const ingestSidebarRow = (rowRoot) => {
        const label = rowRoot.querySelector(
          'label[title], label[for^="series-"], label[for^="category-"], label[for^="brand-"]',
        );
        const span = rowRoot.querySelector('span[title], span.text-sm');
        let name = norm(label?.getAttribute('title') || span?.getAttribute('title') || label?.textContent || span?.textContent || '');
        name = name.replace(/\s+\d+(?:\s+\d+)?\s*$/u, '').trim();
        if (!name || seen.has(name)) {
          return;
        }
        seen.add(name);

        const forAttr = String(label?.getAttribute('for') || '');
        const idMatch = forAttr.match(/^(category|brand|series)-(\d+)$/);
        const rowWrap = rowRoot.closest('.flex.items-center.justify-between') || rowRoot.parentElement;
        out.push({
          name,
          category_id: idMatch ? idMatch[2] : null,
          id_prefix: idMatch ? idMatch[1] : null,
          ...parseBadgeNumbers(rowWrap),
        });
      };

      for (const prefix of idPrefixes) {
        document.querySelectorAll(`label[for^="${prefix}"]`).forEach((label) => {
          ingestSidebarRow(label.closest('.flex.items-center') || label.parentElement);
        });
      }

      document.querySelectorAll('label[for^="series-"], label[for^="category-"], label[for^="brand-"]').forEach((label) => {
        ingestSidebarRow(label.closest('.flex.items-center') || label.parentElement);
      });

      document.querySelectorAll('span[title]').forEach((span) => {
        const row = span.closest('.flex.items-center.justify-between, .flex.items-center.space-x-2, .flex.items-center');
        if (!row) {
          return;
        }
        const name = norm(span.getAttribute('title') || span.textContent || '').replace(/\s+\d+(?:\s+\d+)?\s*$/u, '').trim();
        if (!name || seen.has(name)) {
          return;
        }
        seen.add(name);
        const label = row.querySelector('label[for^="category-"], label[for^="brand-"], label[for^="series-"]');
        const forAttr = String(label?.getAttribute('for') || '');
        const idMatch = forAttr.match(/^(category|brand|series)-(\d+)$/);
        const rowWrap = row.closest('.flex.items-center.justify-between') || row.parentElement;
        out.push({
          name,
          category_id: idMatch ? idMatch[2] : null,
          id_prefix: idMatch ? idMatch[1] : null,
          ...parseBadgeNumbers(rowWrap),
        });
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

    const pricedSkuMatches = [
      ...text.matchAll(
        /"sku"\s*:\s*"([^"]+)"[\s\S]{0,2000}?"(?:stockPrice|stock_price|priceStock|inStockPrice|in_stock_price|unitPrice|listPrice|retailPrice|price)"\s*:\s*"?([0-9]+(?:\.[0-9]{1,2})?)"?/gi,
      ),
    ];
    for (const match of pricedSkuMatches) {
      const sku = String(match[1] || '').trim();
      const price = String(match[2] || '').trim();
      if (!sku || !price) {
        continue;
      }
      const row = rowsBySku.get(sku) || { sku };
      if (!String(row.price_stock || '').trim()) {
        row.price_stock = price;
      }
      rowsBySku.set(sku, row);
    }

    const stockPriceFieldMatches = [
      ...text.matchAll(
        /"(?:stockPrice|stock_price|inStockPrice|in_stock_price|priceStock)"\s*:\s*"?([0-9]+(?:\.[0-9]{1,2})?)"?[\s\S]{0,400}?"sku"\s*:\s*"([^"]+)"/gi,
      ),
    ];
    for (const match of stockPriceFieldMatches) {
      const price = String(match[1] || '').trim();
      const sku = String(match[2] || '').trim();
      if (!sku || !price) {
        continue;
      }
      const row = rowsBySku.get(sku) || { sku };
      if (!String(row.price_stock || '').trim()) {
        row.price_stock = price;
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
    let bodyFull = '';
    try {
      if (
        contentType.includes('json') ||
        contentType.includes('text/csv') ||
        contentType.includes('text/plain') ||
        contentType.includes('text/x-component')
      ) {
        const fullBody = await response.text();
        bodyFull = fullBody.length <= 8_000_000 ? fullBody : '';
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
      body_full: bodyFull,
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
    let cardContainer = anchor;
    for (let depth = 0; depth < 18; depth += 1) {
      if (!container.parentElement) {
        break;
      }
      container = container.parentElement;
      const containerText = norm(container.textContent || '');
      if (containerText.includes('SKU:')) {
        cardContainer = container;
      }
      if (containerText.includes('SKU:') && /IN[- ]?STOCK/i.test(containerText) && /PRICE/i.test(containerText)) {
        cardContainer = container;
        break;
      }
      if (container.tagName === 'MAIN' || container.tagName === 'BODY') {
        break;
      }
    }
    container = cardContainer;

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
    let priceStock = '';
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

    const stockHeader = Array.from(container.querySelectorAll('thead td, thead th, td, th, div, span')).find((node) =>
      /(?:in[- ]?stock|^stock$|stock price)/i.test(norm(node.textContent)),
    );
    if (stockHeader) {
      const table = stockHeader.closest('table') || stockHeader.closest('[class*="product"], article, li');
      const priceNode = table?.querySelector('[class*="price"], .font-bold, .text-lg, .text-2xl');
      if (priceNode) {
        const parsed = norm(priceNode.textContent).match(/([0-9]+\.[0-9]{2})/);
        if (parsed?.[1]) {
          priceStock = parsed[1];
        }
      }
    }

    if (!priceStock) {
      const inStockBlocks = Array.from(container.querySelectorAll('div, section, aside, td')).filter((el) => {
        const blockText = norm(el.textContent || '');
        return blockText.length > 0 && blockText.length < 900 && /IN[- ]?STOCK/i.test(blockText) && /PRICE/i.test(blockText);
      });
      for (const block of inStockBlocks) {
        const hit = norm(block.textContent).match(/PRICE\s*:?\s*\$?\s*([0-9]+\.[0-9]{2})/i);
        if (hit?.[1]) {
          priceStock = hit[1];
          break;
        }
      }
    }

    if (!priceStock) {
      const containerText = norm(container.textContent || '');
      const parsedFromCard = containerText.match(
        /IN[- ]?STOCK[\s\S]{0,500}?PRICE\s*:?\s*\$?\s*([0-9]+\.[0-9]{2})/i,
      );
      if (parsedFromCard?.[1]) {
        priceStock = parsedFromCard[1];
      }
    }

    if (!priceStock) {
      const containerText = norm(container.textContent || '');
      const stockPriceField = containerText.match(/Stock Price\s*:?\s*\$?\s*([0-9]+\.[0-9]{2})/i);
      if (stockPriceField?.[1]) {
        priceStock = stockPriceField[1];
      }
    }

    if (!priceStock) {
      const priceLabels = Array.from(container.querySelectorAll('div, span, p, td, th, label')).filter((node) =>
        /^PRICE:?$/i.test(norm(node.textContent)),
      );
      for (const label of priceLabels) {
        const blockText = norm(label.closest('div')?.textContent || label.parentElement?.textContent || '');
        if (/PO\s*PRICE|PREORDER OFFER/i.test(blockText) && !/IN[- ]?STOCK/i.test(blockText)) {
          continue;
        }
        const parentHit = blockText.match(/PRICE\s*:?\s*\$?\s*([0-9]+\.[0-9]{2})/i);
        if (parentHit?.[1]) {
          priceStock = parentHit[1];
          break;
        }
        let sibling = label.nextElementSibling;
        for (let hop = 0; hop < 4 && sibling; hop += 1) {
          const siblingHit = norm(sibling.textContent).match(/\$?\s*([0-9]+\.[0-9]{2})/);
          if (siblingHit?.[1]) {
            priceStock = siblingHit[1];
            break;
          }
          sibling = sibling.nextElementSibling;
        }
        if (priceStock) {
          break;
        }
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

    const stockPrice = fields['Stock Price'] || fields.Stock || priceStock || '';
    const priceStockValue = stockPrice ? norm(stockPrice).replace(/[^0-9.]/g, '') : '';

    seen.add(sku);
    rows.push({
      sku,
      barcode: fields.Barcode || '',
      product_name: productName,
      series,
      release_date: fields['Release Date'] || fields.Release || '',
      manufacturer,
      category,
      price_stock: priceStockValue,
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
  const target = Math.max(0, Number(expectedCount) || 0);
  const baseStaleRounds = target > 500 ? 40 : target > 100 ? 20 : 16;

  await page.evaluate(() => window.scrollTo(0, 0)).catch(() => undefined);
  await page.waitForTimeout(500);

  let lastLinkCount = 0;

  for (let round = 0; round < 320; round += 1) {
    const batch = await page.evaluate(extractManufacturerPreorderCardsFromDocument);
    const domLinkCount = await page
      .evaluate(() => document.querySelectorAll('a[href*="/retailer/products/"]').length)
      .catch(() => 0);
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

    if (target > 0 && rowsBySku.size >= target) {
      break;
    }

    const closeToTarget = target > 0 && rowsBySku.size >= Math.floor(target * 0.75) && rowsBySku.size < target;
    const staleLimit = closeToTarget ? Math.max(baseStaleRounds, 64) : baseStaleRounds;

    const linkDelta = domLinkCount - lastLinkCount;
    lastLinkCount = domLinkCount;

    if (newRows === 0 && linkDelta <= 0) {
      staleRounds += 1;
      if (staleRounds >= staleLimit) {
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
            node.scrollTop += Math.max(800, Math.floor(window.innerHeight * 0.9));
          }
        });
      })
      .catch(() => undefined);
    if (expectedCount > 500 && round % 4 === 3) {
      await page.keyboard.press('End').catch(() => undefined);
    }
    await page.waitForTimeout(closeToTarget ? 900 : expectedCount > 500 ? 900 : 500);
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

function manufacturerExportTabIsInStock(tabLabel) {
  return String(tabLabel || '')
    .trim()
    .toLowerCase()
    .includes('in-stock');
}

function parseInStockPriceFromCardText(text) {
  const normalized = String(text || '').replace(/\s+/g, ' ').trim();
  if (normalized === '') {
    return '';
  }

  const inStockMatch = normalized.match(
    /IN[- ]?STOCK[\s\S]{0,500}?PRICE\s*:?\s*\$?\s*([0-9]+\.[0-9]{2})/i,
  );
  if (inStockMatch?.[1]) {
    return inStockMatch[1];
  }

  const stockPriceLabel = normalized.match(/Stock Price\s*:?\s*\$?\s*([0-9]+\.[0-9]{2})/i);
  if (stockPriceLabel?.[1]) {
    return stockPriceLabel[1];
  }

  return '';
}

function isPreorderOnlyPriceBlock(blockText) {
  const normalized = String(blockText || '').replace(/\s+/g, ' ').trim();
  if (/IN[- ]?STOCK/i.test(normalized)) {
    return false;
  }

  return /PO\s*PRICE|PREORDER OFFER/i.test(normalized);
}

function manufacturerRowNeedsPdpEnrich(row, tabLabel = 'Preorder') {
  const needsPrice = manufacturerExportTabIsInStock(tabLabel)
    ? !String(row?.price_stock || '').trim()
    : !String(row?.price_preorder || '').trim();

  if (manufacturerExportTabIsInStock(tabLabel)) {
    return needsPrice;
  }

  return needsPrice || !String(row?.image_url || '').trim();
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
    const preorderOffers = [];
    const offerBlocks = [];
    for (const el of document.querySelectorAll('div')) {
      const blockText = norm(el.textContent || '');
      if (!/PREORDER OFFER/i.test(blockText) || !/PO\s*PRICE/i.test(blockText) || blockText.length >= 700) {
        continue;
      }
      const childAlsoMatches = Array.from(el.querySelectorAll('div')).some((child) => {
        if (child === el) {
          return false;
        }
        const childText = norm(child.textContent || '');
        return /PREORDER OFFER/i.test(childText) && /PO\s*PRICE/i.test(childText) && childText.length < 700;
      });
      if (childAlsoMatches) {
        continue;
      }
      offerBlocks.push(el);
    }

    for (const offerBlock of offerBlocks) {
      const offerText = norm(offerBlock.textContent || '');
      const offerIdMatch = offerText.match(/OFFER\s+(\d+)/i);
      const orderedMatch = offerText.match(/ORDERED:\s*(\d+)/i);
      const etaMatch = offerText.match(/ETA:\s*([A-Za-z]{3,9}\s+\d{1,2})/i);
      const poHit = offerText.match(/PO\s*PRICE\s*\$?\s*([0-9]+\.[0-9]{2})/i);
      const closingMatch =
        offerText.match(/Closing(?:\s+\w+)?\s*\(\s*([A-Za-z]{3,9}\s+\d{1,2})\s*\)/i) ||
        offerText.match(/Closing(?:\s+\w+)?\s+([A-Za-z]{3,9}\s+\d{1,2})/i);
      let quantity = orderedMatch?.[1] ? String(parseInt(orderedMatch[1], 10)) : '';
      if (!quantity) {
        const totalLabel = Array.from(offerBlock.querySelectorAll('div, span')).find(
          (node) => norm(node.textContent) === 'TOTAL',
        );
        if (totalLabel) {
          const qtyNode =
            totalLabel.previousElementSibling ||
            totalLabel.parentElement?.querySelector('.text-2xl, .text-xl, .text-3xl, .font-bold, .font-semibold');
          const qtyText = norm(qtyNode?.textContent || '');
          if (/^\d+$/.test(qtyText)) {
            quantity = qtyText;
          }
        }
      }
      if (!/^\d+$/.test(quantity)) {
        quantity = '0';
      }
      preorderOffers.push({
        offer_id: offerIdMatch?.[1] || '',
        quantity,
        eta_date: etaMatch?.[1] || '',
        po_due_date: closingMatch?.[1] || '',
        price_preorder: poHit?.[1] || '',
      });
    }

    const firstOffer = preorderOffers.find((offer) => Number(offer.quantity) > 0) || preorderOffers[0];
    if (firstOffer) {
      pricePreorder = String(firstOffer.price_preorder || '');
      quantityPreorder = String(firstOffer.quantity || '');
      if (!etaDate && firstOffer.eta_date) {
        etaDate = firstOffer.eta_date;
      }
      if (!poDueDate && firstOffer.po_due_date) {
        poDueDate = firstOffer.po_due_date;
      }
    }

    const committedQty = preorderOffers.reduce((sum, offer) => sum + Number(offer.quantity || 0), 0);
    if (committedQty > 0) {
      quantityPreorder = String(committedQty);
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

    let stockPrice = '';
    const inStockBlocks = Array.from(document.querySelectorAll('div, section, aside')).filter((el) => {
      const blockText = norm(el.textContent || '');
      return blockText.length > 0 && blockText.length < 1200 && /IN[- ]?STOCK/i.test(blockText) && /PRICE/i.test(blockText);
    });
    for (const block of inStockBlocks) {
      const hit = norm(block.textContent).match(/PRICE\s*:?\s*\$?\s*([0-9]+\.[0-9]{2})/i);
      if (hit?.[1]) {
        stockPrice = hit[1];
        break;
      }
    }

    const stockMatch =
      stockPrice ||
      (text.match(/IN[- ]?STOCK[\s\S]{0,500}?PRICE\s*:?\s*\$?\s*([0-9]+\.[0-9]{2})/i)?.[1] || '') ||
      (text.match(/Stock Price[^$]{0,40}\$\s*([0-9]+\.[0-9]{2})/i)?.[1] || '') ||
      (text.match(/Stock Price\s*\$?\s*([0-9]+(?:\.[0-9]{2})?)/i)?.[1] || '');
    stockPrice =
      stockMatch || (fields['Stock Price'] ? norm(fields['Stock Price']).replace(/[^0-9.]/g, '') : '');
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
      preorder_offers: preorderOffers,
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

function manufacturerRowEnrichPriority(row, tabLabel = 'Preorder') {
  let score = 0;
  const priceField = manufacturerExportTabIsInStock(tabLabel) ? 'price_stock' : 'price_preorder';
  if (!String(row?.[priceField] || '').trim()) {
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

async function enrichSparseManufacturerRowsFromPdp(
  page,
  baseUrl,
  context,
  rowsBySku,
  tabLabel = 'Preorder',
  onProgress = null,
  sessionRecovery = null,
) {
  const browser = { page, context };
  const inStockTab = manufacturerExportTabIsInStock(tabLabel);
  const maxEnrich = inStockTab
    ? Number.parseInt(process.env.PLAMOD_INSTOCK_PDP_ENRICH_MAX || '120', 10)
    : Number.parseInt(process.env.PLAMOD_MANUFACTURER_PDP_ENRICH_MAX || '30', 10);
  const candidates = [...rowsBySku.entries()]
    .filter(([, row]) => manufacturerRowNeedsPdpEnrich(row, tabLabel))
    .sort(([skuA, rowA], [skuB, rowB]) => {
      const scoreDiff = manufacturerRowEnrichPriority(rowB, tabLabel) - manufacturerRowEnrichPriority(rowA, tabLabel);
      if (scoreDiff !== 0) {
        return scoreDiff;
      }
      return String(skuA).localeCompare(String(skuB));
    });

  let enriched = 0;
  let consecutiveLoginFailures = 0;
  let consecutiveBrowserClosed = 0;
  const enrichBudgetMs = inStockTab
    ? Number.parseInt(process.env.PLAMOD_INSTOCK_PDP_ENRICH_BUDGET_MS || '600000', 10)
    : Number.parseInt(process.env.PLAMOD_MANUFACTURER_PDP_ENRICH_BUDGET_MS || '120000', 10);
  const enrichStarted = Date.now();

  async function reacquireBrowserSession() {
    if (!sessionRecovery) {
      return false;
    }
    // eslint-disable-next-line no-console
    console.log('[plamod] pdp enrich reacquiring browser session after crash');
    const session = await reacquireManufacturerInstockExportSession(
      sessionRecovery.baseUrl,
      sessionRecovery.profileDir,
      sessionRecovery.manufacturerId,
    );
    browser.page = session.page;
    browser.context = session.context;
    consecutiveBrowserClosed = 0;
    return true;
  }

  async function scrapePdpPatch(sku) {
    return scrapePreorderPdpFields(browser.page, baseUrl, browser.context, sku);
  }

  if (candidates.length > 0 && (await looksLikeSignInPage(browser.page))) {
    try {
      await ensureManufacturerSession(browser.page, baseUrl, browser.context);
    } catch (e) {
      // eslint-disable-next-line no-console
      console.log(`[plamod] pdp enrich pre-login failed msg=${String(e?.message || 'unknown')}`);
    }
  }

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
      let patch;
      try {
        patch = await scrapePdpPatch(sku);
      } catch (firstError) {
        const message = String(firstError?.message || 'unknown');
        if (isPlaywrightBrowserClosedError(firstError)) {
          consecutiveBrowserClosed += 1;
          if (consecutiveBrowserClosed <= 3 && (await reacquireBrowserSession())) {
            patch = await scrapePdpPatch(sku);
          } else {
            throw firstError;
          }
        } else if (/login failed|sign-in/i.test(message)) {
          await ensureManufacturerSession(browser.page, baseUrl, browser.context);
          patch = await scrapePdpPatch(sku);
        } else {
          await browser.page.waitForTimeout(800).catch(() => undefined);
          patch = await scrapePdpPatch(sku);
        }
      }

      consecutiveLoginFailures = 0;
      consecutiveBrowserClosed = 0;
      const merged = mergeManufacturerRow(row, patch);
      rowsBySku.set(sku, merged);
      if (!manufacturerRowNeedsPdpEnrich(merged, tabLabel)) {
        enriched += 1;
        if (typeof onProgress === 'function') {
          onProgress(enriched, Math.min(candidates.length, maxEnrich));
        }
        const price = manufacturerExportTabIsInStock(tabLabel)
          ? merged.price_stock || '-'
          : merged.price_preorder || '-';
        // eslint-disable-next-line no-console
        console.log(`[plamod] pdp enrich ok sku=${sku} price=${price} qty=${merged.quantity_preorder || '-'}`);
      } else {
        const price = manufacturerExportTabIsInStock(tabLabel)
          ? patch.price_stock || '-'
          : patch.price_preorder || '-';
        // eslint-disable-next-line no-console
        console.log(`[plamod] pdp enrich sparse sku=${sku} price=${price} qty=${patch.quantity_preorder || '-'}`);
      }
    } catch (e) {
      const message = String(e?.message || 'unknown');
      // eslint-disable-next-line no-console
      console.log(`[plamod] pdp enrich failed sku=${sku} msg=${message}`);
      if (isPlaywrightBrowserClosedError(e)) {
        consecutiveBrowserClosed += 1;
        if (consecutiveBrowserClosed <= 3 && (await reacquireBrowserSession())) {
          try {
            const patch = await scrapePdpPatch(sku);
            consecutiveBrowserClosed = 0;
            const merged = mergeManufacturerRow(row, patch);
            rowsBySku.set(sku, merged);
            if (!manufacturerRowNeedsPdpEnrich(merged, tabLabel)) {
              enriched += 1;
              if (typeof onProgress === 'function') {
                onProgress(enriched, Math.min(candidates.length, maxEnrich));
              }
              // eslint-disable-next-line no-console
              console.log(`[plamod] pdp enrich ok sku=${sku} price=${merged.price_stock || merged.price_preorder || '-'} qty=${merged.quantity_preorder || '-'}`);
            }
            continue;
          } catch (retryError) {
            // eslint-disable-next-line no-console
            console.log(`[plamod] pdp enrich failed sku=${sku} msg=${String(retryError?.message || 'unknown')}`);
          }
        }
        if (consecutiveBrowserClosed >= 3) {
          // eslint-disable-next-line no-console
          console.log('[plamod] pdp enrich stopping early after browser crash cascade');
          break;
        }
      } else if (/login failed|sign-in/i.test(message)) {
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

  return browser;
}

async function tryDownloadCsvViaCapturedResponses(page, captured, destPath) {
  for (const entry of [...captured].reverse()) {
    if (!/csv|export/i.test(entry.url) && !entry.content_type.includes('csv')) {
      continue;
    }
    const body = entry.body_full || entry.body_preview || '';
    if (body.length < 20) {
      continue;
    }
    if (!body.includes('SKU') && !body.includes('Product Name')) {
      continue;
    }
    fs.writeFileSync(destPath, body, 'utf8');
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
    filterTabHint = '',
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
    await ensureLoggedIn(page, baseUrl, context, debugSku);
    await gotoWithTimeout(page, manufacturerUrl, 45_000);
    await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => undefined);
    await page.waitForTimeout(800);
  }
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

  if (manufacturerExportTabIsInStock(tabLabel) && !seriesName && !categoryLineName) {
    await ensureManufacturerPlasticModelKitsOnly(page);
  }

  if (seriesName || categoryLineName) {
    let selected = false;
    if (filterTabHint === 'BRAND') {
      selected = await ensureManufacturerBrand(page, seriesName || categoryLineName);
    } else if (filterTabHint === 'CATEGORY') {
      selected = await ensureManufacturerCategoryLine(page, categoryLineName || seriesName);
    } else if (seriesName) {
      selected = await ensureManufacturerSeries(page, seriesName);
      if (!selected) {
        selected = await ensureManufacturerBrand(page, seriesName);
      }
      if (!selected) {
        selected = await ensureManufacturerCategoryLine(page, seriesName);
      }
    } else if (categoryLineName) {
      selected = await ensureManufacturerCategoryLine(page, categoryLineName);
    }
    if (!selected) {
      const debug = await writeDebugSnapshot(page, debugSku, 'missing-series');
      return {
        ok: false,
        error_message: `Could not select manufacturer filter: ${seriesName || categoryLineName}`,
        debug,
        manufacturer_url: manufacturerUrl,
        duration_ms: Date.now() - started,
      };
    }
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

  // eslint-disable-next-line no-console
  console.log(
    `[plamod] manufacturer export csv_rows=${parsed.rows.size} expected=${expectedCount} mode=${exportMode}`,
  );

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

  for (const [sku, netRow] of skuCollector.rowsBySku.entries()) {
    const existing = rowsBySku.get(sku) || { sku };
    rowsBySku.set(sku, mergeManufacturerRow(existing, netRow));
  }

  if (expectedCount > 0 && rowsBySku.size < expectedCount) {
    await scrapeManufacturerPreorderRows(page, expectedCount, rowsBySku);
    // eslint-disable-next-line no-console
    console.log(
      `[plamod] manufacturer listing scrape fill shortfall expected=${expectedCount} got=${rowsBySku.size}`,
    );
  }

  if (manufacturerExportTabIsInStock(tabLabel)) {
    const missingStockPrice = [...rowsBySku.values()].some((row) => !String(row?.price_stock || '').trim());
    if (missingStockPrice) {
      await scrapeManufacturerPreorderRows(page, expectedCount, rowsBySku);
      // eslint-disable-next-line no-console
      console.log(
        `[plamod] in-stock listing scrape merged rows=${rowsBySku.size} with_stock=${[...rowsBySku.values()].filter((row) => String(row?.price_stock || '').trim()).length}`,
      );
    }
  }

  const skipPdpEnrich =
    String(process.env.PLAMOD_SKIP_PDP_ENRICH || '').toLowerCase() === 'true' ||
    String(process.env.PLAMOD_SKIP_PDP_ENRICH || '') === '1';
  const catalogIncomplete = expectedCount > 0 && rowsBySku.size < Math.floor(expectedCount * 0.95);

  if (!skipPdpEnrich && !catalogIncomplete) {
    await enrichSparseManufacturerRowsFromPdp(page, baseUrl, context, rowsBySku, tabLabel);
    exportMode = `${exportMode}+pdp_enrich`;
  } else if (catalogIncomplete) {
    // eslint-disable-next-line no-console
    console.log(
      `[plamod] skipping pdp enrich until catalog complete got=${rowsBySku.size} expected=${expectedCount}`,
    );
  }

  fs.writeFileSync(csvPath, manufacturerRowsToCsv(csvHeader, rowsBySku), 'utf8');
  parsed = parseSimpleCsvFile(csvPath);

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

const MANUFACTURER_CSV_HEADER = [
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

/**
 * @param {import('playwright').Page} page
 * @param {import('playwright').BrowserContext} context
 * @param {{
 *   expectedCount?: number,
 *   tempCsvPath: string,
 *   captured?: Array<{url: string, content_type: string, body_preview: string, body_full?: string}>,
 *   skuCollector?: ReturnType<typeof createManufacturerSkuCollector>,
 *   tabLabel?: string,
 * }} opts
 * @returns {Promise<Map<string, Record<string, string>>>}
 */
/**
 * Collect in-stock rows for one sidebar filter using the same path as per-series export.
 *
 * @param {import('playwright').Page} page
 * @param {import('playwright').BrowserContext} context
 * @param {{
 *   baseUrl: string,
 *   manufacturerId: string,
 *   tabLabel: string,
 *   filterName: string,
 *   filterTabHint?: string,
 *   filterCategoryId?: string|null,
 *   pmkCategoryId?: string|null,
 *   expectedCount: number,
 *   tempCsvPath: string,
 *   skuCollector: ReturnType<typeof createManufacturerSkuCollector>,
 *   captured: Array<{url: string, content_type: string, body_preview: string, body_full?: string}>,
 * }} opts
 * @returns {Promise<Map<string, Record<string, string>>>}
 */
async function ensureManufacturerSession(page, baseUrl, context) {
  if (!(await looksLikeSignInPage(page))) {
    return;
  }

  await ensureLoggedInQuick(page, baseUrl, context);
}

async function scrapeManufacturerListingRowsFast(page, expectedCount = 0, networkRowsBySku = null) {
  /** @type {Map<string, Record<string, string>>} */
  const rowsBySku = networkRowsBySku || new Map();
  let staleRounds = 0;
  const target = Math.max(0, Number(expectedCount) || 0);
  const maxRounds =
    target > 0 ? Math.min(400, Math.ceil(target / 2) + 24) : 48;
  const maxStaleRounds =
    target > 500 ? 40 : target > 100 ? 20 : target > 0 ? 16 : 8;
  const scrollWaitMs = target > 500 ? 500 : 400;

  await page.evaluate(() => window.scrollTo(0, 0)).catch(() => undefined);
  await page.waitForTimeout(300);

  let lastLinkCount = 0;

  for (let round = 0; round < maxRounds; round += 1) {
    const batch = await page.evaluate(extractManufacturerPreorderCardsFromDocument);
    const domLinkCount = await page
      .evaluate(() => document.querySelectorAll('a[href*="/retailer/products/"]').length)
      .catch(() => 0);
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

    if (target > 0 && rowsBySku.size >= target) {
      break;
    }

    const nearingPlateau = target > 100 && rowsBySku.size >= 80 && rowsBySku.size < target;
    const closeToTarget = target > 0 && rowsBySku.size >= Math.floor(target * 0.75) && rowsBySku.size < target;
    const staleLimit = closeToTarget
      ? Math.max(maxStaleRounds, 64)
      : nearingPlateau
        ? Math.max(maxStaleRounds, 48)
        : maxStaleRounds;

    const linkDelta = domLinkCount - lastLinkCount;
    lastLinkCount = domLinkCount;

    if (newRows === 0 && linkDelta <= 0) {
      staleRounds += 1;
      if (staleRounds >= staleLimit) {
        break;
      }
    } else {
      staleRounds = 0;
    }

    const waitMs = closeToTarget ? Math.max(scrollWaitMs, 900) : nearingPlateau ? Math.max(scrollWaitMs, 800) : scrollWaitMs;

    await page
      .evaluate(() => {
        window.scrollBy(0, Math.max(500, Math.floor(window.innerHeight * 0.85)));
        document.querySelectorAll('[data-radix-scroll-area-viewport], .overflow-auto, main').forEach((node) => {
          if (node instanceof HTMLElement) {
            node.scrollTop += Math.max(700, Math.floor(window.innerHeight * 0.85));
          }
        });
      })
      .catch(() => undefined);
    await page.waitForTimeout(waitMs);
  }

  return rowsBySku;
}

async function waitForListingApiResponse(page, timeoutMs = 6000) {
  await page
    .waitForResponse(
      (response) =>
        /plamod\.com/i.test(response.url()) &&
        /(manufacturer|product|search|graphql|retailer)/i.test(response.url()) &&
        response.status() === 200,
      { timeout: timeoutMs },
    )
    .catch(() => undefined);
  await page.waitForTimeout(350);
}

function manufacturerFilterElementId(filterTab, categoryId) {
  const cid = String(categoryId || '').trim();
  if (!cid) {
    return null;
  }
  const prefix =
    filterTab === 'BRAND' ? 'brand' : filterTab === 'SERIES' ? 'series' : 'category';
  return `${prefix}-${cid}`;
}

async function clearActiveManufacturerFilters(page, filterTab = 'BRAND') {
  const clearLabels =
    filterTab === 'BRAND'
      ? ['Clear Brands', 'Clear Brand', 'Clear Filters', 'Clear Filter']
      : filterTab === 'SERIES'
        ? ['Clear Series', 'Clear Filters', 'Clear Filter']
        : ['Clear Categories', 'Clear Category', 'Clear Filters', 'Clear Filter'];

  for (const label of clearLabels) {
    const clicked = await clickFirst(page, [`button:has-text("${label}")`]);
    if (clicked) {
      await waitForListingApiResponse(page, 4000);
      return;
    }
  }
}

async function selectManufacturerFilterByCategoryId(page, filterTab, categoryId) {
  const elementId = manufacturerFilterElementId(filterTab, categoryId);
  if (!elementId) {
    return false;
  }

  const tabClicked = await clickManufacturerSidebarFilterTab(page, filterTab);
  if (!tabClicked) {
    return false;
  }

  await resetManufacturerSidebarScroll(page);
  await page.waitForTimeout(150);

  for (let round = 0; round < 12; round += 1) {
    const clicked = await page
      .evaluate((id) => {
        const clickChecked = (node) => {
          if (!(node instanceof HTMLElement)) {
            return false;
          }
          if (
            node.getAttribute('data-state') === 'checked' ||
            node.getAttribute('aria-checked') === 'true'
          ) {
            return true;
          }
          node.click();
          return true;
        };

        const direct = document.getElementById(id);
        if (direct && clickChecked(direct)) {
          return true;
        }

        const label = document.querySelector(`label[for="${id}"]`);
        if (label instanceof HTMLElement) {
          const row = label.closest('.flex.items-center') || label.parentElement;
          const checkbox = row?.querySelector('button[role="checkbox"]');
          if (checkbox instanceof HTMLElement && clickChecked(checkbox)) {
            return true;
          }
          label.click();
          return true;
        }

        return false;
      }, elementId)
      .catch(() => false);

    if (clicked) {
      await waitForListingApiResponse(page, 6000);
      return true;
    }

    await scrollManufacturerSidebarStep(page);
    await page.waitForTimeout(120);
  }

  return false;
}

function mergeManufacturerRowMaps(baseRows, extraRows) {
  /** @type {Map<string, Record<string, string>>} */
  const merged = new Map(baseRows);
  for (const [sku, row] of extraRows.entries()) {
    merged.set(sku, mergeManufacturerRow(merged.get(sku) || { sku }, row));
  }
  return merged;
}

async function collectRowsFromDom(page, seedRows = null) {
  /** @type {Map<string, Record<string, string>>} */
  let rows = seedRows ? new Map(seedRows) : new Map();
  const batch = await page.evaluate(extractManufacturerPreorderCardsFromDocument);
  for (const row of batch) {
    if (!row?.sku) {
      continue;
    }
    rows.set(row.sku, mergeManufacturerRow(rows.get(row.sku) || { sku: row.sku }, row));
  }
  return rows;
}

async function applyManufacturerInstockFilter(page, opts) {
  const {
    baseUrl,
    manufacturerId,
    filterTab = 'BRAND',
    filterCategoryId = null,
    filterName,
    tabLabel = 'In-Stock',
  } = opts;

  if (filterCategoryId) {
    const url = `${baseUrl}/retailer/manufacturers/${manufacturerId}?manufacturerCategoryId=${encodeURIComponent(String(filterCategoryId))}`;
    await gotoWithTimeout(page, url, 30_000);
    await page.waitForLoadState('domcontentloaded', { timeout: 10_000 }).catch(() => undefined);
    await waitForListingApiResponse(page, 4000);
    await clickExactManufacturerStatusTabForFilters(page, tabLabel);
    await page.waitForTimeout(650);
    return true;
  }

  await clearActiveManufacturerFilters(page, filterTab);

  if (filterTab === 'BRAND') {
    return ensureManufacturerBrand(page, filterName);
  }
  if (filterTab === 'CATEGORY') {
    return ensureManufacturerCategoryLine(page, filterName);
  }
  return ensureManufacturerSeries(page, filterName);
}

async function scrapeManufacturerListingRowsDeep(page, expectedCount = 0, seedRows = null) {
  /** @type {Map<string, Record<string, string>>} */
  let rows = seedRows ? new Map(seedRows) : new Map();
  rows = await scrapeManufacturerListingRowsFast(page, expectedCount, rows);

  const target = Math.max(0, Number(expectedCount) || 0);
  const minRows = target <= 20 ? Math.max(1, target - 1) : Math.floor(target * 0.85);
  if (target > 0 && rows.size < minRows) {
    rows = await scrapeManufacturerPreorderRows(page, target, rows);
  }

  return rows;
}

async function tryManufacturerSliceCsvDownload(page, tempCsvPath) {
  const csvHandle = await findFirstHandle(page, [
    'a:has-text("CSV")',
    'button:has-text("CSV")',
    'a[href*="csv" i]',
    'a[href*="export" i]',
    '[data-testid="export-csv"]',
    'text=/^CSV$/i',
  ]);
  if (!csvHandle) {
    return null;
  }

  await downloadPreordersCsvFromPage(page, csvHandle, tempCsvPath);
  const parsed = parseSimpleCsvFile(tempCsvPath);
  return simpleCsvRowsToRecordMap(parsed, MANUFACTURER_CSV_HEADER);
}

async function recoverManufacturerInstockListingPage(page, baseUrl, manufacturerId, tabLabel = 'In-Stock') {
  await gotoWithTimeout(page, `${baseUrl}/retailer/manufacturers/${manufacturerId}`, 20_000);
  await page.waitForLoadState('domcontentloaded', { timeout: 10_000 }).catch(() => undefined);
  await page.waitForTimeout(250);
  await clickExactManufacturerStatusTabForFilters(page, tabLabel);
  await clearAllManufacturerFilters(page);
  await page.waitForTimeout(200);
}

function isPlaywrightBrowserClosedError(error) {
  const message = String(error?.message || error || '');
  return /target page, context or browser has been closed|browser has been closed|context or browser has been closed/i.test(
    message,
  );
}

async function reacquireManufacturerInstockExportSession(baseUrl, profileDir, manufacturerId) {
  await closeWarmManufacturerSession();
  const session = await acquireWarmManufacturerSession(baseUrl, profileDir, manufacturerId);
  await ensureLoggedInQuick(session.page, baseUrl, session.context);
  return session;
}

function manufacturerInstockFilterLooksBleeding(tabBadgeCount, targetCount) {
  const badge = Math.max(0, Number(tabBadgeCount) || 0);
  const target = Math.max(0, Number(targetCount) || 0);
  if (target <= 0 || badge <= 0) {
    return false;
  }

  return badge > Math.max(target * 3, target + 50);
}

async function recoverManufacturerInstockSliceAfterFilterBleed(page, context, opts) {
  const {
    baseUrl,
    manufacturerId,
    tabLabel,
    filterName,
    filterTab = 'BRAND',
    filterCategoryId = null,
    expectedCount = 0,
  } = opts;
  const targetCount = Math.max(0, Number(expectedCount) || 0);

  // eslint-disable-next-line no-console
  console.log(
    `[plamod] instock slice filter bleed filter=${filterName} tab=${filterTab}; recovering listing + reapplying filter`,
  );

  await recoverManufacturerInstockListingPage(page, baseUrl, manufacturerId, tabLabel);
  const selected = await applyManufacturerInstockFilter(page, {
    baseUrl,
    manufacturerId,
    filterName,
    filterTab,
    filterCategoryId,
    tabLabel,
  });
  if (!selected) {
    throw new Error(`Could not re-select manufacturer filter after bleed: ${filterTab}/${filterName}`);
  }

  if (await looksLikeSignInPage(page)) {
    await ensureManufacturerSession(page, baseUrl, context);
    await recoverManufacturerInstockListingPage(page, baseUrl, manufacturerId, tabLabel);
    await applyManufacturerInstockFilter(page, {
      baseUrl,
      manufacturerId,
      filterName,
      filterTab,
      filterCategoryId,
      tabLabel,
    });
  }

  await page.waitForTimeout(1200);
  const minRows =
    targetCount <= 20 ? Math.max(1, targetCount - 1) : Math.floor(targetCount * 0.85);
  let rows = await collectRowsFromDom(page);
  if (rows.size < minRows) {
    rows = await scrollManufacturerListingRowsToTarget(page, targetCount, targetCount > 50 ? null : rows);
  }

  const tabBadgeText = await readManufacturerTabBadge(page, tabLabel);
  const tabBadgeCount = Number.parseInt(String(tabBadgeText || '').replace(/[^\d]/g, ''), 10) || 0;

  return { rows, tabBadgeCount, strategy: 'bleed_recovery' };
}

async function scrollManufacturerListingRowsToTarget(page, expectedCount = 0, seedRows = null) {
  const target = Math.max(0, Number(expectedCount) || 0);
  /** @type {Map<string, Record<string, string>>} */
  const rows = seedRows ? new Map(seedRows) : new Map();
  let staleRounds = 0;
  let lastLinkCount = 0;

  for (let round = 0; round < 400; round += 1) {
    const batch = await page.evaluate(extractManufacturerPreorderCardsFromDocument);
    let newRows = 0;
    for (const row of batch) {
      if (!row?.sku) {
        continue;
      }
      if (!rows.has(row.sku)) {
        rows.set(row.sku, row);
        newRows += 1;
      } else {
        rows.set(row.sku, mergeManufacturerRow(rows.get(row.sku) || { sku: row.sku }, row));
      }
    }

    if (target > 0 && rows.size >= target) {
      break;
    }

    const domLinkCount = await page
      .evaluate(() => document.querySelectorAll('a[href*="/retailer/products/"]').length)
      .catch(() => 0);
    const linkDelta = domLinkCount - lastLinkCount;
    lastLinkCount = domLinkCount;

    if (newRows === 0 && linkDelta <= 0) {
      staleRounds += 1;
      if (staleRounds >= 60) {
        break;
      }
    } else {
      staleRounds = 0;
    }

    await page
      .evaluate(() => {
        window.scrollBy(0, Math.max(900, Math.floor(window.innerHeight * 0.95)));
        document.querySelectorAll('[data-radix-scroll-area-viewport], .overflow-auto, main').forEach((node) => {
          if (node instanceof HTMLElement) {
            node.scrollTop += Math.max(1000, Math.floor(window.innerHeight * 0.95));
          }
        });
      })
      .catch(() => undefined);
    await page.waitForTimeout(700);
  }

  return rows;
}

async function collectManufacturerInstockSliceRowsFast(page, context, opts) {
  const {
    baseUrl,
    manufacturerId,
    tabLabel,
    filterName,
    filterTab = 'BRAND',
    filterCategoryId = null,
    expectedCount = 0,
    tempCsvPath = null,
  } = opts;

  const skuCollector = createManufacturerSkuCollector();
  attachPlamodNetworkCapture(page, skuCollector);

  const targetCount = Math.max(0, Number(expectedCount) || 0);
  const minRows =
    targetCount <= 20 ? Math.max(1, targetCount - 1) : Math.floor(targetCount * 0.85);

  if (await looksLikeSignInPage(page)) {
    await ensureManufacturerSession(page, baseUrl, context);
  }

  if (!filterCategoryId) {
    await recoverManufacturerInstockListingPage(page, baseUrl, manufacturerId, tabLabel);
  }

  const selected = await applyManufacturerInstockFilter(page, {
    baseUrl,
    manufacturerId,
    filterName,
    filterTab,
    filterCategoryId,
    tabLabel,
  });

  if (!selected) {
    await recoverManufacturerInstockListingPage(page, baseUrl, manufacturerId, tabLabel);
    const retried = await applyManufacturerInstockFilter(page, {
      baseUrl,
      manufacturerId,
      filterName,
      filterTab,
      filterCategoryId,
      tabLabel,
    });
    if (!retried) {
      throw new Error(`Could not select manufacturer filter: ${filterTab}/${filterName}`);
    }
  }

  if (await looksLikeSignInPage(page)) {
    await ensureManufacturerSession(page, baseUrl, context);
    await recoverManufacturerInstockListingPage(page, baseUrl, manufacturerId, tabLabel);
    await applyManufacturerInstockFilter(page, {
      baseUrl,
      manufacturerId,
      filterName,
      filterTab,
      filterCategoryId,
      tabLabel,
    });
  }

  let rows = await collectRowsFromDom(page);
  let strategy = 'dom';

  if (targetCount > 0 && rows.size === 0) {
    await page.waitForTimeout(1200);
    rows = await collectRowsFromDom(page);
    strategy = 'dom_retry';
  }

  if (rows.size < minRows) {
    const scrolled = await scrollManufacturerListingRowsToTarget(
      page,
      targetCount,
      targetCount > 50 ? null : rows,
    );
    rows = targetCount > 50 ? scrolled : mergeManufacturerRowMaps(rows, scrolled);
    strategy = 'deep_scroll';
  }

  if (rows.size < minRows && tempCsvPath) {
    try {
      const csvRows = await tryManufacturerSliceCsvDownload(page, tempCsvPath);
      if (csvRows && csvRows.size > 0) {
        rows = mergeManufacturerRowMaps(rows, csvRows);
        strategy = 'csv';
      }
    } catch (csvError) {
      // eslint-disable-next-line no-console
      console.log(
        `[plamod] instock slice csv failed filter=${filterName} msg=${String(csvError?.message || csvError)}`,
      );
    }
  }

  if (rows.size === 0) {
    await recoverManufacturerInstockListingPage(page, baseUrl, manufacturerId, tabLabel);
  }

  const tabBadgeText = await readManufacturerTabBadge(page, tabLabel);
  let tabBadgeCount = Number.parseInt(String(tabBadgeText || '').replace(/[^\d]/g, ''), 10) || 0;

  if (manufacturerInstockFilterLooksBleeding(tabBadgeCount, targetCount)) {
    const recovered = await recoverManufacturerInstockSliceAfterFilterBleed(page, context, {
      baseUrl,
      manufacturerId,
      tabLabel,
      filterName,
      filterTab,
      filterCategoryId,
      expectedCount: targetCount,
    });
    rows = recovered.rows;
    tabBadgeCount = recovered.tabBadgeCount;
    strategy = recovered.strategy;
  }

  for (const [sku, netRow] of skuCollector.rowsBySku.entries()) {
    rows.set(sku, mergeManufacturerRow(rows.get(sku) || { sku }, netRow));
  }

  const countMissingPrices = (rowMap) =>
    [...rowMap.values()].filter((row) => !String(row?.price_stock || '').trim()).length;

  let missingPriceCount = countMissingPrices(rows);
  if (instockSliceShouldRetryListingPrices(missingPriceCount, rows.size)) {
    // eslint-disable-next-line no-console
    console.log(
      `[plamod] instock slice retry missing prices filter=${filterName} missing=${missingPriceCount}`,
    );
    await page.waitForTimeout(600);
    const retryDomRows = await collectRowsFromDom(page);
    rows = mergeManufacturerRowMaps(rows, retryDomRows);
    for (const [sku, netRow] of skuCollector.rowsBySku.entries()) {
      rows.set(sku, mergeManufacturerRow(rows.get(sku) || { sku }, netRow));
    }
    missingPriceCount = countMissingPrices(rows);
  }

  const pricedRows = [...rows.values()].filter((row) => String(row?.price_stock || '').trim()).length;
  if (rows.size > 0 && pricedRows === 0) {
    // eslint-disable-next-line no-console
    console.log(
      `[plamod] instock slice missing prices filter=${filterName} rows=${rows.size} network_rows=${skuCollector.rowsBySku.size}`,
    );
  } else if (pricedRows > 0) {
    // eslint-disable-next-line no-console
    console.log(
      `[plamod] instock slice priced filter=${filterName} rows=${rows.size} with_stock=${pricedRows}`,
    );
  }

  return { rows, listingExpected: targetCount, tabBadgeCount, strategy };
}

async function waitForManufacturerListingSettle(page, expectedCount = 0, skuCollector = null) {
  const target = Math.max(0, Number(expectedCount) || 0);
  const deadline = Date.now() + (target > 0 ? Math.min(12_000, 4000 + target * 40) : 8000);

  while (Date.now() < deadline) {
    await page
      .waitForResponse(
        (response) => /plamod\.com/i.test(response.url()) && response.status() === 200,
        { timeout: 2500 },
      )
      .catch(() => undefined);

    const snapshot = await page
      .evaluate(() => ({
        products: document.querySelectorAll('a[href*="/retailer/products/"]').length,
        empty: /no products found/i.test(document.body?.textContent || ''),
      }))
      .catch(() => ({ products: 0, empty: true }));

    const networkRows = skuCollector?.rowsBySku?.size ?? 0;
    if (snapshot.products > 0 || networkRows > 0) {
      await page.waitForTimeout(800);
      return Math.max(snapshot.products, networkRows);
    }

    if (expectedCount > 0 && !snapshot.empty) {
      await page.waitForTimeout(800);
      return 0;
    }

    await page.waitForTimeout(750);
  }

  return skuCollector?.rowsBySku?.size ?? 0;
}

async function gatherManufacturerProductRowsFromCurrentView(page, context, opts) {
  const expectedCount = Number(opts.expectedCount ?? 0);
  const tempCsvPath = opts.tempCsvPath;
  const tabLabel = String(opts.tabLabel ?? 'In-Stock');
  const skuCollector = opts.skuCollector ?? createManufacturerSkuCollector();
  const captured = opts.captured ?? attachPlamodNetworkCapture(page, skuCollector);
  const csvHeader = MANUFACTURER_CSV_HEADER;

  let parsed = { header: csvHeader, rows: new Map() };

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
      await downloadPreordersCsvFromPage(page, csvHandle, tempCsvPath);
      parsed = parseSimpleCsvFile(tempCsvPath);
    } catch (e) {
      // eslint-disable-next-line no-console
      console.log(`[plamod] chunk csv download failed msg=${String(e?.message || 'unknown')}`);
    }
  }

  if (parsed.rows.size === 0 && (await tryDownloadCsvViaCapturedResponses(page, captured, tempCsvPath))) {
    parsed = parseSimpleCsvFile(tempCsvPath);
  }

  /** @type {Map<string, Record<string, string>>} */
  let rowsBySku = new Map();

  if (parsed.rows.size === 0) {
    rowsBySku = await scrapeManufacturerPreorderRows(page, expectedCount, skuCollector.rowsBySku);
  } else {
    rowsBySku = simpleCsvRowsToRecordMap(parsed, csvHeader);
  }

  for (const [sku, netRow] of skuCollector.rowsBySku.entries()) {
    const existing = rowsBySku.get(sku) || { sku };
    rowsBySku.set(sku, mergeManufacturerRow(existing, netRow));
  }

  if (expectedCount > 0 && rowsBySku.size < expectedCount) {
    await scrapeManufacturerPreorderRows(page, expectedCount, rowsBySku);
  }

  if (manufacturerExportTabIsInStock(tabLabel)) {
    const missingStockPrice = [...rowsBySku.values()].some((row) => !String(row?.price_stock || '').trim());
    if (missingStockPrice) {
      await scrapeManufacturerPreorderRows(page, expectedCount, rowsBySku);
    }
  }

  return rowsBySku;
}

function manufacturerInstockFilterCacheEnabled() {
  const raw = process.env.PLAMOD_INSTOCK_FILTER_CACHE;
  return raw !== '0' && raw !== 'false';
}

function manufacturerInstockFilterCachePath(root, manufacturerId) {
  return path.join(root, 'plamod', 'instock_filter_cache', `mfr-${String(manufacturerId).trim()}.json`);
}

function readManufacturerInstockFilterCache(root, manufacturerId, expectedTotal, ttlMs) {
  if (!manufacturerInstockFilterCacheEnabled()) {
    return null;
  }

  const cachePath = manufacturerInstockFilterCachePath(root, manufacturerId);
  if (!fs.existsSync(cachePath)) {
    return null;
  }

  try {
    const parsed = JSON.parse(fs.readFileSync(cachePath, 'utf8'));
    if (Number(parsed.expected_total) !== Number(expectedTotal)) {
      return null;
    }
    if (!Array.isArray(parsed.filters) || parsed.filters.length === 0) {
      return null;
    }

    const cachedAt = Date.parse(String(parsed.cached_at || ''));
    const ageMs = Date.now() - cachedAt;
    if (!Number.isFinite(cachedAt) || ageMs < 0 || ageMs > ttlMs) {
      return null;
    }

    return parsed.filters;
  } catch {
    return null;
  }
}

function writeManufacturerInstockFilterCache(root, manufacturerId, expectedTotal, filters) {
  if (!manufacturerInstockFilterCacheEnabled()) {
    return;
  }

  const cachePath = manufacturerInstockFilterCachePath(root, manufacturerId);
  ensureDir(path.dirname(cachePath));
  fs.writeFileSync(
    cachePath,
    JSON.stringify({
      manufacturer_id: String(manufacturerId),
      expected_total: expectedTotal,
      cached_at: new Date().toISOString(),
      filters,
    }),
    'utf8',
  );
}

function instockSliceShouldRetryListingPrices(missingCount, totalRows) {
  const missing = Math.max(0, Number(missingCount) || 0);
  const total = Math.max(0, Number(totalRows) || 0);
  if (missing <= 0 || total <= 0) {
    return false;
  }
  if (missing === total) {
    return true;
  }

  return missing / total >= 0.5;
}

/**
 * @param {import('playwright').Page} page
 * @returns {Promise<Array<{tab: string, name: string, instock_count: number}>>}
 */
async function discoverManufacturerInstockSidebarFilters(page) {
  /** @type {Array<{tab: string, name: string, instock_count: number}>} */
  let best = [];

  for (const filterTab of ['BRAND', 'SERIES', 'CATEGORY']) {
    const items = await scrapeManufacturerSidebarFilterItems(page, filterTab);
    // eslint-disable-next-line no-console
    console.log(
      `[plamod] instock filter scrape tab=${filterTab} raw=${items.length} sample=${items[0]?.name || '-'} instock=${items[0]?.instock_count ?? 'null'} id=${items[0]?.category_id ?? 'null'}`,
    );
    const eligible = items
      .map((item) => ({
        tab: filterTab,
        name: String(item.name || '').trim(),
        category_id: item.category_id ? String(item.category_id) : null,
        instock_count: Number(item.instock_count ?? item.preorder_count ?? item.other_count ?? 0),
      }))
      .filter((item) => item.name !== '' && item.instock_count > 0)
      .sort((a, b) => a.name.localeCompare(b.name));

    if (eligible.length >= 5 && filterTab === 'BRAND') {
      return eligible;
    }

    if (eligible.length > best.length) {
      best = eligible;
    }
    if (eligible.length >= 5) {
      return eligible;
    }
  }

  return best;
}

async function ensureManufacturerFilterSidebarReady(page, minLabels = 5) {
  for (let attempt = 0; attempt < 24; attempt += 1) {
    const labelCount = await page
      .evaluate(
        () =>
          document.querySelectorAll('label[for^="category-"], label[for^="brand-"], label[for^="series-"]').length,
      )
      .catch(() => 0);
    if (labelCount >= minLabels) {
      return labelCount;
    }
    await page.waitForTimeout(500);
  }

  return 0;
}

async function discoverManufacturerInstockFiltersWithFreshContext(baseUrl, profileDir, manufacturerId, tabLabel = 'In-Stock') {
  // eslint-disable-next-line global-require
  const { chromium } = require('playwright');
  cleanupPersistentProfileLocks(profileDir);
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
    await ensureLoggedIn(page, baseUrl, context, `manufacturer-${manufacturerId}-instock-discovery`);
    await gotoWithTimeout(page, `${baseUrl}/retailer/manufacturers/${manufacturerId}`, 45_000);
    await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => undefined);
    await page.waitForTimeout(800);

    const tabClicked = await clickExactManufacturerStatusTab(page, tabLabel);
    if (!tabClicked) {
      return { filters: [], pmkCategoryId: null };
    }

    await clearAllManufacturerFilters(page);
    await page.waitForTimeout(1500);
    await ensureManufacturerFilterSidebarReady(page);
    const filters = await discoverManufacturerInstockSidebarFilters(page);
    const pmkCategoryId = await resolveManufacturerPmkCategoryId(page);

    return { filters, pmkCategoryId };
  } finally {
    await safeCloseContext(context);
  }
}

async function prepareManufacturerInstockDiscoveryPage(page, baseUrl, manufacturerId, tabLabel = 'In-Stock') {
  const manufacturerUrl = `${baseUrl}/retailer/manufacturers/${manufacturerId}`;
  await gotoWithTimeout(page, manufacturerUrl, 45_000);
  await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => undefined);
  await page.waitForTimeout(600);

  const tabClicked = await clickExactManufacturerStatusTabForFilters(page, tabLabel);
  if (!tabClicked) {
    throw new Error(`Could not find manufacturer status tab: ${tabLabel}`);
  }

  await clearAllManufacturerFilters(page);
  await page.waitForTimeout(500);
  const labelCount = await ensureManufacturerFilterSidebarReady(page, 5);
  if (labelCount < 5) {
    // eslint-disable-next-line no-console
    console.log(`[plamod] filter sidebar sparse before PMK labels=${labelCount}`);
  }

  return labelCount;
}

async function prepareManufacturerInstockBasePage(page, baseUrl, manufacturerId, tabLabel = 'In-Stock') {
  return prepareManufacturerInstockDiscoveryPage(page, baseUrl, manufacturerId, tabLabel);
}

async function prepareManufacturerInstockChunkPage(page, baseUrl, manufacturerId, tabLabel = 'In-Stock') {
  await ensureOnManufacturerPage(page, baseUrl, manufacturerId);
  const tabClicked = await clickExactManufacturerStatusTab(page, tabLabel);
  if (!tabClicked) {
    throw new Error(`Could not find manufacturer status tab: ${tabLabel}`);
  }
  await clearAllManufacturerFilters(page);
  await page.waitForTimeout(600);
}

/**
 * @param {import('playwright').Page} page
 * @returns {Promise<Array<{tab: string, name: string, instock_count: number}>>}
 */
async function discoverManufacturerInstockSeriesFilters(page) {
  const items = await scrapeManufacturerSidebarFilterItems(page, 'SERIES');

  return items
    .map((item) => ({
      tab: 'SERIES',
      name: String(item.name || '').trim(),
      category_id: item.category_id ? String(item.category_id) : null,
      instock_count: Number(item.instock_count ?? item.preorder_count ?? item.other_count ?? 0),
    }))
    .filter((item) => item.name !== '' && item.instock_count > 0)
    .sort((a, b) => a.name.localeCompare(b.name));
}

/**
 * Export In-Stock Plastic Model Kits by iterating sidebar filters (SERIES first, BRAND/CATEGORY fallback).
 *
 * @param {{ manufacturerId?: number|string }} opts
 */
async function exportManufacturerInstockMerged(opts = {}) {
  const manufacturerId = String(opts.manufacturerId ?? 1).trim();
  const tabLabel = 'In-Stock';
  const maxFilters = Number.parseInt(
    String(opts.maxFilters ?? process.env.PLAMOD_INSTOCK_MERGED_MAX_FILTERS ?? '0'),
    10,
  );
  const testMode = maxFilters > 0;
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  const profileDir = String(process.env.PLAMOD_PERSISTENT_PROFILE_DIR || path.resolve(__dirname, '..', '..', '.pw-user-data'));
  const debugSku = `manufacturer-${manufacturerId}-instock-merged`;
  const started = Date.now();

  const root = storageRoot();
  const rawDir = path.join(root, 'plamod', 'instock_merged_exports');
  ensureDir(rawDir);
  const csvFilename = `instock-mfr-${manufacturerId}-merged-${nowStamp()}.csv`;
  const csvPath = path.join(rawDir, csvFilename);
  const csvStoragePath = path.posix.join('plamod', 'instock_merged_exports', csvFilename);
  const tempCsvPath = path.join(rawDir, `_chunk-${nowStamp()}.csv`);

  // eslint-disable-next-line no-console
  console.log(`[plamod] export manufacturer instock merged start id=${manufacturerId}`);

  return withManufacturerSessionMutex(async () => {
    try {
      writeInstockExportProgress({
        active: true,
        phase: 'discover',
        manufacturer_id: manufacturerId,
        started_at: new Date().toISOString(),
      });

      const session = await acquireWarmManufacturerSession(baseUrl, profileDir, manufacturerId);
      let page = session.page;
      let context = session.context;

      await prepareManufacturerInstockDiscoveryPage(page, baseUrl, manufacturerId, tabLabel);
      if (await looksLikeSignInPage(page)) {
        await ensureManufacturerSession(page, baseUrl, context);
        await prepareManufacturerInstockDiscoveryPage(page, baseUrl, manufacturerId, tabLabel);
      }

      const tabBadgeText = await readManufacturerTabBadge(page, tabLabel);
      const expectedTotal = Number.parseInt(String(tabBadgeText || '').replace(/[^\d]/g, ''), 10) || 0;

      const filterCacheTtlMs = Number.parseInt(process.env.PLAMOD_INSTOCK_FILTER_CACHE_TTL_MS || '86400000', 10);
      let filters = readManufacturerInstockFilterCache(root, manufacturerId, expectedTotal, filterCacheTtlMs);
      let filtersFromCache = Boolean(filters);

      if (!filters) {
        filters = await discoverManufacturerInstockSidebarFilters(page);
      } else {
        // eslint-disable-next-line no-console
        console.log(`[plamod] instock merged using cached filters count=${filters.length} expected_total=${expectedTotal}`);
      }

      if (filters.length === 0) {
        // eslint-disable-next-line no-console
        console.log('[plamod] instock merged warm-session discovery empty; retrying with fresh browser context');
        const fresh = await discoverManufacturerInstockFiltersWithFreshContext(
          baseUrl,
          profileDir,
          manufacturerId,
          tabLabel,
        );
        if (fresh.filters.length > 0) {
          filters = fresh.filters;
          filtersFromCache = false;
        }
      }

      filters = filters.filter((filter) => Boolean(filter.category_id));
      if (!filtersFromCache && filters.length > 0) {
        writeManufacturerInstockFilterCache(root, manufacturerId, expectedTotal, filters);
      }
      if (maxFilters > 0) {
        filters = filters.slice(0, maxFilters);
        // eslint-disable-next-line no-console
        console.log(`[plamod] instock merged test mode limiting to ${filters.length} filters`);
      }

      const filtersWithIds = filters.filter((filter) => Boolean(filter.category_id)).length;
      // eslint-disable-next-line no-console
      console.log(
        `[plamod] instock merged discovered ${filters.length} filters expected_total=${expectedTotal} first_tab=${filters[0]?.tab || '-'} filters_with_ids=${filtersWithIds}`,
      );

      if (filters.length === 0) {
        clearInstockExportProgress();
        return {
          ok: false,
          error_message: 'No sidebar filters with in-stock counts were discovered.',
          expected_row_count: expectedTotal,
          duration_ms: Date.now() - started,
        };
      }

      writeInstockExportProgress({
        active: true,
        phase: 'export',
        manufacturer_id: manufacturerId,
        filters_total: filters.length,
        filters_processed: 0,
        rows_merged: 0,
      });

      const sliceTempCsvPath = path.join(rawDir, `_slice-${nowStamp()}.csv`);

      /** @type {Map<string, Record<string, string>>} */
      const merged = new Map();
      /** @type {Array<Record<string, string|number|boolean|null>>} */
      const chunkStats = [];

      for (let filterIndex = 0; filterIndex < filters.length; filterIndex += 1) {
        const filter = filters[filterIndex];
        let chunkAttempt = 0;
        let chunkRecorded = false;

        while (!chunkRecorded && chunkAttempt < 2) {
          chunkAttempt += 1;
          const sliceStarted = Date.now();
          try {
            const sliceResult = await collectManufacturerInstockSliceRowsFast(page, context, {
              baseUrl,
              manufacturerId,
              tabLabel,
              filterName: filter.name,
              filterTab: filter.tab,
              filterCategoryId: filter.category_id,
              expectedCount: filter.instock_count,
              tempCsvPath: sliceTempCsvPath,
            });
            const sliceRows = sliceResult.rows;

            let added = 0;
            for (const [sku, row] of sliceRows.entries()) {
              const had = merged.has(sku);
              merged.set(sku, mergeManufacturerRow(merged.get(sku) || { sku }, row));
              if (!had) {
                added += 1;
              }
            }

            const sliceMs = Date.now() - sliceStarted;
            const sampleSku = sliceRows.keys().next().value || '-';
            const sampleSeries = sampleSku !== '-' ? String(sliceRows.get(sampleSku)?.series || '-') : '-';

            chunkStats.push({
              tab: filter.tab,
              filter: filter.name,
              category_id: filter.category_id,
              expected: filter.instock_count,
              listing_expected: sliceResult.listingExpected,
              tab_badge_count: sliceResult.tabBadgeCount,
              rows: sliceRows.size,
              added,
              total: merged.size,
              skipped: sliceRows.size === 0,
              duration_ms: sliceMs,
              sample_sku: sampleSku,
              sample_series: sampleSeries,
              strategy: sliceResult.strategy ?? null,
              attempt: chunkAttempt,
            });

            writeInstockExportProgress({
              active: true,
              phase: 'export',
              manufacturer_id: manufacturerId,
              filters_total: filters.length,
              filters_processed: filterIndex + 1,
              current_filter: filter.name,
              current_filter_tab: filter.tab,
              rows_merged: merged.size,
            });

            // eslint-disable-next-line no-console
            console.log(
              `[plamod] instock chunk tab=${filter.tab} filter=${filter.name} expected=${filter.instock_count} slice=${sliceRows.size} added=${added} total=${merged.size} strategy=${sliceResult.strategy ?? '-'} ms=${sliceMs}`,
            );
            chunkRecorded = true;
          } catch (chunkError) {
            const sliceMs = Date.now() - sliceStarted;
            const message = String(chunkError?.message || chunkError);
            // eslint-disable-next-line no-console
            console.log(
              `[plamod] instock chunk error tab=${filter.tab} filter=${filter.name} attempt=${chunkAttempt} ms=${sliceMs} msg=${message}`,
            );

            if (chunkAttempt < 2 && isPlaywrightBrowserClosedError(chunkError)) {
              // eslint-disable-next-line no-console
              console.log(`[plamod] instock chunk reacquiring browser session after crash filter=${filter.name}`);
              session = await reacquireManufacturerInstockExportSession(baseUrl, profileDir, manufacturerId);
              page = session.page;
              context = session.context;
              continue;
            }

            chunkStats.push({
              tab: filter.tab,
              filter: filter.name,
              category_id: filter.category_id,
              expected: filter.instock_count,
              rows: 0,
              added: 0,
              total: merged.size,
              skipped: true,
              duration_ms: sliceMs,
              error: message,
              attempt: chunkAttempt,
            });
            chunkRecorded = true;
          }
        }
      }

      const missingPriceCount = [...merged.values()].filter((row) => !String(row?.price_stock || '').trim()).length;

      if (missingPriceCount > 0) {
        const enrichMax = Math.min(
          missingPriceCount,
          Number.parseInt(process.env.PLAMOD_INSTOCK_PDP_ENRICH_MAX || '750', 10),
        );
        const enrichBudgetMs = Number.parseInt(process.env.PLAMOD_INSTOCK_PDP_ENRICH_BUDGET_MS || '3600000', 10);
        const previousEnrichMax = process.env.PLAMOD_INSTOCK_PDP_ENRICH_MAX;
        const previousEnrichBudget = process.env.PLAMOD_INSTOCK_PDP_ENRICH_BUDGET_MS;
        process.env.PLAMOD_INSTOCK_PDP_ENRICH_MAX = String(enrichMax);
        process.env.PLAMOD_INSTOCK_PDP_ENRICH_BUDGET_MS = String(enrichBudgetMs);
        writeInstockExportProgress({
          active: true,
          phase: 'pdp_enrich',
          manufacturer_id: manufacturerId,
          pdp_enrich_total: enrichMax,
          pdp_enrich_done: 0,
        });
        // eslint-disable-next-line no-console
        console.log(`[plamod] instock merged pdp enrich starting missing_price=${missingPriceCount} max=${enrichMax}`);
        const browserAfterEnrich = await enrichSparseManufacturerRowsFromPdp(page, baseUrl, context, merged, tabLabel, (done, total) => {
          writeInstockExportProgress({
            active: true,
            phase: 'pdp_enrich',
            manufacturer_id: manufacturerId,
            pdp_enrich_total: total,
            pdp_enrich_done: done,
          });
        }, {
          baseUrl,
          profileDir,
          manufacturerId,
        });
        page = browserAfterEnrich.page;
        context = browserAfterEnrich.context;
        if (previousEnrichMax === undefined) {
          delete process.env.PLAMOD_INSTOCK_PDP_ENRICH_MAX;
        } else {
          process.env.PLAMOD_INSTOCK_PDP_ENRICH_MAX = previousEnrichMax;
        }
        if (previousEnrichBudget === undefined) {
          delete process.env.PLAMOD_INSTOCK_PDP_ENRICH_BUDGET_MS;
        } else {
          process.env.PLAMOD_INSTOCK_PDP_ENRICH_BUDGET_MS = previousEnrichBudget;
        }
      }

      fs.writeFileSync(csvPath, manufacturerRowsToCsv(MANUFACTURER_CSV_HEADER, merged), 'utf8');
      const stat = fs.statSync(csvPath);

      session.lastUsed = Date.now();

      const minAcceptable = testMode
        ? 1
        : expectedTotal > 0
          ? Math.floor(expectedTotal * 0.85)
          : 1;
      const successThreshold = testMode ? Math.max(1, Math.floor(filters.length * 0.75)) : minAcceptable;
      const successfulChunks = chunkStats.filter((chunk) => Number(chunk.rows ?? 0) > 0).length;

      if (merged.size === 0 || (!testMode && expectedTotal > 0 && merged.size < minAcceptable)) {
        // eslint-disable-next-line no-console
        console.log(
          `[plamod] export manufacturer instock merged incomplete rows=${merged.size} expected=${expectedTotal} min=${minAcceptable}`,
        );

        return {
          ok: false,
          error_message: `In-stock merged export incomplete: got ${merged.size} rows, expected ~${expectedTotal}.`,
          manufacturer_id: manufacturerId,
          tab: tabLabel,
          csv_storage_path: csvStoragePath,
          bytes: stat.size,
          row_count: merged.size,
          expected_row_count: expectedTotal,
          filter_mode: filters[0]?.tab ?? null,
          filter_chunks: chunkStats,
          test_mode: testMode,
          duration_ms: Date.now() - started,
        };
      }

      if (testMode && successfulChunks < successThreshold) {
        return {
          ok: false,
          error_message: `In-stock merged test export: only ${successfulChunks}/${filters.length} chunks returned rows.`,
          manufacturer_id: manufacturerId,
          tab: tabLabel,
          csv_storage_path: csvStoragePath,
          bytes: stat.size,
          row_count: merged.size,
          expected_row_count: expectedTotal,
          filter_mode: filters[0]?.tab ?? null,
          filter_chunks: chunkStats,
          test_mode: true,
          duration_ms: Date.now() - started,
        };
      }

      const inaccurateChunks = chunkStats.filter((chunk) => {
        const expected = Number(chunk.listing_expected ?? chunk.expected ?? 0);
        const rows = Number(chunk.rows ?? 0);
        if (expected < 5 || chunk.error) {
          return false;
        }
        const minRows = expected <= 20 ? Math.max(1, expected - 1) : Math.floor(expected * 0.85);
        return rows < minRows;
      });
      if (testMode && inaccurateChunks.length > 0) {
        const names = inaccurateChunks
          .map((chunk) => `${chunk.filter}(${chunk.rows}/${chunk.listing_expected ?? chunk.expected})`)
          .join(', ');
        return {
          ok: false,
          error_message: `In-stock merged test export: chunk row counts below target: ${names}`,
          manufacturer_id: manufacturerId,
          tab: tabLabel,
          csv_storage_path: csvStoragePath,
          bytes: stat.size,
          row_count: merged.size,
          expected_row_count: expectedTotal,
          filter_mode: filters[0]?.tab ?? null,
          filter_chunks: chunkStats,
          test_mode: true,
          duration_ms: Date.now() - started,
        };
      }

      // eslint-disable-next-line no-console
      console.log(
        `[plamod] export manufacturer instock merged done rows=${merged.size} expected=${expectedTotal} bytes=${stat.size}`,
      );

      return {
        ok: true,
        manufacturer_id: manufacturerId,
        tab: tabLabel,
        csv_storage_path: csvStoragePath,
        bytes: stat.size,
        row_count: merged.size,
        expected_row_count: expectedTotal,
        filter_mode: filters[0]?.tab ?? null,
        filter_chunks: chunkStats,
        test_mode: testMode,
        duration_ms: Date.now() - started,
      };
    } catch (e) {
      await closeWarmManufacturerSession();
      // eslint-disable-next-line no-console
      console.log(`[plamod] export manufacturer instock merged error msg=${String(e?.message || e)}`);
      return {
        ok: false,
        error_message: String(e?.message || 'Unknown error'),
        duration_ms: Date.now() - started,
      };
    } finally {
      clearInstockExportProgress();
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
          price_stock: String(fields?.price_stock || '').trim(),
          price_preorder: String(fields?.price_preorder || '').trim(),
          quantity_preorder: String(fields?.quantity_preorder || '').trim(),
          preorder_offers: Array.isArray(fields?.preorder_offers) ? fields.preorder_offers : [],
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
      enriched: Object.values(results).filter(
        (row) =>
          row &&
          (String(row.price_stock || '').trim() !== '' ||
            String(row.price_preorder || '').trim() !== '' ||
            String(row.image_url || '').trim() !== ''),
      ).length,
      duration_ms: Date.now() - started,
    };
  });
}

/**
 * Diagnostic: scroll one manufacturer filter and log how many SKUs the DOM yields.
 *
 * @param {{
 *   manufacturerId?: string|number,
 *   categoryId?: string|number,
 *   expectedCount?: number,
 *   maxStaleRounds?: number,
 * }} opts
 */
async function diagnoseManufacturerFilterScroll(opts = {}) {
  const manufacturerId = String(opts.manufacturerId ?? 1).trim();
  const categoryId = String(opts.categoryId ?? '1004').trim();
  const expectedCount = Math.max(0, Number(opts.expectedCount ?? 191) || 0);
  const maxStaleRounds = Math.max(8, Number(opts.maxStaleRounds ?? 60) || 60);
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  const profileDir = String(process.env.PLAMOD_PERSISTENT_PROFILE_DIR || path.resolve(__dirname, '..', '..', '.pw-user-data'));

  /** @type {Array<{url: string, total?: number, bodyPreview: string}>} */
  const apiHits = [];
  const session = await acquireWarmManufacturerSession(baseUrl, profileDir, manufacturerId);
  const page = session.page;
  const context = session.context;

  page.on('response', async (response) => {
    const url = String(response.url() || '');
    if (!/plamod\.com/i.test(url) || response.status() !== 200) {
      return;
    }
    if (!/(manufacturer|product|search|graphql|retailer)/i.test(url)) {
      return;
    }
    const contentType = String(response.headers()['content-type'] || '');
    if (!/json/i.test(contentType)) {
      return;
    }
    try {
      const body = await response.text();
      const totalMatch = body.match(/"total(?:Count)?"\s*:\s*(\d+)/i);
      apiHits.push({
        url,
        total: totalMatch ? Number.parseInt(totalMatch[1], 10) : undefined,
        bodyPreview: body.slice(0, 400),
      });
    } catch {
      // ignore
    }
  });

  await ensureLoggedInQuick(page, baseUrl, context);
  await recoverManufacturerInstockListingPage(page, baseUrl, manufacturerId, 'In-Stock');
  await applyManufacturerInstockFilter(page, {
    baseUrl,
    manufacturerId,
    filterTab: 'BRAND',
    filterCategoryId: categoryId,
    filterName: '30 Minutes Label',
  });
  await page.waitForTimeout(1200);

  const initialDom = await collectRowsFromDom(page);
  // eslint-disable-next-line no-console
  console.log(
    `[diagnose] filter category=${categoryId} expected=${expectedCount} initial_dom=${initialDom.size} url=${page.url()}`,
  );

  /** @type {Map<string, Record<string, string>>} */
  const rows = new Map(initialDom);
  let staleRounds = 0;
  let lastLinkCount = 0;

  for (let round = 0; round < 400; round += 1) {
    const batch = await page.evaluate(extractManufacturerPreorderCardsFromDocument);
    let newRows = 0;
    for (const row of batch) {
      if (!row?.sku || rows.has(row.sku)) {
        continue;
      }
      rows.set(row.sku, row);
      newRows += 1;
    }

    const domStats = await page.evaluate(() => {
      const viewports = Array.from(document.querySelectorAll('[data-radix-scroll-area-viewport]'));
      return {
        links: document.querySelectorAll('a[href*="/retailer/products/"]').length,
        viewportCount: viewports.length,
        maxViewportScrollTop: Math.max(0, ...viewports.map((n) => (n instanceof HTMLElement ? n.scrollTop : 0))),
        maxViewportScrollHeight: Math.max(
          0,
          ...viewports.map((n) => (n instanceof HTMLElement ? n.scrollHeight : 0)),
        ),
        bodyScrollTop: document.documentElement.scrollTop || document.body.scrollTop || 0,
        bodyScrollHeight: document.documentElement.scrollHeight || document.body.scrollHeight || 0,
      };
    });

    const linkDelta = domStats.links - lastLinkCount;
    lastLinkCount = domStats.links;

    if (round < 10 || newRows > 0 || round % 15 === 0) {
      // eslint-disable-next-line no-console
      console.log(
        `[diagnose] round=${round} skus=${rows.size} new=${newRows} dom_links=${domStats.links} link_delta=${linkDelta} stale=${staleRounds} vscroll=${domStats.maxViewportScrollTop}/${domStats.maxViewportScrollHeight} body=${domStats.bodyScrollTop}/${domStats.bodyScrollHeight}`,
      );
    }

    if (expectedCount > 0 && rows.size >= expectedCount) {
      // eslint-disable-next-line no-console
      console.log(`[diagnose] reached expected ${expectedCount}`);
      break;
    }

    if (newRows === 0 && linkDelta === 0) {
      staleRounds += 1;
      if (staleRounds >= maxStaleRounds) {
        // eslint-disable-next-line no-console
        console.log(`[diagnose] stopped after ${staleRounds} stale rounds`);
        break;
      }
    } else {
      staleRounds = 0;
    }

    await page
      .evaluate(() => {
        window.scrollBy(0, Math.max(900, Math.floor(window.innerHeight * 0.95)));
        document.querySelectorAll('[data-radix-scroll-area-viewport], .overflow-auto, main').forEach((node) => {
          if (node instanceof HTMLElement) {
            node.scrollTop += Math.max(1000, Math.floor(window.innerHeight * 0.95));
          }
        });
      })
      .catch(() => undefined);
    await page.waitForTimeout(700);
  }

  const apiTotals = apiHits
    .map((hit) => hit.total)
    .filter((value) => typeof value === 'number' && Number.isFinite(value) && value > 0);
  const maxApiTotal = apiTotals.length > 0 ? Math.max(...apiTotals) : null;

  // eslint-disable-next-line no-console
  console.log(
    `[diagnose] FINAL skus=${rows.size} expected=${expectedCount} max_api_total=${maxApiTotal ?? 'n/a'} api_hits=${apiHits.length}`,
  );

  return {
    category_id: categoryId,
    expected_count: expectedCount,
    dom_sku_count: rows.size,
    max_api_total: maxApiTotal,
    api_hit_count: apiHits.length,
  };
}

module.exports = {
  downloadPlamodZipForSku,
  exportPlamodPreordersCsv,
  listManufacturerPreorderFilters,
  exportManufacturerPreordersCsv,
  exportManufacturerInstockMerged,
  readInstockExportProgress,
  diagnoseManufacturerFilterScroll,
  searchRetailerPreorders,
  resetPlamodScraperSessions,
  debugScrapePreorderPdpFields,
  enrichPreorderPdpFields,
  retailerPdpSkuFromUrl,
  isOnRetailerPdpForSku,
  ensureLoggedInQuick,
  ensureOnRetailerPdp,
  manufacturerInstockFilterCacheEnabled,
  manufacturerInstockFilterCachePath,
  readManufacturerInstockFilterCache,
  writeManufacturerInstockFilterCache,
  instockSliceShouldRetryListingPrices,
  parseInStockPriceFromCardText,
  isPreorderOnlyPriceBlock,
};


