function normalizeWhitespace(s) {
  return String(s || '').replace(/\s+/g, ' ').trim();
}

const fs = require('node:fs');
const path = require('node:path');

function extractCurrencyCode(text) {
  const t = normalizeWhitespace(text);
  if (!t) return null;
  // AliExpress commonly shows: "US $12.34", "C$12.34", "CA $12.34"
  if (t.includes('US $') || t.includes('US$')) return 'USD';
  if (t.includes('C$') || t.includes('CA $') || t.includes('CAD')) return 'CAD';
  if (t.includes('AU $') || t.includes('A$')) return 'AUD';
  if (t.includes('€')) return 'EUR';
  if (t.includes('£')) return 'GBP';
  return null;
}

function extractFirstPriceNumber(text) {
  const t = normalizeWhitespace(text);
  // Take first number with optional decimals
  const m = t.match(/([0-9]{1,6}(?:\.[0-9]{1,2})?)/);
  if (!m) return null;
  return Number.parseFloat(m[1]);
}

function looksLikeBlocked(htmlOrText) {
  const t = String(htmlOrText || '').toLowerCase();
  return (
    t.includes('captcha') ||
    t.includes('verify') && t.includes('human') ||
    t.includes('access denied') ||
    t.includes('unusual traffic') ||
    t.includes('are you a robot') ||
    t.includes('security verification') ||
    t.includes('enable cookies') ||
    (t.includes('punish') && t.includes('risk'))
  );
}

function safeSnippet(text, maxLen = 220) {
  const t = normalizeWhitespace(String(text || ''));
  if (t.length <= maxLen) return t;
  return `${t.slice(0, maxLen)}…`;
}

function shouldRetry(result) {
  return result && result.status === 'error' && result.error_message === 'blocked_by_antibot';
}

function jitterMs(minMs, maxMs) {
  const min = Math.max(0, Number(minMs) || 0);
  const max = Math.max(min, Number(maxMs) || min);
  return Math.floor(min + Math.random() * (max - min));
}

async function withProfileLock(profileDir, fn) {
  const dir = profileDir && String(profileDir).trim() !== '' ? String(profileDir).trim() : null;
  if (!dir) {
    return await fn();
  }

  fs.mkdirSync(dir, { recursive: true });
  const lockPath = path.join(dir, '.lock');
  const started = Date.now();
  const maxWaitMs = 25000;

  while (true) {
    try {
      const fd = fs.openSync(lockPath, 'wx');
      try {
        return await fn();
      } finally {
        try {
          fs.closeSync(fd);
        } catch {
          // ignore
        }
        try {
          fs.unlinkSync(lockPath);
        } catch {
          // ignore
        }
      }
    } catch {
      if (Date.now() - started > maxWaitMs) {
        // If we can't obtain the lock, proceed without it (avoid deadlocking the whole system).
        return await fn();
      }
      await new Promise((r) => setTimeout(r, jitterMs(120, 280)));
    }
  }
}

function parseCookiesFromEnv() {
  const raw = process.env.ALIEXPRESS_COOKIES_JSON;
  if (!raw) return null;
  try {
    const json = JSON.parse(raw);
    if (!Array.isArray(json)) return null;
    return json
      .filter((c) => c && typeof c === 'object' && typeof c.name === 'string' && typeof c.value === 'string')
      .map((c) => {
        // Playwright expects sameSite to be one of Strict|Lax|None (or undefined).
        let sameSite = undefined;
        if (typeof c.sameSite === 'string') {
          const s = c.sameSite.toLowerCase();
          if (s === 'strict') sameSite = 'Strict';
          else if (s === 'lax') sameSite = 'Lax';
          else if (s === 'none') sameSite = 'None';
        }

        // expires must be a number of seconds since UNIX epoch (or undefined).
        const expires = typeof c.expires === 'number' ? c.expires : undefined;

        return {
          name: c.name,
          value: c.value,
          domain: c.domain,
          path: c.path || '/',
          expires,
          httpOnly: !!c.httpOnly,
          secure: !!c.secure,
          sameSite,
        };
      });
  } catch {
    return null;
  }
}

async function createContext(browser) {
  const cookies = parseCookiesFromEnv();
  const context = await browser.newContext({
    locale: 'en-CA',
    timezoneId: 'America/Toronto',
    viewport: { width: 1365, height: 900 },
    userAgent:
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
    extraHTTPHeaders: {
      'Accept-Language': 'en-CA,en;q=0.9',
    },
  });

  // Basic stealth hardening (best-effort; AliExpress WAF is aggressive and may still block on IP reputation).
  await context.addInitScript(() => {
    // eslint-disable-next-line no-undef
    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
    // eslint-disable-next-line no-undef
    Object.defineProperty(navigator, 'languages', { get: () => ['en-CA', 'en'] });
    // eslint-disable-next-line no-undef
    Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });
    // eslint-disable-next-line no-undef
    window.chrome = window.chrome || { runtime: {} };
  });

  context.setDefaultNavigationTimeout(10000);
  context.setDefaultTimeout(10000);
  if (cookies && cookies.length) {
    await context.addCookies(cookies);
  }
  return context;
}

function bestEffortMatchScore({ title, sku, barcode, term }) {
  const hay = normalizeWhitespace(title).toLowerCase();
  let score = 0;
  const skuL = sku ? sku.toLowerCase() : null;
  const bcL = barcode ? barcode.toLowerCase() : null;
  const termL = term ? term.toLowerCase() : null;

  if (bcL && hay.includes(bcL)) score += 10;
  if (skuL && hay.includes(skuL)) score += 8;

  if (termL) {
    const tokens = termL.split(/[^a-z0-9]+/).filter((x) => x && x.length >= 3);
    let hits = 0;
    for (const tok of tokens.slice(0, 8)) {
      if (hay.includes(tok)) hits++;
    }
    score += Math.min(6, hits);
  }

  return score;
}

async function extractPdpInfo(page) {
  const url = page.url();
  const title =
    (await page.locator('meta[property="og:title"]').getAttribute('content').catch(() => null)) ||
    (await page.title().catch(() => null));

  // Try to find price via common selectors (best-effort; AliExpress changes frequently)
  const candidates = [
    '[data-pl="product-price"]',
    '[class*="product-price"]',
    '[class*="price"]',
    'meta[property="product:price:amount"]',
  ];

  let priceText = null;
  for (const sel of candidates) {
    const loc = page.locator(sel).first();
    if (await loc.count()) {
      if (sel.startsWith('meta[')) {
        const amount = await loc.getAttribute('content').catch(() => null);
        if (amount) {
          const n = extractFirstPriceNumber(amount);
          if (n !== null) {
            return {
              title: normalizeWhitespace(title),
              product_url: url,
              currency: null,
              price: n,
              original_price: null,
              availability: null,
              raw_price_text: amount,
            };
          }
        }
      } else {
        const txt = await loc.innerText().catch(() => null);
        if (txt && extractFirstPriceNumber(txt) !== null) {
          priceText = txt;
          break;
        }
      }
    }
  }

  // Fallback: scan page text for something that looks like a price token (US $ / C$)
  if (!priceText) {
    const bodyText = await page.locator('body').innerText().catch(() => '');
    const lines = String(bodyText || '')
      .split('\n')
      .map((l) => l.trim())
      .filter(Boolean);
    const likely = lines.find((l) => l.includes('US $') || l.includes('US$') || l.includes('C$') || l.includes('CA $'));
    priceText = likely || null;
  }

  const currency = extractCurrencyCode(priceText || '') || null;
  const price = extractFirstPriceNumber(priceText || '');

  // Availability best-effort
  const bodyText2 = await page.locator('body').innerText().catch(() => '');
  let availability = null;
  const low = String(bodyText2 || '').toLowerCase();
  if (low.includes('sold out') || low.includes('out of stock')) availability = 'sold_out';
  if (!availability && (low.includes('buy now') || low.includes('add to cart'))) availability = 'in_stock';

  return {
    title: normalizeWhitespace(title),
    product_url: url,
    currency,
    price,
    original_price: null,
    availability,
    raw_price_text: priceText,
  };
}

async function collectCandidatePdpUrls(page, baseUrl) {
  // item links are usually /item/<id>.html
  const hrefs = await page.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href')).filter(Boolean));
  const out = [];
  for (const href of hrefs) {
    let h = String(href).trim();
    if (!h) continue;

    // Normalize odd relative formats AliExpress uses in search results.
    // Examples we've seen:
    // - "/www.aliexpress.com/item/100500....html?...": (yes, really)
    // - "//www.aliexpress.com/item/100500....html?...": protocol-relative
    // - "/item/100500....html?...": normal relative
    if (h.startsWith('/www.aliexpress.com/')) {
      h = h.replace(/^\/www\.aliexpress\.com/, '');
    }

    if (!h.includes('/item/')) continue;
    let url = null;
    if (h.startsWith('http://') || h.startsWith('https://')) {
      url = h;
    } else if (h.startsWith('//')) {
      url = `https:${h}`;
    } else if (h.startsWith('/')) {
      url = `${baseUrl.replace(/\/$/, '')}${h}`;
    } else if (h.startsWith('www.aliexpress.')) {
      url = `https://${h}`;
    } else if (h.startsWith('aliexpress.')) {
      url = `https://${h}`;
    }
    if (!url) continue;
    // Clean up any accidental double-domain prefixing.
    url = url.replace(/^https?:\/\/www\.aliexpress\.com\/\/www\.aliexpress\.com\//, 'https://www.aliexpress.com/');
    if (!url.includes('/item/')) continue;
    out.push(url.split('#')[0]);
  }
  return Array.from(new Set(out)).slice(0, 10);
}

function buildSearchUrl({ baseUrl, term, currency }) {
  const t = normalizeWhitespace(term);
  const q = encodeURIComponent(t);
  // Use AliExpress /w/ wholesale endpoint; also request CAD if supported.
  const url = `${baseUrl.replace(/\/$/, '')}/w/wholesale-${q}.html`;
  const sep = url.includes('?') ? '&' : '?';
  return `${url}${sep}currency=${encodeURIComponent(currency || 'CAD')}`;
}

/**
 * @param {{term: string, sku: string|null, barcode: string|null, currency: string, baseUrl: string}} input
 * @returns {Promise<any>}
 */
async function scrapeAliExpressBySearch(input) {
  const searchUrl = buildSearchUrl(input);

  // Lazy-load Playwright so unit tests can run without installing browser deps.
  // In Docker (Playwright image), this will always be available.
  // eslint-disable-next-line global-require
  const { chromium } = require('playwright');

  const profileDir = process.env.ALIEXPRESS_PERSISTENT_PROFILE_DIR || path.join(__dirname, '..', '.pw-user-data');

  return await withProfileLock(profileDir, async () => {
    async function attemptOnce(attempt) {
      const startedAt = Date.now();
      const context = await chromium.launchPersistentContext(profileDir, {
        headless: true,
        args: ['--disable-blink-features=AutomationControlled'],
        locale: 'en-CA',
        timezoneId: 'America/Toronto',
        viewport: { width: 1365, height: 900 },
        userAgent:
          'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
        extraHTTPHeaders: {
          'Accept-Language': 'en-CA,en;q=0.9',
        },
      });

      // Basic stealth hardening (best-effort; AliExpress WAF is aggressive and may still block on IP reputation).
      await context.addInitScript(() => {
        // eslint-disable-next-line no-undef
        Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
        // eslint-disable-next-line no-undef
        Object.defineProperty(navigator, 'languages', { get: () => ['en-CA', 'en'] });
        // eslint-disable-next-line no-undef
        Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });
        // eslint-disable-next-line no-undef
        window.chrome = window.chrome || { runtime: {} };
      });

      context.setDefaultNavigationTimeout(10000);
      context.setDefaultTimeout(10000);

      const cookies = parseCookiesFromEnv();
      if (cookies && cookies.length) {
        await context.addCookies(cookies);
      }

      const page = await context.newPage();
      try {
        // AliExpress frequently keeps long-polling connections open; "networkidle" can hang.
        // Use domcontentloaded with bounded timeouts so we return within backend time budget.
        await page.goto(searchUrl, { waitUntil: 'domcontentloaded', timeout: 10000 });
        await page.waitForTimeout(600);

        const html = await page.content().catch(() => '');
        const bodyText = await page.locator('body').innerText().catch(() => '');
        if (looksLikeBlocked(html) || looksLikeBlocked(bodyText)) {
          const title = await page.title().catch(() => null);
          const url = page.url();
          return {
            status: 'error',
            error_message: 'blocked_by_antibot',
            debug: {
              attempt,
              search_url: searchUrl,
              final_url: url,
              title,
              snippet: safeSnippet(bodyText),
              duration_ms: Date.now() - startedAt,
            },
          };
        }

        const candidates = await collectCandidatePdpUrls(page, input.baseUrl);
        if (!candidates.length) {
          return { status: 'not_found', debug: { attempt, search_url: searchUrl, duration_ms: Date.now() - startedAt } };
        }

        let best = null;
        let bestScore = -1;

        // Keep this bounded; we optimize for consistent runtime while keeping match quality high.
        for (const url of candidates.slice(0, 2)) {
          const pdp = await context.newPage();
          try {
            // Budget guard: if we're already near the timeout threshold, stop checking candidates.
            if (Date.now() - startedAt > 24000) {
              break;
            }

            await pdp.goto(url, { waitUntil: 'domcontentloaded', timeout: 10000 });
            await pdp.waitForTimeout(600);

            const pdpHtml = await pdp.content().catch(() => '');
            const pdpText = await pdp.locator('body').innerText().catch(() => '');
            if (looksLikeBlocked(pdpHtml) || looksLikeBlocked(pdpText)) {
              continue;
            }

            const info = await extractPdpInfo(pdp);
            const score = bestEffortMatchScore({
              title: info.title,
              sku: input.sku,
              barcode: input.barcode,
              term: input.term,
            });

            if (info.price !== null && Number.isFinite(info.price) && score > bestScore) {
              best = info;
              bestScore = score;
            }
          } catch (e) {
            // ignore candidate errors
          } finally {
            await pdp.close().catch(() => {});
          }
        }

        if (!best || best.price === null) {
          return {
            status: 'not_found',
            debug: { attempt, search_url: searchUrl, candidates: candidates.slice(0, 5), duration_ms: Date.now() - startedAt },
          };
        }

        return {
          status: 'found',
          price: best.price,
          original_price: best.original_price,
          currency: best.currency || null,
          availability: best.availability,
          product_url: best.product_url,
          debug: {
            attempt,
            search_url: searchUrl,
            matched_title: best.title,
            score: bestScore,
            raw_price_text: best.raw_price_text || null,
            duration_ms: Date.now() - startedAt,
          },
        };
      } finally {
        await page.close().catch(() => {});
        await context.close().catch(() => {});
      }
    }

    const maxAttempts = 3;
    /** @type {any} */
    let last = null;
    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
      last = await attemptOnce(attempt);
      if (!shouldRetry(last)) {
        return last;
      }
      if (attempt < maxAttempts) {
        // Small backoff with jitter to avoid hitting the same WAF path repeatedly.
        await new Promise((r) => setTimeout(r, jitterMs(900, 1800)));
      }
    }

    return last;
  });
}

module.exports = {
  scrapeAliExpressBySearch,
  buildSearchUrl,
  extractCurrencyCode,
  extractFirstPriceNumber,
  bestEffortMatchScore,
  looksLikeBlocked,
  // exported for unit tests only
  parseCookiesFromEnv,
  shouldRetry,
};


