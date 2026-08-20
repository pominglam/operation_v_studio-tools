import { expect, test } from './fixtures';

test('maintenance Shopify status auto-updates without a manual refresh', async ({ page }) => {
    let settingsRequests = 0;

    await page.route('**/api/v1/shopify/settings', async (route) => {
        settingsRequests += 1;
        const completed = settingsRequests > 1;

        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                data: {
                    order_reconcile_interval_hours: 12,
                    tasks: [
                        {
                            key: 'inventory_pull',
                            label: 'Pull Shopify inventory',
                            status: completed ? 'completed' : 'running',
                            queued: false,
                            last_started_at: '2026-08-19T22:40:00Z',
                            last_finished_at: completed ? '2026-08-19T22:41:00Z' : null,
                            duration_ms: completed ? 60_000 : null,
                            records_fetched: completed ? 25 : null,
                            records_updated: completed ? 20 : null,
                            error_summary: null,
                            counts_json: null,
                        },
                    ],
                },
            }),
        });
    });

    await page.goto('/maintenance');

    const inventoryRow = page.getByRole('row').filter({ hasText: 'Pull Shopify inventory' });
    await expect(inventoryRow.getByText('Running', { exact: true })).toBeVisible();
    await expect(page.getByText('Work in progress — auto-updates every 5 seconds')).toBeVisible();

    await expect(inventoryRow.getByText('Completed', { exact: true })).toBeVisible({
        timeout: 8_000,
    });
    await expect(inventoryRow).toContainText('fetched 25, updated 20');
    expect(settingsRequests).toBeGreaterThanOrEqual(2);
});
