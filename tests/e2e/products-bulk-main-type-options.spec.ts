import { expect, test } from './fixtures';

test('bulk update main type options hide filter-only __empty__ token', async ({
    page,
    request,
    trackE2EProductId,
}) => {
    const uniq = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const sku = `E2E-BULK-MT-${uniq}`;

    const create = await request.post('/api/v1/products', {
        data: {
            sku,
            description: `E2E bulk main type ${uniq}`,
            vendor: 'Plamod',
            main_type: 'model kit',
        },
    });
    expect(create.ok(), `Create failed: HTTP ${create.status()} body=${await create.text()}`).toBeTruthy();
    const createBody = (await create.json()) as any;
    trackE2EProductId(createBody?.data?.id);

    await page.goto('/products');
    await page.getByPlaceholder('Search SKU / barcode / name…').fill(sku);
    await page.waitForTimeout(700);

    const skuCell = page.getByText(sku, { exact: true });
    await expect(skuCell).toBeVisible();
    const row = page.locator('tr', { has: skuCell });
    await row.locator('input[type="checkbox"]').first().check();

    await page.locator('#app').getByRole('button', { name: 'Update selected' }).click();
    await expect(page.getByText('Bulk update products')).toBeVisible();

    await page.getByTestId('bulk-update-main-type-apply').check();
    await expect(
        page.locator('#bulk-update-main-type-options option[value="__empty__"]'),
    ).toHaveCount(0);
    await expect(
        page.locator('#bulk-update-main-type-options option[value="empty (no Shopify tags)"]'),
    ).toHaveCount(1);
});
