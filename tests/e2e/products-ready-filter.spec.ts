import { expect, test } from './fixtures';

test('@smoke ready filter shows ready vs not ready products', async ({
    page,
    request,
    trackE2EProductId,
}) => {
    const uniq = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const skuReady = `E2E-READY-${uniq}-YES`;
    const skuNotReady = `E2E-READY-${uniq}-NO`;

    const createReady = await request.post('/api/v1/products', {
        data: {
            sku: skuReady,
            description: `E2E ready true ${uniq}`,
            vendor: 'Plamod',
            is_ready: true,
        },
    });
    const createReadyBody = await createReady.text();
    expect(
        createReady.ok(),
        `Create ready product failed: HTTP ${createReady.status()} body=${createReadyBody}`,
    ).toBeTruthy();
    const readyId = (((JSON.parse(createReadyBody) as any) ?? null)?.data?.id as string | undefined) ?? '';
    trackE2EProductId(readyId);

    const createNotReady = await request.post('/api/v1/products', {
        data: {
            sku: skuNotReady,
            description: `E2E ready false ${uniq}`,
            vendor: 'Plamod',
            is_ready: false,
        },
    });
    const createNotReadyBody = await createNotReady.text();
    expect(
        createNotReady.ok(),
        `Create not-ready product failed: HTTP ${createNotReady.status()} body=${createNotReadyBody}`,
    ).toBeTruthy();
    const notReadyId =
        (((JSON.parse(createNotReadyBody) as any) ?? null)?.data?.id as string | undefined) ?? '';
    trackE2EProductId(notReadyId);

    // Product create defaults to not-ready; explicitly mark one as ready.
    const setReady = await request.patch(`/api/v1/products/${readyId}/ready`, {
        data: { is_ready: true },
    });
    expect(
        setReady.ok(),
        `Set ready flag failed: HTTP ${setReady.status()} body=${await setReady.text()}`,
    ).toBeTruthy();

    await page.goto('/products');
    await page.waitForResponse(
        (r) => r.url().includes('/api/v1/products') && r.request().method() === 'GET',
        { timeout: 30_000 },
    );

    await page.getByPlaceholder('Search SKU / barcode / name…').fill(`E2E-READY-${uniq}`);
    await page.waitForResponse(
        (r) =>
            r.url().includes('/api/v1/products') &&
            r.request().method() === 'GET' &&
            r.url().includes(`search=${encodeURIComponent(`E2E-READY-${uniq}`)}`),
        { timeout: 30_000 },
    );

    await expect(page.getByText(skuReady, { exact: true })).toBeVisible();
    await expect(page.getByText(skuNotReady, { exact: true })).toBeVisible();

    await page.getByTestId('products-filter-ready').selectOption('ready');
    await page.waitForResponse(
        (r) =>
            r.url().includes('/api/v1/products') &&
            r.request().method() === 'GET' &&
            r.url().includes('ready=ready'),
        { timeout: 30_000 },
    );
    await expect(page.getByText(skuReady, { exact: true })).toBeVisible();
    await expect(page.getByText(skuNotReady, { exact: true })).toHaveCount(0);

    await page.getByTestId('products-filter-ready').selectOption('not_ready');
    await page.waitForResponse(
        (r) =>
            r.url().includes('/api/v1/products') &&
            r.request().method() === 'GET' &&
            r.url().includes('ready=not_ready'),
        { timeout: 30_000 },
    );
    await expect(page.getByText(skuNotReady, { exact: true })).toBeVisible();
    await expect(page.getByText(skuReady, { exact: true })).toHaveCount(0);
});
