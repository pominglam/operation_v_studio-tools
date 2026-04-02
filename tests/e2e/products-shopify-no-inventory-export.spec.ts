import { expect, test } from './fixtures';

test('@smoke export selected supports Shopify (CSV) (no inventory)', async ({ page, request, trackE2EProductId }) => {
    const uniq = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const sku = `E2E-SHP-NOINV-${uniq}`;

    const create = await request.post('/api/v1/products', {
        data: {
            sku,
            description: `E2E Shopify no inventory ${uniq}`,
            vendor: 'Plamod',
            main_type: 'model kit',
            type: 'HG',
        },
    });
    const createBodyText = await create.text();
    expect(create.ok(), `Create failed: HTTP ${create.status()} body=${createBodyText}`).toBeTruthy();
    const createBody = (JSON.parse(createBodyText) as any) ?? null;
    const productId = createBody?.data?.id as string | undefined;
    trackE2EProductId(productId);

    const sellingPrice = await request.put(`/api/v1/products/${productId}/selling-price`, {
        data: {
            selling_price: '17.99',
            currency: 'CAD',
        },
    });
    expect(
        sellingPrice.ok(),
        `Set selling price failed: HTTP ${sellingPrice.status()} body=${await sellingPrice.text()}`,
    ).toBeTruthy();

    await page.goto('/products');
    await page.waitForResponse(
        (r) => r.url().includes('/api/v1/products') && r.request().method() === 'GET',
        { timeout: 30_000 },
    );

    await page.getByPlaceholder('Search SKU / barcode / name…').fill(sku);
    await page.waitForResponse(
        (r) => r.url().includes('/api/v1/products') && r.request().method() === 'GET',
        { timeout: 30_000 },
    );

    const skuCell = page.getByText(sku, { exact: true });
    await expect(skuCell).toBeVisible();
    const row = page.locator('tr', { has: skuCell });
    await row.locator('input[type="checkbox"]').first().check();

    await page.locator('#app').getByRole('button', { name: 'Export selected' }).click();
    const dialog = page.locator('[role="dialog"]', { hasText: 'Export selected products' });
    await expect(dialog.getByText('Export selected products')).toBeVisible();

    await expect(dialog.locator('option[value="shopify_no_inventory"]')).toHaveCount(1);
    await dialog.locator('select').selectOption('shopify_no_inventory');

    const exportResponsePromise = page.waitForResponse(
        (r) =>
            r.url().includes('/api/v1/products/export/selected') &&
            r.request().method() === 'POST' &&
            (r.request().postData() ?? '').includes('"shopify_no_inventory"'),
        { timeout: 30_000 },
    );
    await dialog.getByRole('button', { name: 'Export' }).click();
    const exportResponse = await exportResponsePromise;

    expect(exportResponse.status()).toBe(200);
    const csv = await exportResponse.text();
    const lines = csv.trim().split(/\r\n|\n|\r/);
    expect(lines.length).toBe(2);

    const header = lines[0].split(',');
    expect(header).not.toContain('Variant Inventory Tracker');
    expect(header).not.toContain('Variant Inventory Qty');
    expect(header).not.toContain('Variant Inventory Policy');
    expect(csv).toContain(sku);
});
