const http = require('http');

const { downloadPlamodZipForSku } = require('./src/plamod');

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
const requestTimeoutMs = Number.parseInt(process.env.PLAMOD_REQUEST_TIMEOUT_MS || '220000', 10);

const server = http.createServer(async (req, res) => {
  try {
    if (req.method === 'GET' && req.url === '/health') {
      return sendJson(res, 200, { ok: true });
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


