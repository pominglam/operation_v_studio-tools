import { expect, test } from './fixtures';

test('@smoke bulk update can clear main type to empty', async ({ page, request, trackE2EProductId }) => {
    const uniq = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const sku = `E2E-MT-EMPTY-${uniq}`;

    const create = await request.post('/api/v1/products', {
        data: {
            sku,
            description: `E2E main type empty ${uniq}`,
            vendor: 'Plamod',
            main_type: 'model kit',
            type: 'HG',
        },
    });
    const createBody = await create.text();
    expect(create.ok(), `Create failed: HTTP ${create.status()} body=${createBody}`).toBeTruthy();
    const created = (JSON.parse(createBody) as any) ?? null;
    const id = created?.data?.id as string | undefined;
    trackE2EProductId(id);

    await page.goto('/products');
    await page.waitForResponse(
        (r) => r.url().includes('/api/v1/products') && r.request().method() === 'GET',
        { timeout: 30_000 },
    );

    // Narrow the list so our seeded row is visible.
    await page.getByPlaceholder('Search SKU / barcode / name…').fill(sku);
    await page.waitForResponse(
        (r) => r.url().includes('/api/v1/products') && r.request().method() === 'GET',
        { timeout: 30_000 },
    );

    // Select the row.
    const skuCell = page.getByText(sku, { exact: true });
    await expect(skuCell).toBeVisible();
    const row = page.locator('tr', { has: skuCell });
    await row.locator('input[type="checkbox"]').first().check();

    // Open bulk update (this button appears when at least 1 row is selected).
    await page.locator('#app').getByRole('button', { name: 'Update selected' }).click();
    await expect(page.getByText('Bulk update products')).toBeVisible();

    // Enable main type apply and set empty.
    await page.getByTestId('bulk-update-main-type-apply').check();
    await expect(
        page.locator('#bulk-update-main-type-options option[value="empty (no Shopify tags)"]'),
    ).toHaveCount(1);
    await page.getByTestId('bulk-update-main-type-empty').click();

    // Confirm update.
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Update selected' })
        .click();
    await expect(page.getByText('Updated')).toBeVisible();

    // Verify via API.
    const res = await request.get(`/api/v1/products?per_page=10&search=${encodeURIComponent(sku)}`);
    expect(res.ok(), `List failed: HTTP ${res.status()} body=${await res.text()}`).toBeTruthy();
    const json = (await res.json()) as any;
    const p = (json?.data ?? []).find((x: any) => x?.sku === sku) ?? null;
    expect(p).toBeTruthy();
    expect(String(p?.main_type ?? '')).toBe('');

    // Verify via UI filter: "(empty)" main type should match.
    await page.getByPlaceholder('Search SKU / barcode / name…').fill('');
    await page.waitForResponse(
        (r) => r.url().includes('/api/v1/products') && r.request().method() === 'GET',
        { timeout: 30_000 },
    );

    await page.getByTestId('products-filter-main-type-button').click();
    await expect(page.getByTestId('products-filter-main-type-panel')).toBeVisible();
    await page
        .getByTestId('products-filter-main-type-panel')
        .getByText('(empty)', { exact: true })
        .click();

    // Narrow with search so pagination doesn't hide it.
    await page.getByPlaceholder('Search SKU / barcode / name…').fill(sku);
    await page.waitForResponse(
        (r) =>
            r.url().includes('/api/v1/products') &&
            r.request().method() === 'GET' &&
            r.url().includes('__empty__') &&
            r.url().includes(encodeURIComponent(sku)),
        { timeout: 30_000 },
    );

    // The product should be visible when filtering by empty main type.
    await expect(page.getByText(sku, { exact: true })).toBeVisible();
});

