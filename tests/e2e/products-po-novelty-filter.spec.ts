import { expect, test } from './fixtures';

test('@smoke PO novelty filter shows new vs existing products', async ({
    page,
    request,
    trackE2EProductId,
    trackE2EPurchaseOrderId,
}) => {
    test.setTimeout(120_000);

    const uniq = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const existingSku = `E2E-PO-NOVELTY-EXISTING-${uniq}`;
    const newSku = `E2E-PO-NOVELTY-NEW-${uniq}`;

    const createExisting = await request.post('/api/v1/products', {
        data: {
            sku: existingSku,
            description: `E2E existing in selected PO ${uniq}`,
            vendor: 'Plamod',
            type: 'HG',
        },
    });
    const createExistingBody = await createExisting.text();
    expect(
        createExisting.ok(),
        `Create existing failed: HTTP ${createExisting.status()} body=${createExistingBody}`,
    ).toBeTruthy();
    const createdExisting = (JSON.parse(createExistingBody) as any) ?? null;
    trackE2EProductId(createdExisting?.data?.id);

    const createNew = await request.post('/api/v1/products', {
        data: {
            sku: newSku,
            description: `E2E new in selected PO ${uniq}`,
            vendor: 'Plamod',
            type: 'HG',
        },
    });
    const createNewBody = await createNew.text();
    expect(createNew.ok(), `Create new failed: HTTP ${createNew.status()} body=${createNewBody}`).toBeTruthy();
    const createdNew = (JSON.parse(createNewBody) as any) ?? null;
    trackE2EProductId(createdNew?.data?.id);

    const oldPoCsv = ['SKU,Unit cost,Qty ordered,Qty shipped,Qty received', `${existingSku},1.23,1,,`].join('\n') + '\n';
    const oldPoImport = await request.post('/api/v1/purchase-orders/import', {
        multipart: {
            vendor: 'Plamod',
            file: {
                name: `po-old-${uniq}.csv`,
                mimeType: 'text/csv',
                buffer: Buffer.from(oldPoCsv, 'utf-8'),
            },
        },
    });
    expect(
        oldPoImport.ok(),
        `Old PO import failed: HTTP ${oldPoImport.status()} body=${await oldPoImport.text()}`,
    ).toBeTruthy();
    const oldPoImportBody = (await oldPoImport.json()) as any;
    const oldPoUuid = String(oldPoImportBody?.purchase_order_uuid ?? '');
    expect(oldPoUuid).not.toBe('');
    trackE2EPurchaseOrderId(oldPoUuid);

    const selectedPoCsv =
        ['SKU,Unit cost,Qty ordered,Qty shipped,Qty received', `${existingSku},1.25,2,,`, `${newSku},1.50,3,,`].join('\n') +
        '\n';
    const selectedPoImport = await request.post('/api/v1/purchase-orders/import', {
        multipart: {
            vendor: 'Plamod',
            file: {
                name: `po-selected-${uniq}.csv`,
                mimeType: 'text/csv',
                buffer: Buffer.from(selectedPoCsv, 'utf-8'),
            },
        },
    });
    expect(
        selectedPoImport.ok(),
        `Selected PO import failed: HTTP ${selectedPoImport.status()} body=${await selectedPoImport.text()}`,
    ).toBeTruthy();
    const selectedPoImportBody = (await selectedPoImport.json()) as any;
    const selectedPoUuid = String(selectedPoImportBody?.purchase_order_uuid ?? '');
    expect(selectedPoUuid).not.toBe('');
    trackE2EPurchaseOrderId(selectedPoUuid);

    await page.goto('/products');
    await page.waitForResponse(
        (r) => r.url().includes('/api/v1/products') && r.request().method() === 'GET',
        { timeout: 30_000 },
    );
    await page.waitForResponse(
        (r) => r.url().includes('/api/v1/purchase-orders') && r.request().method() === 'GET',
        { timeout: 30_000 },
    );

    await page.getByTestId('products-filter-po-button').click();
    const poPanel = page.getByTestId('products-filter-po-panel');
    await expect(poPanel).toBeVisible();
    await poPanel.getByTestId('products-filter-po-search').fill(selectedPoUuid.slice(0, 8));
    await expect(poPanel).toContainText(selectedPoUuid.slice(0, 8));
    await poPanel.getByText(selectedPoUuid.slice(0, 8), { exact: false }).first().click();
    await page.waitForResponse(
        (r) =>
            r.url().includes('/api/v1/products') &&
            r.request().method() === 'GET' &&
            r.url().includes('purchase_order_uuids') &&
            r.url().includes(encodeURIComponent(selectedPoUuid)),
        { timeout: 30_000 },
    );

    await expect(page.getByText(existingSku, { exact: true })).toBeVisible();
    await expect(page.getByText(newSku, { exact: true })).toBeVisible();

    const novelty = page.getByTestId('products-filter-po-novelty');
    await novelty.selectOption('new');
    await page.waitForResponse(
        (r) =>
            r.url().includes('/api/v1/products') &&
            r.request().method() === 'GET' &&
            r.url().includes('po_product_novelty=new'),
        { timeout: 30_000 },
    );
    await expect(page.getByText(newSku, { exact: true })).toBeVisible();
    await expect(page.getByText(existingSku, { exact: true })).toHaveCount(0);

    await novelty.selectOption('existing');
    await page.waitForResponse(
        (r) =>
            r.url().includes('/api/v1/products') &&
            r.request().method() === 'GET' &&
            r.url().includes('po_product_novelty=existing'),
        { timeout: 30_000 },
    );
    await expect(page.getByText(existingSku, { exact: true })).toBeVisible();
    await expect(page.getByText(newSku, { exact: true })).toHaveCount(0);

    await novelty.selectOption('all');
    await page.waitForResponse(
        (r) =>
            r.url().includes('/api/v1/products') &&
            r.request().method() === 'GET' &&
            !r.url().includes('po_product_novelty='),
        { timeout: 30_000 },
    );
    await expect(page.getByText(existingSku, { exact: true })).toBeVisible();
    await expect(page.getByText(newSku, { exact: true })).toBeVisible();
});
