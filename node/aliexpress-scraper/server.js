const http = require('http');
const { scrapeAliExpressBySearch } = require('./src/scrape');
const fs = require('fs');
const path = require('path');

function readJson(req) {
  return new Promise((resolve, reject) => {
    let body = '';
    req.on('data', (chunk) => {
      body += chunk;
      // Hard cap ~1MB
      if (body.length > 1024 * 1024) {
        reject(new Error('Request too large'));
      }
    });
    req.on('end', () => {
      if (!body) return resolve({});
      try {
        resolve(JSON.parse(body));
      } catch (e) {
        reject(new Error('Invalid JSON'));
      }
    });
  });
}

function sendJson(res, status, payload) {
  const data = JSON.stringify(payload);
  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(data),
  });
  res.end(data);
}

const port = Number.parseInt(process.env.PORT || '3000', 10);
const cookiesFilePath = path.join(__dirname, '.cookies.json');

const server = http.createServer(async (req, res) => {
  try {
    if (req.method === 'GET' && req.url === '/health') {
      return sendJson(res, 200, { ok: true });
    }

    if (req.method === 'POST' && req.url === '/cookies') {
      const payload = await readJson(req);
      const cookies = payload?.cookies;
      if (!Array.isArray(cookies)) {
        return sendJson(res, 422, { ok: false, error_message: 'cookies must be an array' });
      }

      // Persist for container lifetime (volume-mounted app dir). Avoid logging content.
      fs.writeFileSync(cookiesFilePath, JSON.stringify(cookies), { encoding: 'utf8' });
      process.env.ALIEXPRESS_COOKIES_JSON = JSON.stringify(cookies);

      return sendJson(res, 200, { ok: true, count: cookies.length });
    }

    if (req.method === 'GET' && req.url === '/cookies') {
      if (process.env.ALIEXPRESS_COOKIES_JSON) {
        try {
          const arr = JSON.parse(process.env.ALIEXPRESS_COOKIES_JSON);
          return sendJson(res, 200, { ok: true, count: Array.isArray(arr) ? arr.length : 0 });
        } catch {
          return sendJson(res, 200, { ok: true, count: 0 });
        }
      }

      if (fs.existsSync(cookiesFilePath)) {
        try {
          const arr = JSON.parse(fs.readFileSync(cookiesFilePath, 'utf8'));
          return sendJson(res, 200, { ok: true, count: Array.isArray(arr) ? arr.length : 0 });
        } catch {
          return sendJson(res, 200, { ok: true, count: 0 });
        }
      }

      return sendJson(res, 200, { ok: true, count: 0 });
    }

    if (req.method === 'POST' && req.url === '/search-and-scrape') {
      const payload = await readJson(req);
      const term = typeof payload.term === 'string' ? payload.term.trim() : '';
      const sku = typeof payload.sku === 'string' ? payload.sku.trim() : null;
      const barcode = typeof payload.barcode === 'string' ? payload.barcode.trim() : null;
      if (!term) {
        return sendJson(res, 422, { status: 'error', error_message: 'term is required' });
      }

      const out = await scrapeAliExpressBySearch({
        term,
        sku,
        barcode,
        currency: process.env.ALIEXPRESS_CURRENCY || 'CAD',
        baseUrl: process.env.ALIEXPRESS_BASE_URL || 'https://www.aliexpress.com',
      });
      return sendJson(res, 200, out);
    }

    return sendJson(res, 404, { status: 'error', error_message: 'Not found' });
  } catch (e) {
    return sendJson(res, 500, { status: 'error', error_message: e?.message || 'Unknown error' });
  }
});

server.listen(port, '0.0.0.0', () => {
  // eslint-disable-next-line no-console
  console.log(`aliexpress-scraper listening on :${port}`);
});


