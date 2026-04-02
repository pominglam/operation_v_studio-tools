import { expect, test } from './fixtures';

test('reorder value updates immediately when available changes', async ({
    page,
    request,
    trackE2EProductId,
}) => {
    const uniq = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const sku = `E2E-REORDER-LIVE-${uniq}`;

    const create = await request.post('/api/v1/products', {
        data: {
            sku,
            description: `E2E reorder live ${uniq}`,
            vendor: 'Plamod',
            available: 1,
            maintain: 4,
        },
    });
    expect(create.ok(), `Create failed: HTTP ${create.status()} body=${await create.text()}`).toBeTruthy();
    const created = (await create.json()) as { data?: { id?: string } };
    const productId = String(created?.data?.id ?? '');
    expect(productId).not.toBe('');
    trackE2EProductId(productId);

    await page.goto('/products');
    await page.waitForResponse(
        (r) => r.url().includes('/api/v1/products') && r.request().method() === 'GET',
        { timeout: 30_000 },
    );

    await page.getByPlaceholder('Search SKU / barcode / name…').fill(sku);
    await page.waitForResponse(
        (r) =>
            r.url().includes('/api/v1/products') &&
            r.request().method() === 'GET' &&
            r.url().includes(encodeURIComponent(sku)),
        { timeout: 30_000 },
    );

    const reorderValue = page.getByTestId(`product-reorder-value:${productId}`);
    await expect(reorderValue).toHaveText('3');

    const availableInput = page.getByTestId(`product-available-input:${productId}`);
    await availableInput.fill('3');

    const saveAvailableReq = page.waitForRequest(
        (req) =>
            req.method() === 'PATCH' &&
            req.url().includes(`/api/v1/products/${productId}/available`),
        { timeout: 30_000 },
    );
    await availableInput.blur();
    await saveAvailableReq;

    await expect(reorderValue).toHaveText('1');
});
