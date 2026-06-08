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

async function ensureOnRetailerPdp(page, baseUrl, sku, context) {
  const url = page.url();
  if (url.includes('/retailer/products/')) {
    return;
  }

  if (url.includes('/retailer-sign-in')) {
    await ensureLoggedIn(page, baseUrl, context, sku);
  }

  // If login redirected us elsewhere, try to navigate again.
  const pdpUrl = `${baseUrl}/retailer/products/${encodeURIComponent(sku)}`;
  await gotoWithTimeout(page, pdpUrl, 20_000);

  if (page.url().includes('/retailer-sign-in')) {
    throw new Error('Plamod login failed: retailer PDP redirected back to sign-in.');
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

async function ensureManufacturerPlasticModelKitsOnly(page) {
  const clicked = await clickFirst(page, [
    'button:has-text("Clear Categories")',
    'button:has-text("Clear Category")',
  ]);
  if (clicked) {
    await page.waitForTimeout(800);
  }

  const categoryClicked = await clickFirst(page, ['button:has-text("CATEGORY")']);
  if (!categoryClicked) {
    return false;
  }

  await page.waitForTimeout(600);
  const selected = await page
    .evaluate(() => {
      const norm = (value) => String(value || '').replace(/\s+/g, ' ').trim();
      const labels = Array.from(document.querySelectorAll('label, span, div'));
      const hit = labels.find((node) => norm(node.textContent) === 'Plastic Model Kits');
      if (!hit) {
        return false;
      }
      const row = hit.closest('div');
      const checkbox = row?.querySelector('input[type="checkbox"]');
      if (!(checkbox instanceof HTMLInputElement)) {
        return false;
      }
      if (!checkbox.checked) {
        checkbox.click();
      }
      return true;
    })
    .catch(() => false);

  await page.keyboard.press('Escape').catch(() => undefined);
  await page.waitForLoadState('networkidle', { timeout: 45_000 }).catch(() => undefined);
  await page.waitForTimeout(1200);
  return selected;
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

    seen.add(sku);
    rows.push({
      sku,
      barcode: fields.Barcode || '',
      product_name: productName,
      series,
      release_date: fields.Release || '',
      manufacturer,
      category,
      price_preorder: pricePreorder,
      quantity_preorder: quantityPreorder,
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

      const parsed = parseSimpleCsvFile(partialPath);
      if (mergedHeader.length === 0 && parsed.header.length > 0) {
        mergedHeader = parsed.header;
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
 * Export preorder CSV from a Plamod manufacturer page (e.g. BANDAI HOBBY = id 1).
 *
 * @param {{ manufacturerId?: number|string, tab?: string, category?: string|null }} opts
 */
async function exportManufacturerPreordersCsv(opts = {}) {
  const manufacturerId = String(opts.manufacturerId ?? 1).trim();
  const tabLabel = String(opts.tab ?? 'Preorder').trim();
  const categoryLabel = opts.category === null ? null : String(opts.category ?? 'Plastic Model Kits').trim();
  const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
  const profileDir = String(process.env.PLAMOD_PERSISTENT_PROFILE_DIR || path.resolve(__dirname, '..', '..', '.pw-user-data'));
  const probeSku = '5060358';
  const debugSku = `manufacturer-${manufacturerId}-export`;

  const root = storageRoot();
  const rawDir = path.join(root, 'plamod', 'manufacturer_preorder_exports');
  ensureDir(rawDir);
  const csvFilename = `mfr-${manufacturerId}-${nowStamp()}.csv`;
  const csvPath = path.join(rawDir, csvFilename);
  const csvStoragePath = path.posix.join('plamod', 'manufacturer_preorder_exports', csvFilename);

  // eslint-disable-next-line global-require
  const { chromium } = require('playwright');

  const started = Date.now();
  cleanupPersistentProfileLocks(profileDir);

  // eslint-disable-next-line no-console
  console.log(`[plamod] export manufacturer preorders csv start id=${manufacturerId} tab=${tabLabel} category=${categoryLabel || '(none)'}`);
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
    const skuCollector = createManufacturerSkuCollector();
    const captured = attachPlamodNetworkCapture(page, skuCollector);
    const manufacturerUrl = `${baseUrl}/retailer/manufacturers/${manufacturerId}`;
    const categoryId = categoryLabel ? '1' : '';
    const searchUrl = categoryId
      ? `${baseUrl}/retailer/search?manufacturers=${encodeURIComponent(manufacturerId)}&categories=${categoryId}&tab=preorder`
      : `${baseUrl}/retailer/search?manufacturers=${encodeURIComponent(manufacturerId)}&tab=preorder`;
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

    await page.waitForLoadState('networkidle', { timeout: 45_000 }).catch(() => undefined);
    await page.waitForTimeout(1200);

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

    if (categoryLabel) {
      await ensureManufacturerPlasticModelKitsOnly(page);
    }

    const tabBadgeText = await readManufacturerTabBadge(page, tabLabel);
    const expectedCount = Number.parseInt(String(tabBadgeText || '').replace(/[^\d]/g, ''), 10) || 0;
    await writeDebugSnapshot(page, debugSku, 'before-export').catch(() => undefined);

    if (await looksLikeSignInPage(page)) {
      const debug = await writeDebugSnapshot(page, debugSku, 'sign-in');
      return {
        ok: false,
        error_message: 'Plamod login failed for manufacturer preorder export. Check PLAMOD credentials.',
        debug,
        duration_ms: Date.now() - started,
      };
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

    if (parsed.rows.size === 0) {
      const scrapedRows = await scrapeManufacturerPreorderRows(page, expectedCount, skuCollector.rowsBySku);
      if (scrapedRows.size === 0) {
        const debug = await writeDebugSnapshot(page, debugSku, 'missing-rows');
        return {
          ok: false,
          error_message: 'Could not export manufacturer preorder rows (no CSV control and scrape returned zero rows)',
          debug,
          manufacturer_url: manufacturerUrl,
          search_url: searchUrl,
          tab_badge_text: tabBadgeText,
          expected_row_count: expectedCount,
          captured_responses: captured.length,
          duration_ms: Date.now() - started,
        };
      }

      const supplementQueries = ['RE 1/100 VIGNA-GHINA'];
      for (const query of supplementQueries) {
        if (scrapedRows.has('0225768')) {
          break;
        }
        try {
          const hit = await scrapeRetailerSearchPage(page, baseUrl, query, context);
          if (hit?.sku) {
            scrapedRows.set(hit.sku, {
              sku: hit.sku,
              product_name: hit.product_name || query,
              manufacturer: 'BANDAI HOBBY',
              category: 'Plastic Model Kits',
            });
          }
        } catch (e) {
          // eslint-disable-next-line no-console
          console.log(`[plamod] manufacturer supplement search failed query=${query} msg=${String(e?.message || 'unknown')}`);
        }
      }

      fs.writeFileSync(csvPath, manufacturerRowsToCsv(csvHeader, scrapedRows), 'utf8');
      parsed = parseSimpleCsvFile(csvPath);
      exportMode = scrapedRows.has('0225768') ? 'dom_scrape+search_supplement' : 'dom_scrape';
    }

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
      manufacturer_url: manufacturerUrl,
      search_url: searchUrl,
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

async function scrapeRetailerSearchPage(page, baseUrl, query, context) {
  const searchUrl = `${baseUrl}/retailer/search?tab=preorder&q=${encodeURIComponent(query)}`;

  const openSearchResults = async () => {
    await gotoWithTimeout(page, `${baseUrl}/retailer`, 30_000);
    await page.waitForLoadState('domcontentloaded', { timeout: 20_000 }).catch(() => undefined);
    await page.waitForTimeout(800);

    if (page.url().includes('/retailer-sign-in')) {
      if (!context) return false;
      await ensureLoggedIn(page, baseUrl, context, 'search');
      await gotoWithTimeout(page, `${baseUrl}/retailer`, 30_000);
      await page.waitForTimeout(800);
    }

    const filled = await fillFirst(page, [
      'input[type="search"]',
      'input[placeholder*="Search" i]',
      'input[name="q"]',
      'input[aria-label*="Search" i]',
    ], query);
    if (filled) {
      const input = await page.$('input[type="search"], input[placeholder*="Search" i], input[name="q"]');
      if (input) {
        await input.press('Enter').catch(() => undefined);
      }
      await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => undefined);
      await page.waitForTimeout(1200);
      if (!page.url().includes('/retailer/search')) {
        await gotoWithTimeout(page, searchUrl, 45_000);
      }
    } else {
      await gotoWithTimeout(page, searchUrl, 45_000);
    }

    await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => undefined);
    await page.waitForTimeout(1200);
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
  const probeSku = '5060358';

  const cleaned = [];
  for (const q of queries) {
    if (typeof q !== 'string') continue;
    const v = q.trim();
    if (v !== '') cleaned.push(v);
  }
  if (cleaned.length === 0) {
    return { ok: false, error_message: 'queries is required' };
  }

  // eslint-disable-next-line global-require
  const { chromium } = require('playwright');
  const started = Date.now();
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

  /** @type {Record<string, any>} */
  const results = {};

  try {
    const page = await context.newPage();
    await ensureLoggedIn(page, baseUrl, context, probeSku);

    for (const query of cleaned) {
      try {
        results[query] = await scrapeRetailerSearchPage(page, baseUrl, query, context);
      } catch (e) {
        results[query] = null;
      }
    }

    return {
      ok: true,
      results,
      duration_ms: Date.now() - started,
    };
  } catch (e) {
    return {
      ok: false,
      error_message: String(e?.message || 'Unknown error'),
      results,
      duration_ms: Date.now() - started,
    };
  } finally {
    await safeCloseContext(context);
  }
}

module.exports = {
  downloadPlamodZipForSku,
  exportPlamodPreordersCsv,
  exportManufacturerPreordersCsv,
  searchRetailerPreorders,
};


