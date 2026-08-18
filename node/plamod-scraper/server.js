const http = require('http');

const {
  downloadPlamodZipForSku,
  exportPlamodPreordersCsv,
  listManufacturerPreorderFilters,
  exportManufacturerPreordersCsv,
  exportManufacturerInstockMerged,
  readInstockExportProgress,
  searchRetailerPreorders,
  resetPlamodScraperSessions,
  enrichPreorderPdpFields,
  ensureLoggedInQuick,
  ensureOnRetailerPdp,
} = require('./src/plamod');
const {
  restockAddLinesToCart,
  restockVerifyCart,
  readRestockCartProgress,
} = require('./src/plamod-restock-cart');

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
const instockMergedTimeoutMs = Number.parseInt(process.env.PLAMOD_INSTOCK_MERGED_TIMEOUT_MS || '10800000', 10);
const restockCartTimeoutMs = Number.parseInt(process.env.PLAMOD_RESTOCK_CART_TIMEOUT_MS || '7200000', 10);
const restockVerifyTimeoutMs = Number.parseInt(process.env.PLAMOD_RESTOCK_VERIFY_TIMEOUT_MS || '180000', 10);

const server = http.createServer(async (req, res) => {
  try {
    if (req.method === 'GET' && req.url === '/health') {
      return sendJson(res, 200, {
        ok: true,
        routes: [
          'POST /download-zip',
          'POST /export-preorders-csv',
          'POST /export-manufacturer-preorders-csv',
          'POST /export-manufacturer-instock-merged',
          'GET /instock-export-progress',
          'POST /list-manufacturer-preorders-filters',
          'POST /search-retailer-preorders',
          'POST /reset-scraper-sessions',
          'POST /enrich-preorder-pdp-fields',
          'POST /restock-add-to-cart',
          'POST /restock-verify-cart',
          'GET /restock-cart-progress',
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

    if (req.method === 'GET' && req.url === '/instock-export-progress') {
      return sendJson(res, 200, readInstockExportProgress());
    }

    if (req.method === 'POST' && req.url === '/export-manufacturer-instock-merged') {
      const payload = await readJson(req);
      const manufacturerId = payload.manufacturer_id ?? payload.manufacturerId ?? 1;
      const maxFiltersRaw = payload.max_filters ?? payload.maxFilters ?? 0;
      const maxFilters = Number.parseInt(String(maxFiltersRaw), 10) || 0;
      const started = Date.now();
      // eslint-disable-next-line no-console
      console.log(
        `[plamod] export-manufacturer-instock-merged start id=${manufacturerId}${maxFilters > 0 ? ` max_filters=${maxFilters}` : ''}`,
      );

      try {
        const out = await withTimeout(
          exportManufacturerInstockMerged({ manufacturerId, maxFilters }),
          instockMergedTimeoutMs,
        );
        // eslint-disable-next-line no-console
        console.log(
          `[plamod] export-manufacturer-instock-merged end ok=${Boolean(out?.ok)} rows=${out?.row_count ?? 0} ms=${Date.now() - started}`,
        );
        return sendJson(res, 200, out);
      } catch (e) {
        // eslint-disable-next-line no-console
        console.log(
          `[plamod] export-manufacturer-instock-merged error msg=${String(e?.message || 'Unknown error')} ms=${Date.now() - started}`,
        );
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

    if (req.method === 'GET' && req.url === '/restock-cart-progress') {
      return sendJson(res, 200, readRestockCartProgress());
    }

    if (req.method === 'POST' && req.url === '/restock-add-to-cart') {
      const payload = await readJson(req);
      const items = Array.isArray(payload.items) ? payload.items : [];
      const started = Date.now();
      // eslint-disable-next-line no-console
      console.log(`[plamod] restock-add-to-cart start count=${items.length}`);

      try {
        await resetPlamodScraperSessions();
        const out = await withTimeout(
          restockAddLinesToCart({ ensureLoggedInQuick, ensureOnRetailerPdp }, payload),
          restockCartTimeoutMs,
        );
        // eslint-disable-next-line no-console
        console.log(
          `[plamod] restock-add-to-cart end ok=${Boolean(out?.ok)} verified=${out?.report?.summary?.verified ?? 0}/${out?.report?.summary?.requested_lines ?? 0} ms=${Date.now() - started}`,
        );
        return sendJson(res, 200, out);
      } catch (e) {
        // eslint-disable-next-line no-console
        console.log(`[plamod] restock-add-to-cart error msg=${String(e?.message || 'Unknown error')} ms=${Date.now() - started}`);
        return sendJson(res, 200, {
          ok: false,
          error_message: String(e?.message || 'Unknown error'),
          duration_ms: Date.now() - started,
        });
      }
    }

    if (req.method === 'POST' && req.url === '/restock-verify-cart') {
      const payload = await readJson(req);
      const lineCount = Array.isArray(payload.lines) ? payload.lines.length : 0;
      const started = Date.now();
      // eslint-disable-next-line no-console
      console.log(`[plamod] restock-verify-cart start lines=${lineCount}`);

      try {
        await resetPlamodScraperSessions();
        const out = await withTimeout(
          restockVerifyCart({ ensureLoggedInQuick }, payload),
          restockVerifyTimeoutMs,
        );
        // eslint-disable-next-line no-console
        console.log(
          `[plamod] restock-verify-cart end ok=${Boolean(out?.ok)} verified=${out?.report?.summary?.verified ?? 0}/${out?.report?.summary?.requested_lines ?? 0} ms=${Date.now() - started}`,
        );
        return sendJson(res, 200, out);
      } catch (e) {
        // eslint-disable-next-line no-console
        console.log(`[plamod] restock-verify-cart error msg=${String(e?.message || 'Unknown error')} ms=${Date.now() - started}`);
        return sendJson(res, 200, {
          ok: false,
          error_message: String(e?.message || 'Unknown error'),
          duration_ms: Date.now() - started,
        });
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


