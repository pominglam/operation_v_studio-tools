import { expect, test } from './fixtures';

test('bulk search finds products from multi-line paste', async ({ page, request, trackE2EProductId }) => {
    const uniq = String(Date.now());
    const skuA = `E2E-${uniq}-A`;
    const skuB = `E2E-${uniq}-B`;

    const nameA = `Action Base 5 1/144 Black ${uniq}`;
    const nameB = `OLFA Knife ${uniq}`;

    // Seed a couple products via API (no UI setup needed).
    const createA = await request.post('/api/v1/products', {
        data: { sku: skuA, description: nameA, barcode: `E2E-${uniq}-111`, vendor: 'Plamod' },
    });
    expect(createA.ok()).toBeTruthy();
    const createdA = (await createA.json()) as any;
    trackE2EProductId(createdA?.data?.id);

    const createB = await request.post('/api/v1/products', {
        data: { sku: skuB, description: nameB, barcode: `E2E-${uniq}-222`, vendor: 'Plamod' },
    });
    expect(createB.ok()).toBeTruthy();
    const createdB = (await createB.json()) as any;
    trackE2EProductId(createdB?.data?.id);

    await page.goto('/products');

    // Wait for initial page fetch to settle.
    const initialList = await page.waitForResponse(
        (r) => r.url().includes('/api/v1/products') && r.request().method() === 'GET',
        { timeout: 30_000 },
    );
    expect(initialList.ok(), `Initial products list failed: HTTP ${initialList.status()}`).toBeTruthy();

    await page.getByTestId('products-search-bulk').click();

    // Use the same style of multi-line input as real usage (with duplicates / mixed case).
    await page.getByTestId('products-bulk-textarea').fill(
        [
            `Action Base 5 1/144 Black ${uniq}`,
            `Action Base 5 1/144 Black ${uniq}`,
            `OLFA Knife ${uniq}`,
            `OLFA Knife ${uniq}`,
            'ENTRY GRADE 1/144 RX-78-2 GUNDAM',
            'ENTRY GRADE 1/144 RX-78-2 Gundam',
        ].join('\n'),
    );

    // Wait for the bulk search to trigger a new products fetch.
    const afterSearchPromise = page.waitForResponse(
        (r) => r.url().includes('/api/v1/products') && r.request().method() === 'GET',
        { timeout: 30_000 },
    );
    await page.getByTestId('products-bulk-search').click();
    const afterSearch = await afterSearchPromise;
    expect(afterSearch.ok(), `Products fetch after bulk search failed: HTTP ${afterSearch.status()}`).toBeTruthy();

    // Should not show a hard failure banner.
    await expect(page.getByTestId('products-error')).toHaveCount(0);

    // Should include both seeded products (unique SKUs).
    await expect(page.getByText(skuA)).toBeVisible();
    await expect(page.getByText(skuB)).toBeVisible();
});

