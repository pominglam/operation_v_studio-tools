import { expect, test } from './fixtures';

test('staff orders report loads day rows and order count', async ({ page }) => {
    test.setTimeout(240_000);

    await page.route('**/api/v1/reports/staff-orders?**', async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                data: {
                    from_month: '2026-07',
                    to_month: '2026-07',
                    month: '2026-07',
                    timezone: 'America/Toronto',
                    columns: [
                        { key: 'date', label: 'Date' },
                        { key: 'alex_hui', label: 'Alex Hui' },
                        { key: 'total', label: 'Total' },
                    ],
                    rows: [
                        { date: '2026-07-01', alex_hui: 2, total: 2 },
                        { date: '2026-07-02', alex_hui: 0, total: 0 },
                    ],
                    totals: { alex_hui: 2, total: 2 },
                    revenue_rows: [
                        { date: '2026-07-01', alex_hui: '100.00', total: '100.00' },
                        { date: '2026-07-02', alex_hui: '0.00', total: '0.00' },
                    ],
                    revenue_totals: { alex_hui: '100.00', total: '100.00' },
                    revenue_currency: 'CAD',
                    orders_scanned: 2,
                },
            }),
        });
    });

    await page.goto('/reports/staff-orders', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: 'Staff orders' })).toBeVisible();

    await expect(page.getByText('Showing 2 days')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText('Orders counted: 2')).toBeVisible();
    await expect(page.locator('#staff-orders-month')).toBeVisible();
    await expect(page.locator('#staff-orders-view-mode')).toBeVisible();
    await expect(page.getByText('Wed, Jul 1')).toBeVisible();

    await page.locator('#staff-orders-view-mode').selectOption('revenue');
    await expect(page.getByText('CA$100.00').first()).toBeVisible();
    await expect(page.getByText('zero day rows')).toHaveCount(0);
});
