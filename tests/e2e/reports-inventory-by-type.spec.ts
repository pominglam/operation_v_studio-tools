import { expect, test } from './fixtures';

test('reports hub shows navigation and inventory by type report', async ({ page }) => {
    await page.route('**/api/v1/reports/inventory-by-main-type', async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                data: {
                    data_source: 'products',
                    scope: 'active_products_on_hand_available_qty',
                    currency: 'CAD',
                    rows: [
                        {
                            type: 'HG',
                            type_label: 'HG',
                            main_type: 'model kit',
                            catalog_skus: 784,
                            skus_on_hand: 219,
                            quantity_on_hand: 434,
                            not_arrived_skus: 5,
                            not_arrived: 120,
                            estimated_landed_value: '5000.00',
                            estimated_not_landed_value: '600.00',
                            skus_missing_landed_cost: 0,
                            units_received: 5000,
                            units_sold: 3000,
                        },
                        {
                            type: 'pliers',
                            type_label: 'pliers',
                            main_type: 'tools',
                            catalog_skus: 100,
                            skus_on_hand: 71,
                            quantity_on_hand: 304,
                            not_arrived_skus: 2,
                            not_arrived: 50,
                            estimated_landed_value: '1200.00',
                            estimated_not_landed_value: '200.00',
                            skus_missing_landed_cost: 0,
                            units_received: 800,
                            units_sold: 400,
                        },
                        {
                            type: 'tape',
                            type_label: 'tape',
                            main_type: 'supplies',
                            catalog_skus: 50,
                            skus_on_hand: 10,
                            quantity_on_hand: 50,
                            not_arrived_skus: 0,
                            not_arrived: 0,
                            estimated_landed_value: '300.00',
                            estimated_not_landed_value: '0.00',
                            skus_missing_landed_cost: 0,
                            units_received: 100,
                            units_sold: 50,
                        },
                        {
                            type: 'decals',
                            type_label: 'decals',
                            main_type: 'water decals',
                            catalog_skus: 12,
                            skus_on_hand: 8,
                            quantity_on_hand: 20,
                            not_arrived_skus: 0,
                            not_arrived: 0,
                            estimated_landed_value: '80.00',
                            estimated_not_landed_value: '0.00',
                            skus_missing_landed_cost: 0,
                            units_received: 40,
                            units_sold: 12,
                        },
                        {
                            type: 'misc-item',
                            type_label: 'misc-item',
                            main_type: 'misc',
                            catalog_skus: 25,
                            skus_on_hand: 5,
                            quantity_on_hand: 25,
                            not_arrived_skus: 0,
                            not_arrived: 0,
                            estimated_landed_value: '100.00',
                            estimated_not_landed_value: '0.00',
                            skus_missing_landed_cost: 0,
                            units_received: 25,
                            units_sold: 10,
                        },
                    ],
                    totals: {
                        catalog_skus: 959,
                        skus_on_hand: 305,
                        quantity_on_hand: 813,
                        not_arrived_skus: 7,
                        not_arrived: 170,
                        estimated_landed_value: '6600.00',
                        estimated_not_landed_value: '800.00',
                        skus_missing_landed_cost: 0,
                        units_received: 5925,
                        units_sold: 3460,
                    },
                },
            }),
        });
    });

    await page.goto('/reports/inventory-by-main-type', { waitUntil: 'domcontentloaded' });

    await expect(page.getByRole('heading', { name: 'Reports' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Inventory by type' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Inventory by type' })).toBeVisible();
    await expect(page.getByText('305 SKU(s) on hand · 813 units · estimated landed')).toBeVisible({
        timeout: 30_000,
    });
    await expect(page.getByText('Received / sold')).toBeVisible();
    await expect(page.getByTestId('type-row-hg')).toBeVisible();

    const groupButtons = page.locator('tbody button[aria-expanded]');
    await expect(groupButtons).toHaveText([
        'Model kits',
        'Tools & Supplies',
        'Water decals',
        'Miscellaneous',
    ]);

    await page.getByRole('button', { name: 'Collapse Tools & Supplies' }).click();
    await expect(page.getByTestId('type-row-pliers')).toBeHidden();
    await expect(page.getByTestId('type-row-tape')).toBeHidden();
    await expect(page.getByRole('button', { name: 'Expand Tools & Supplies' })).toHaveAttribute(
        'aria-expanded',
        'false',
    );
});
