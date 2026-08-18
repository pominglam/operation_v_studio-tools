import { expect, test } from '@playwright/test';

test('plamod restock page loads and searches existing products @smoke', async ({
    page,
    request,
}) => {
    const uniq = Date.now();
    const sku = `E2E-RESTOCK-${uniq}`;

    await request.post('/api/v1/products', {
        data: {
            sku,
            description: `E2E Restock ${uniq}`,
            vendor: 'Plamod',
            available_qty: 0,
            maintain_qty: 3,
        },
    });

    await page.goto('/restocking/plamod');
    await expect(page.getByRole('heading', { name: 'PLAMOD restock' })).toBeVisible();
    await expect(page.getByTestId('restock-existing-table')).toBeVisible();
    await expect(page.getByTestId('restock-new-table')).toBeAttached();
    await expect(page.getByTestId('restock-shipping-percent')).toBeVisible();
    for (const group of ['total', 'existing', 'new']) {
        const uniqueProducts = page
            .getByTestId(`restock-cost-${group}`)
            .locator('div', { has: page.getByText('Unique products', { exact: true }) })
            .locator('dd');
        await expect(uniqueProducts).toHaveText(/^\d+$/);
    }

    const existingRows = page
        .getByTestId('restock-existing-table')
        .locator('tbody tr:has(a[href*="/retailer/products/"])');
    await expect(existingRows.first()).toBeVisible();
    const firstSku = (await existingRows.first().locator('td').nth(1).innerText()).trim();
    const firstType = (await existingRows.first().locator('td').nth(2).innerText()).trim();

    await page.getByTestId('restock-existing-search').fill(firstSku);
    await expect(existingRows).toHaveCount(1);
    await expect(existingRows.first()).toContainText(firstSku);

    await page.getByTestId('restock-existing-type-filter').selectOption(firstType);
    await expect(existingRows).toHaveCount(1);

    await page.getByTestId('restock-existing-search').fill('');
    await expect(existingRows.first()).toBeVisible();
    await page.getByTestId('restock-existing-type-filter').selectOption('');
});

test('included new product can persist zero order qty', async ({ page }) => {
    await page.goto('/restocking/plamod');
    await page.getByTestId('restock-tab-new').click();
    await page.getByRole('searchbox', { name: 'Search SKU or product name' }).fill('5073703');
    const input = page.getByTestId('restock-new-order-5073703');
    await expect(input).toBeVisible();

    for (const qty of ['1', '0']) {
        const saved = page.waitForResponse(
            (response) =>
                response.url().includes('/api/v1/plamod/restock/decisions/5073703') &&
                response.request().method() === 'PUT',
        );
        await input.fill(qty);
        await input.press('Tab');
        await expect((await saved).ok()).toBeTruthy();
        await expect(input).toHaveValue(qty);
    }

    await page.reload();
    await expect(page.getByTestId('restock-new-order-5073703')).toHaveValue('0');
    await expect(page.getByTestId('restock-verify-full-order')).toHaveText('Verify full order (8)');
});

test('full-order verification applies arrived preorders to new product planned qty', async ({
    page,
}) => {
    await page.addInitScript(() => {
        window.localStorage.removeItem('plamod-restock-dismissed-order-verify-at');
    });
    await page.route('**/api/v1/plamod/restock/order-verify', async (route) => {
        await route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify({
                data: {
                    ok: true,
                    all_verified: true,
                    order_matches_cart: true,
                    verified_at: '2026-08-18T04:00:00.000Z',
                    line_count: 1,
                    error_summary: null,
                    summary: {
                        requested_lines: 1,
                        verified: 0,
                        partial: 0,
                        over_added: 0,
                        missing: 0,
                        add_failed: 0,
                        already_satisfied: 1,
                        all_verified: true,
                        extra_cart_lines: 0,
                        order_matches_cart: true,
                    },
                    report: {
                        cart_url: 'https://plamod.com/retailer/cart',
                        verified_at: '2026-08-18T04:00:00.000Z',
                        scope: 'full_order',
                        preorder_arrived: { '5067248': 2 },
                        summary: {
                            requested_lines: 1,
                            verified: 0,
                            partial: 0,
                            over_added: 0,
                            missing: 0,
                            add_failed: 0,
                            already_satisfied: 1,
                            all_verified: true,
                            extra_cart_lines: 0,
                            order_matches_cart: true,
                        },
                        lines: [
                            {
                                sku: '5067248',
                                product_name: 'PG 1/60 GUNDAM ASTRAY RED FRAME KAI',
                                source: 'new',
                                requested_qty: 2,
                                preorder_arrived_qty: 2,
                                target_instock_qty: 0,
                                cart_qty_after: 0,
                                verification_status: 'already_satisfied',
                            },
                        ],
                    },
                },
            }),
        });
    });

    await page.goto('/restocking/plamod');

    const report = page.getByTestId('restock-order-verify-report');
    await expect(report).toContainText('Planned qty');
    await expect(report).toContainText('Arrived preorder qty');
    await expect(report).toContainText('Required in-stock qty');
    await expect(report).toContainText('In-stock cart qty');
    await page.getByLabel('Show mismatches only').uncheck();
    const row = page.getByTestId('restock-order-verify-row-5067248');
    await expect(row).toContainText('2');
    await expect(row).toContainText('2 arrived preorder units applied toward the planned quantity');
    await expect(row).toContainText('required in-stock quantity is 0');
});
