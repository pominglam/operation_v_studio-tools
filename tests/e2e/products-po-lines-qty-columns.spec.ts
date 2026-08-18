import { expect, test } from './fixtures';

test('product PO lines drawer shows qty shipped and qty received columns', async ({
    page,
    request,
    trackE2EProductId,
    trackE2EPurchaseOrderId,
}) => {
    test.setTimeout(90_000);
    await page.setViewportSize({ width: 1440, height: 900 });

    const uniq = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const sku = `E2E-PO-LINES-QTY-${uniq}`;

    const create = await request.post('/api/v1/products', {
        data: {
            sku,
            description: `E2E PO lines qty ${uniq}`,
            vendor: 'Plamod',
            type: 'HG',
        },
    });
    const createBody = await create.text();
    expect(create.ok(), `Create failed: HTTP ${create.status()} body=${createBody}`).toBeTruthy();
    const created = (JSON.parse(createBody) as any) ?? null;
    trackE2EProductId(created?.data?.id);

    // Keep qty_received at 0 so PO deletion remains allowed in cleanup.
    const csv =
        ['SKU,Unit cost,Qty ordered,Qty shipped,Qty received', `${sku},1.23,5,3,0`].join('\n') +
        '\n';
    const importPo = await request.post('/api/v1/purchase-orders/import', {
        multipart: {
            vendor: 'Plamod',
            file: {
                name: `po-lines-qty-${uniq}.csv`,
                mimeType: 'text/csv',
                buffer: Buffer.from(csv, 'utf-8'),
            },
        },
    });
    expect(
        importPo.ok(),
        `PO import failed: HTTP ${importPo.status()} body=${await importPo.text()}`,
    ).toBeTruthy();
    const importBody = (await importPo.json()) as any;
    const poUuid = String(importBody?.purchase_order_uuid ?? '');
    expect(poUuid).not.toBe('');
    trackE2EPurchaseOrderId(poUuid);

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

    const row = page.locator('tr', { has: page.getByText(sku, { exact: true }) });
    const poLinesResponse = page.waitForResponse(
        (r) =>
            r.url().includes('/api/v1/products/') &&
            r.url().includes('/po-lines') &&
            r.request().method() === 'GET',
        { timeout: 30_000 },
    );
    await row.getByRole('button', { name: 'PO Lines', exact: true }).click();
    await poLinesResponse;

    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('PO Lines');
    const panel = dialog.getByTestId('product-po-lines-panel');
    await expect(panel).toBeVisible();
    const panelBox = await panel.boundingBox();
    expect(panelBox?.width).toBeGreaterThanOrEqual(1200);
    await expect(dialog.getByText('Qty ordered', { exact: true })).toBeVisible();
    await expect(dialog.getByText('Qty shipped', { exact: true })).toBeVisible();
    await expect(dialog.getByText('Qty received', { exact: true })).toBeVisible();

    const poRow = dialog.locator('tbody tr').first();
    await expect(poRow).toBeVisible();
    await expect(poRow.locator('td:nth-child(5)')).toHaveText('5');
    await expect(poRow.locator('td:nth-child(6)')).toHaveText('3');
    await expect(poRow.locator('td:nth-child(7)')).toHaveText('0');
});
