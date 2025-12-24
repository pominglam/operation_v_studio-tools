const test = require('node:test');
const assert = require('node:assert/strict');
const {
  buildSearchUrl,
  extractCurrencyCode,
  extractFirstPriceNumber,
  bestEffortMatchScore,
  looksLikeBlocked,
  parseCookiesFromEnv,
  shouldRetry,
} = require('../src/scrape');

test('buildSearchUrl uses wholesale endpoint and currency param', () => {
  const url = buildSearchUrl({
    baseUrl: 'https://www.aliexpress.com',
    term: 'Stedi MS-104',
    currency: 'CAD',
  });
  assert.ok(url.includes('/w/wholesale-'));
  assert.ok(url.includes('currency=CAD'));
});

test('extractCurrencyCode detects common patterns', () => {
  assert.equal(extractCurrencyCode('US $12.34'), 'USD');
  assert.equal(extractCurrencyCode('C$12.34'), 'CAD');
  assert.equal(extractCurrencyCode('CA $12.34'), 'CAD');
});

test('extractFirstPriceNumber returns first numeric token', () => {
  assert.equal(extractFirstPriceNumber('US $12.34 / piece'), 12.34);
});

test('bestEffortMatchScore prefers SKU hits', () => {
  const s1 = bestEffortMatchScore({ title: 'Stedi MS-104 Model Nipper', sku: 'MS-104', barcode: null, term: 'Stedi MS-104' });
  const s2 = bestEffortMatchScore({ title: 'Generic Model Nipper', sku: 'MS-104', barcode: null, term: 'Stedi MS-104' });
  assert.ok(s1 > s2);
});

test('looksLikeBlocked detects common antibot page hints', () => {
  assert.equal(looksLikeBlocked('Unusual traffic detected'), true);
  assert.equal(looksLikeBlocked('Please verify you are a human'), true);
  assert.equal(looksLikeBlocked('Welcome to the store'), false);
});

test('parseCookiesFromEnv parses Playwright cookie JSON', () => {
  const prev = process.env.ALIEXPRESS_COOKIES_JSON;
  process.env.ALIEXPRESS_COOKIES_JSON = JSON.stringify([
    { name: 'a', value: '1', domain: '.aliexpress.com', path: '/', sameSite: 'Lax' },
    { name: 'b', value: '2', domain: '.aliexpress.com', path: '/', sameSite: 'no_restriction' },
  ]);
  const cookies = parseCookiesFromEnv();
  assert.ok(Array.isArray(cookies));
  assert.equal(cookies[0].name, 'a');
  assert.equal(cookies[0].domain, '.aliexpress.com');
  assert.equal(cookies[0].sameSite, 'Lax');
  assert.equal(cookies[1].sameSite, undefined);
  process.env.ALIEXPRESS_COOKIES_JSON = prev;
});

test('shouldRetry retries only blocked_by_antibot errors', () => {
  assert.equal(shouldRetry({ status: 'error', error_message: 'blocked_by_antibot' }), true);
  assert.equal(shouldRetry({ status: 'error', error_message: 'timeout' }), false);
  assert.equal(shouldRetry({ status: 'not_found' }), false);
  assert.equal(shouldRetry({ status: 'found' }), false);
});


