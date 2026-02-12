import { expect, test } from './fixtures';

test('maintenance shows external access card and can start tunnel via API', async ({ page }) => {
    // Stub external access status.
    await page.route('**/api/v1/maintenance/external-access', async (route) => {
        const req = route.request();
        if (req.method() === 'GET') {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    data: {
                        enabled: true,
                        password_configured: true,
                        tunnel: {
                            running: false,
                            tunnel_url: null,
                            container_id: 'cid',
                            error: null,
                            reachable: null,
                            reachable_http_status: null,
                            reachable_checked_at: null,
                            reachable_error: null,
                        },
                    },
                }),
            });
            return;
        }

        if (req.method() === 'PUT') {
            const body = (req.postDataJSON?.() ?? null) as any;
            expect(body?.enabled).toBe(true);
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    data: {
                        enabled: true,
                        tunnel: {
                            running: true,
                            tunnel_url: 'https://example.trycloudflare.com',
                            container_id: 'cid',
                            error: null,
                            reachable: true,
                            reachable_http_status: 200,
                            reachable_checked_at: new Date().toISOString(),
                            reachable_error: null,
                        },
                    },
                }),
            });
            return;
        }

        await route.fulfill({ status: 405, body: 'method not allowed' });
    });

    await page.goto('/maintenance');

    // Card exists.
    await expect(page.getByText('External access', { exact: true })).toBeVisible();
    await expect(page.getByText('Status:', { exact: false })).toBeVisible();
    await expect(page.getByText('Enabled')).toBeVisible();
    await expect(page.getByText('Tunnel stopped')).toBeVisible();

    // Since enabled=true but tunnel stopped, button should allow starting tunnel.
    const startBtn = page.getByRole('button', { name: 'Start / Update tunnel' });
    await expect(startBtn).toBeEnabled();
    await startBtn.click();

    // UI should show URL from PUT response.
    await expect(page.getByText('https://example.trycloudflare.com')).toBeVisible();
});

