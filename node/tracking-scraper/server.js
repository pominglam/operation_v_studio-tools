const http = require('http');
const { chromium } = require('playwright');

const { createProviders } = require('./src/providers');
const { resolveWithProviders } = require('./src/resolver');

const port = Number.parseInt(process.env.PORT || '3002', 10);
const requestTimeoutMs = Number.parseInt(
    process.env.TRACKING_SCRAPER_REQUEST_TIMEOUT_MS || '150000',
    10,
);
let browserPromise;

function browser() {
    browserPromise ??= chromium.launch({ headless: true });
    return browserPromise;
}

function readJson(req) {
    return new Promise((resolve, reject) => {
        let body = '';
        req.on('data', (chunk) => {
            body += chunk;
            if (body.length > 16_384) reject(new Error('Request too large'));
        });
        req.on('end', () => {
            try {
                resolve(body ? JSON.parse(body) : {});
            } catch {
                reject(new Error('Invalid JSON'));
            }
        });
    });
}

function sendJson(res, status, payload) {
    const body = JSON.stringify(payload);
    res.writeHead(status, {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(body),
    });
    res.end(body);
}

function validTrackingNumber(value) {
    return (
        typeof value === 'string' &&
        value.trim().length >= 4 &&
        value.trim().length <= 255 &&
        /^[\p{L}\p{N}._\-/ ]+$/u.test(value.trim())
    );
}

async function withTimeout(promise, timeoutMs) {
    let timer;
    const timeout = new Promise((_, reject) => {
        timer = setTimeout(
            () => reject(new Error('Tracking provider probe timed out.')),
            timeoutMs,
        );
    });
    try {
        return await Promise.race([promise, timeout]);
    } finally {
        clearTimeout(timer);
    }
}

const server = http.createServer(async (req, res) => {
    if (req.method === 'GET' && req.url === '/health') {
        return sendJson(res, 200, { ok: true, routes: ['POST /resolve'] });
    }
    if (req.method !== 'POST' || req.url !== '/resolve') {
        return sendJson(res, 404, { ok: false, error_message: 'Not found' });
    }

    try {
        const payload = await readJson(req);
        if (!validTrackingNumber(payload.tracking_number)) {
            return sendJson(res, 422, {
                status: 'failed',
                error_message: 'A valid tracking_number is required.',
            });
        }

        const trackingNumber = payload.tracking_number.trim();
        const startedAt = Date.now();
        console.log(`[tracking] resolve start number=${trackingNumber}`);
        const activeBrowser = await browser();
        const result = await withTimeout(
            resolveWithProviders({
                trackingNumber,
                providers: createProviders(activeBrowser),
            }),
            requestTimeoutMs,
        );
        console.log(
            `[tracking] resolve end number=${trackingNumber} status=${result.status} provider=${result.provider || '-'} ms=${Date.now() - startedAt}`,
        );
        return sendJson(res, 200, result);
    } catch (error) {
        console.error(`[tracking] resolve error message=${String(error?.message || error)}`);
        return sendJson(res, 200, {
            status: 'failed',
            provider: null,
            tracking_url: null,
            event_count: 0,
            error_message: String(error?.message || 'Unknown tracking worker error').slice(0, 300),
        });
    }
});

server.listen(port, '0.0.0.0', () => {
    console.log(`[tracking] listening on ${port}`);
});

async function shutdown() {
    server.close();
    if (browserPromise) {
        const activeBrowser = await browserPromise.catch(() => null);
        await activeBrowser?.close();
    }
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
