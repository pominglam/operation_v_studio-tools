import { expect, test } from './fixtures';

test('products list shows not-arrived/reorder and export tab supports replenishment csv', async ({
    page,
    request,
    trackE2EProductId,
}) => {
    const uniq = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const sku = `E2E-REP-${uniq}`;

    const create = await request.post('/api/v1/products', {
        data: {
            sku,
            description: `E2E replenishment ${uniq}`,
            vendor: 'Plamod',
            main_type: 'model kit',
            type: 'HG',
        },
    });
    expect(
        create.ok(),
        `Create failed: HTTP ${create.status()} body=${await create.text()}`,
    ).toBeTruthy();
    const createBody = (await create.json()) as any;
    const productId = createBody?.data?.id as string;
    trackE2EProductId(productId);

    const setAvailable = await request.patch(`/api/v1/products/${productId}/available`, {
        data: { available: 1 },
    });
    expect(setAvailable.ok(), `Set available failed: HTTP ${setAvailable.status()}`).toBeTruthy();

    const setMaintain = await request.patch(`/api/v1/products/${productId}/maintain`, {
        data: { maintain: 4 },
    });
    expect(setMaintain.ok(), `Set maintain failed: HTTP ${setMaintain.status()}`).toBeTruthy();

    await page.goto('/products');
    await page.getByPlaceholder('Search SKU / barcode / name…').fill(sku);
    await page.waitForResponse(
        (r) => r.url().includes('/api/v1/products') && r.request().method() === 'GET',
        { timeout: 30_000 },
    );

    await expect(page.getByRole('columnheader', { name: 'Not arrived' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Reorder' })).toBeVisible();

    const skuCell = page.getByText(sku, { exact: true });
    await expect(skuCell).toBeVisible();
    const row = page.locator('tr', { has: skuCell });
    await expect(row).toContainText('0');
    await expect(row).toContainText('3');

    await page.getByRole('tab', { name: 'Export' }).click();
    const previewResponse = page.waitForResponse(
        (r) =>
            r.url().includes('/api/v1/products/replenishment/preview') &&
            r.request().method() === 'GET',
        { timeout: 30_000 },
    );
    await page.getByTestId('replenishment-preview-button').click();
    await previewResponse;
    await expect(page.getByRole('cell', { name: sku, exact: true }).first()).toBeVisible();

    const downloadPromise = page.waitForEvent('download', { timeout: 30_000 });
    await page.getByTestId('replenishment-export-button').click();
    const download = await downloadPromise;
    const stream = await download.createReadStream();
    expect(stream).toBeTruthy();
    let csv = '';
    stream?.setEncoding('utf8');
    for await (const chunk of stream ?? []) {
        csv += chunk;
    }
    expect(csv).toContain('Suggested Order Qty');
    expect(csv).toContain(sku);
});

test('products list keeps received PO quantity in not arrived until it is shelved', async ({
    page,
    request,
    trackE2EProductId,
    trackE2EPurchaseOrderId,
}) => {
    const uniq = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const sku = `E2E-RECEIVED-UNSHELVED-${uniq}`;

    const create = await request.post('/api/v1/products', {
        data: {
            sku,
            description: `E2E received unshelved ${uniq}`,
            vendor: 'Plamod',
            main_type: 'model kit',
        },
    });
    expect(
        create.ok(),
        `Create failed: HTTP ${create.status()} body=${await create.text()}`,
    ).toBeTruthy();
    const created = (await create.json()) as { data?: { id?: string } };
    trackE2EProductId(created.data?.id);

    const csv =
        ['SKU,Unit cost,Qty ordered,Qty shipped,Qty received', `${sku},1.23,7,7,`].join('\n') +
        '\n';
    const importPo = await request.post('/api/v1/purchase-orders/import', {
        multipart: {
            vendor: 'Plamod',
            received_date: '2026-08-19',
            file: {
                name: `received-unshelved-${uniq}.csv`,
                mimeType: 'text/csv',
                buffer: Buffer.from(csv, 'utf-8'),
            },
        },
    });
    expect(
        importPo.ok(),
        `PO import failed: HTTP ${importPo.status()} body=${await importPo.text()}`,
    ).toBeTruthy();
    const imported = (await importPo.json()) as { purchase_order_uuid?: string };
    const poUuid = imported.purchase_order_uuid ?? '';
    expect(poUuid).not.toBe('');
    trackE2EPurchaseOrderId(poUuid);

    await page.goto('/products');
    await page.getByPlaceholder('Search SKU / barcode / name…').fill(sku);
    await page.waitForResponse(
        (response) =>
            response.url().includes('/api/v1/products') &&
            response.request().method() === 'GET' &&
            response.url().includes(encodeURIComponent(sku)),
        { timeout: 30_000 },
    );

    const row = page.locator('tr', { has: page.getByText(sku, { exact: true }) });
    const headers = await page.locator('thead th').allTextContents();
    const notArrivedColumn = headers.findIndex((header) => header.trim() === 'Not arrived');

    expect(notArrivedColumn).toBeGreaterThan(-1);
    await expect(row.locator('td').nth(notArrivedColumn)).toHaveText('7');
});
