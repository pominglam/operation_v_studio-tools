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
  await gotoWithTimeout(page, probeUrl, 15_000);
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

  // Submit button
  const clicked = await clickFirst(page, [
    'button[type="submit"]',
    'button:has-text("Sign in")',
    'button:has-text("Login")',
    'button:has-text("Sign In")',
  ]);
  if (!clicked) {
    throw new Error('Could not find sign-in submit button on Plamod sign-in page');
  }

  // Snapshot immediately after clicking submit (before any redirect).
  await page.waitForTimeout(500);
  if (debugSku) {
    await writeDebugSnapshot(page, debugSku, 'login-after-click-sign-in').catch(() => undefined);
  }

  // Wait briefly for either inline error UI to appear or some navigation/render.
  await Promise.race([
    page.waitForFunction(() => /sign in failed|an error occurred/i.test(document?.body?.innerText || ''), { timeout: 8_000 }),
    page.waitForLoadState('domcontentloaded', { timeout: 8_000 }),
  ]).catch(() => undefined);

  // If the UI already shows an inline failure, throw NOW (so debug snapshot captures it).
  const immediateInlineError = await extractInlineLoginError(page);
  if (immediateInlineError && /sign in failed|error occurred|try again/i.test(immediateInlineError)) {
    const cookieCount = context
      ? await context.cookies().then((cs) => cs.length).catch(() => 0)
      : 0;
    throw new Error(`Plamod login failed: ${immediateInlineError} (url: ${page.url()}, cookies: ${cookieCount})`);
  }

  // Validate auth by hitting the retailer PDP probe and ensuring it doesn't show the sign-in UI.
  await gotoWithTimeout(page, probeUrl, 15_000);

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

module.exports = { downloadPlamodZipForSku };


