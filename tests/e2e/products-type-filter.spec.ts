import { expect, test } from './fixtures';

test('@smoke type filter shows options and filters list', async ({ page, request, trackE2EProductId }) => {
    const uniq = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const skuA = `E2E-TYPE-${uniq}-A`;
    const skuB = `E2E-TYPE-${uniq}-B`;

    const createA = await request.post('/api/v1/products', {
        data: {
            sku: skuA,
            description: `E2E type filter A ${uniq}`,
            vendor: 'Plamod',
            type: 'HG',
        },
    });
    const createABody = await createA.text();
    expect(
        createA.ok(),
        `Create A failed: HTTP ${createA.status()} body=${createABody}`,
    ).toBeTruthy();
    const createdA = (JSON.parse(createABody) as any) ?? null;
    trackE2EProductId(createdA?.data?.id);

    const createB = await request.post('/api/v1/products', {
        data: {
            sku: skuB,
            description: `E2E type filter B ${uniq}`,
            vendor: 'Plamod',
            type: 'TOOLS',
        },
    });
    const createBBody = await createB.text();
    expect(
        createB.ok(),
        `Create B failed: HTTP ${createB.status()} body=${createBBody}`,
    ).toBeTruthy();
    const createdB = (JSON.parse(createBBody) as any) ?? null;
    trackE2EProductId(createdB?.data?.id);

    await page.goto('/products');

    // Initial list fetch.
    const initialList = await page.waitForResponse(
        (r) => r.url().includes('/api/v1/products') && r.request().method() === 'GET',
        { timeout: 30_000 },
    );
    expect(initialList.ok(), `Initial products list failed: HTTP ${initialList.status()}`).toBeTruthy();

    // Open the Type filter and ensure our seeded type exists.
    await page.getByTestId('products-filter-type-button').click();
    await expect(page.getByTestId('products-filter-type-panel')).toBeVisible();
    await expect(page.getByTestId('products-filter-type-panel')).toContainText('TOOLS');

    // Select TOOLS; this should trigger a filtered products fetch.
    await page.getByTestId('products-filter-type-panel').getByText('TOOLS', { exact: true }).click();

    // Should include the TOOLS SKU and not include the HG SKU.
    await expect(page.getByText(skuB)).toBeVisible();
    await expect(page.getByText(skuA)).toHaveCount(0);
});

