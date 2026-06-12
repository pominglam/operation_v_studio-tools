const http = require('http');

const {
  downloadPlamodZipForSku,
  exportPlamodPreordersCsv,
  listManufacturerPreorderFilters,
  exportManufacturerPreordersCsv,
  searchRetailerPreorders,
  resetPlamodScraperSessions,
  enrichPreorderPdpFields,
} = require('./src/plamod');

function readJson(req) {
  return new Promise((resolve, reject) => {
    let body = '';
    req.on('data', (chunk) => {
      body += chunk;
      if (body.length > 1024 * 1024) {
        reject(new Error('Request too large'));
      }
    });
    req.on('end', () => {
      if (!body) return resolve({});
      try {
        resolve(JSON.parse(body));
      } catch {
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

async function withTimeout(promise, timeoutMs) {
  let t;
  const timeout = new Promise((_, reject) => {
    t = setTimeout(() => reject(new Error('timeout')), timeoutMs);
  });
  try {
    return await Promise.race([promise, timeout]);
  } finally {
    clearTimeout(t);
  }
}

const port = Number.parseInt(process.env.PORT || '3001', 10);
const requestTimeoutMs = Number.parseInt(process.env.PLAMOD_REQUEST_TIMEOUT_MS || '360000', 10);

const server = http.createServer(async (req, res) => {
  try {
    if (req.method === 'GET' && req.url === '/health') {
      return sendJson(res, 200, {
        ok: true,
        routes: [
          'POST /download-zip',
          'POST /export-preorders-csv',
          'POST /export-manufacturer-preorders-csv',
          'POST /list-manufacturer-preorders-filters',
          'POST /search-retailer-preorders',
          'POST /reset-scraper-sessions',
          'POST /enrich-preorder-pdp-fields',
        ],
      });
    }

    if (req.method === 'POST' && req.url === '/reset-scraper-sessions') {
      const started = Date.now();
      // eslint-disable-next-line no-console
      console.log('[plamod] reset-scraper-sessions start');
      try {
        const out = await resetPlamodScraperSessions();
        // eslint-disable-next-line no-console
        console.log(`[plamod] reset-scraper-sessions end ok=${Boolean(out?.ok)} ms=${Date.now() - started}`);
        return sendJson(res, 200, out);
      } catch (e) {
        return sendJson(res, 200, {
          ok: false,
          error_message: String(e?.message || 'Unknown error'),
          duration_ms: Date.now() - started,
        });
      }
    }

    if (req.method === 'POST' && req.url === '/download-zip') {
      const payload = await readJson(req);
      const sku = typeof payload.sku === 'string' ? payload.sku.trim() : '';
      if (!sku) {
        return sendJson(res, 422, { ok: false, error_message: 'sku is required' });
      }

      const started = Date.now();
      // eslint-disable-next-line no-console
      console.log(`[plamod] download-zip start sku=${sku}`);

      try {
        const out = await withTimeout(downloadPlamodZipForSku({ sku }), requestTimeoutMs);
        // eslint-disable-next-line no-console
        console.log(`[plamod] download-zip end sku=${sku} ok=${Boolean(out?.ok)} ms=${Date.now() - started}`);
        return sendJson(res, 200, out);
      } catch (e) {
        // Always return 200 with ok:false so the PHP side can show the message/debug.
        // eslint-disable-next-line no-console
        console.log(`[plamod] download-zip error sku=${sku} msg=${String(e?.message || 'Unknown error')} ms=${Date.now() - started}`);
        return sendJson(res, 200, { ok: false, sku, error_message: String(e?.message || 'Unknown error'), duration_ms: Date.now() - started });
      }
    }

    if (req.method === 'POST' && req.url === '/export-preorders-csv') {
      const started = Date.now();
      // eslint-disable-next-line no-console
      console.log('[plamod] export-preorders-csv start');

      try {
        const out = await withTimeout(exportPlamodPreordersCsv(), requestTimeoutMs);
        // eslint-disable-next-line no-console
        console.log(`[plamod] export-preorders-csv end ok=${Boolean(out?.ok)} ms=${Date.now() - started}`);
        return sendJson(res, 200, out);
      } catch (e) {
        // eslint-disable-next-line no-console
        console.log(`[plamod] export-preorders-csv error msg=${String(e?.message || 'Unknown error')} ms=${Date.now() - started}`);
        return sendJson(res, 200, { ok: false, error_message: String(e?.message || 'Unknown error'), duration_ms: Date.now() - started });
      }
    }

    if (req.method === 'POST' && req.url === '/export-manufacturer-preorders-csv') {
      const payload = await readJson(req);
      const manufacturerId = payload.manufacturer_id ?? payload.manufacturerId ?? 1;
      const tab = typeof payload.tab === 'string' ? payload.tab : 'Preorder';
      const series = typeof payload.series === 'string' && payload.series.trim() ? payload.series.trim() : null;
      const categoryLine =
        typeof payload.category_line === 'string' && payload.category_line.trim()
          ? payload.category_line.trim()
          : typeof payload.categoryLine === 'string' && payload.categoryLine.trim()
            ? payload.categoryLine.trim()
            : null;
      const category =
        series || categoryLine
          ? null
          : payload.category === null
            ? null
            : typeof payload.category === 'string'
              ? payload.category
              : 'Plastic Model Kits';
      const started = Date.now();
      // eslint-disable-next-line no-console
      console.log(
        `[plamod] export-manufacturer-preorders-csv start id=${manufacturerId} tab=${tab} series=${series || '-'} category_line=${categoryLine || '-'}`,
      );

      try {
        const out = await withTimeout(
          exportManufacturerPreordersCsv({ manufacturerId, tab, category, series, categoryLine }),
          requestTimeoutMs,
        );
        // eslint-disable-next-line no-console
        console.log(`[plamod] export-manufacturer-preorders-csv end ok=${Boolean(out?.ok)} rows=${out?.row_count ?? 0} ms=${Date.now() - started}`);
        return sendJson(res, 200, out);
      } catch (e) {
        // eslint-disable-next-line no-console
        console.log(`[plamod] export-manufacturer-preorders-csv error msg=${String(e?.message || 'Unknown error')} ms=${Date.now() - started}`);
        return sendJson(res, 200, { ok: false, error_message: String(e?.message || 'Unknown error'), duration_ms: Date.now() - started });
      }
    }

    if (req.method === 'POST' && req.url === '/list-manufacturer-preorders-filters') {
      const payload = await readJson(req);
      const manufacturerId = payload.manufacturer_id ?? payload.manufacturerId ?? 1;
      const tab = typeof payload.tab === 'string' ? payload.tab : 'Preorder';
      const started = Date.now();
      // eslint-disable-next-line no-console
      console.log(`[plamod] list-manufacturer-preorders-filters start id=${manufacturerId} tab=${tab}`);

      try {
        const out = await withTimeout(listManufacturerPreorderFilters({ manufacturerId, tab }), requestTimeoutMs);
        // eslint-disable-next-line no-console
        console.log(
          `[plamod] list-manufacturer-preorders-filters end ok=${Boolean(out?.ok)} series=${out?.series?.length ?? 0} ms=${Date.now() - started}`,
        );
        return sendJson(res, 200, out);
      } catch (e) {
        // eslint-disable-next-line no-console
        console.log(
          `[plamod] list-manufacturer-preorders-filters error msg=${String(e?.message || 'Unknown error')} ms=${Date.now() - started}`,
        );
        return sendJson(res, 200, { ok: false, error_message: String(e?.message || 'Unknown error'), duration_ms: Date.now() - started });
      }
    }

    if (req.method === 'POST' && req.url === '/enrich-preorder-pdp-fields') {
      const payload = await readJson(req);
      const skus = Array.isArray(payload.skus) ? payload.skus : [];
      const started = Date.now();
      // eslint-disable-next-line no-console
      console.log(`[plamod] enrich-preorder-pdp-fields start count=${skus.length}`);

      try {
        const out = await withTimeout(enrichPreorderPdpFields(skus), requestTimeoutMs);
        // eslint-disable-next-line no-console
        console.log(
          `[plamod] enrich-preorder-pdp-fields end ok=${Boolean(out?.ok)} enriched=${out?.enriched ?? 0} ms=${Date.now() - started}`,
        );
        return sendJson(res, 200, out);
      } catch (e) {
        // eslint-disable-next-line no-console
        console.log(`[plamod] enrich-preorder-pdp-fields error msg=${String(e?.message || 'Unknown error')} ms=${Date.now() - started}`);
        return sendJson(res, 200, { ok: false, error_message: String(e?.message || 'Unknown error'), duration_ms: Date.now() - started });
      }
    }

    if (req.method === 'POST' && req.url === '/search-retailer-preorders') {
      const payload = await readJson(req);
      const queries = Array.isArray(payload.queries) ? payload.queries : [];
      const started = Date.now();
      // eslint-disable-next-line no-console
      console.log(`[plamod] search-retailer-preorders start count=${queries.length}`);

      try {
        const out = await withTimeout(searchRetailerPreorders(queries), requestTimeoutMs);
        // eslint-disable-next-line no-console
        console.log(`[plamod] search-retailer-preorders end ok=${Boolean(out?.ok)} ms=${Date.now() - started}`);
        return sendJson(res, 200, out);
      } catch (e) {
        // eslint-disable-next-line no-console
        console.log(`[plamod] search-retailer-preorders error msg=${String(e?.message || 'Unknown error')} ms=${Date.now() - started}`);
        return sendJson(res, 200, { ok: false, error_message: String(e?.message || 'Unknown error'), duration_ms: Date.now() - started });
      }
    }

    return sendJson(res, 404, { ok: false, error_message: 'Not found' });
  } catch (e) {
    // Always return ok:false (200) so the caller can display the error message.
    return sendJson(res, 200, { ok: false, error_message: e?.message || 'Unknown error' });
  }
});

server.listen(port, '0.0.0.0', () => {
  // eslint-disable-next-line no-console
  console.log(`plamod-scraper listening on :${port}`);
});


