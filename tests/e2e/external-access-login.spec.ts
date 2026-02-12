import { expect, test } from './fixtures';
import fs from 'node:fs';
import path from 'node:path';

function readExternalPasswordFromDotEnv(): string | null {
    try {
        const p = path.resolve(process.cwd(), '.env');
        const raw = fs.readFileSync(p, 'utf8');
        const m = raw.match(/^\s*EXTERNAL_ACCESS_PASSWORD\s*=\s*(.+)\s*$/m);
        if (!m) return null;
        let v = String(m[1] ?? '').trim();
        // strip optional quotes
        if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) {
            v = v.slice(1, -1);
        }
        return v.trim() ? v.trim() : null;
    } catch {
        return null;
    }
}

test.describe.configure({ mode: 'serial' });

test('external tunnel: enable -> login -> use app; disable -> 404; Shopify tunnel still OK', async ({ page }) => {
    const pw = readExternalPasswordFromDotEnv();
    test.skip(!pw, 'EXTERNAL_ACCESS_PASSWORD not set in .env');

    test.setTimeout(180_000);

    // Enable external access (this also starts/updates the app quick tunnel).
    const enableRes = await page.request.put('/api/v1/maintenance/external-access', {
        data: { enabled: true },
    });
    expect(enableRes.ok()).toBeTruthy();

    // Poll until we have a tunnel URL.
    let appTunnelBaseUrl = '';
    const startedAt = Date.now();
    while (Date.now() - startedAt < 120_000) {
        const res = await page.request.get('/api/v1/maintenance/external-access');
        if (res.ok()) {
            const json = (await res.json()) as any;
            const u = String(json?.data?.tunnel?.tunnel_url ?? '').trim();
            if (u) {
                appTunnelBaseUrl = u;
                break;
            }
        }
        await new Promise((r) => setTimeout(r, 1200));
    }
    expect(appTunnelBaseUrl).toMatch(/^https:\/\/.+\.trycloudflare\.com$/);

    // Visiting the tunnel should show the external-login page (redirect or direct).
    const productsUrl = `${appTunnelBaseUrl}/products`;
    // Quick tunnels can take a bit to become resolvable/reachable. Retry until we stop seeing the Cloudflare error page.
    const waitTunnelStart = Date.now();
    while (Date.now() - waitTunnelStart < 90_000) {
        const resp = await page.goto(productsUrl, { waitUntil: 'domcontentloaded' });
        const st = resp?.status() ?? 0;
        const isCloudflareError = await page.getByText('Cloudflare Tunnel error').isVisible().catch(() => false);
        if (st !== 530 && !isCloudflareError) break;
        await page.waitForTimeout(1500);
    }

    // If we're not already on Products, we should be on the external-login page.
    const hasProducts = await page.getByRole('heading', { name: 'Products' }).isVisible().catch(() => false);
    if (!hasProducts) {
        await expect(page.getByText('External access')).toBeVisible();
    }

    // Log in and land on Products page through the tunnel host.
    const hasPassword = await page.getByLabel('Password').isVisible().catch(() => false);
    if (hasPassword) {
        await page.getByLabel('Password').fill(pw!);
        await page.getByRole('button', { name: 'Log in' }).click();
    }

    await expect(page).toHaveURL(/\/products/);
    expect(page.url().startsWith(`${appTunnelBaseUrl}/`)).toBe(true);
    await expect(page.getByRole('heading', { name: 'Products' })).toBeVisible();

    // Disable external access: app + login should become 404 through the tunnel.
    const disableRes = await page.request.put('/api/v1/maintenance/external-access', {
        data: { enabled: false },
    });
    expect(disableRes.ok()).toBeTruthy();

    const resp = await page.goto(`${appTunnelBaseUrl}/external-login?next=/products`, { waitUntil: 'domcontentloaded' });
    // When disabled, the app must not be reachable externally.
    // Depending on whether the app tunnel is still up, this can be:
    // - 404 from our middleware (tunnel running, but access disabled)
    // - 530 from Cloudflare (tunnel stopped / no active tunnel)
    expect([404, 530]).toContain(resp?.status());

    // Shopify images tunnel should still be available (separate tunnel).
    const shopRes = await page.request.get('/api/v1/shopify/image-tunnel');
    expect(shopRes.ok()).toBeTruthy();
    const shopJson = (await shopRes.json()) as any;
    expect(String(shopJson?.tunnel_url ?? '')).toMatch(/^https:\/\/.+\.trycloudflare\.com$/);
});

