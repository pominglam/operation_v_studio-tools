import { expect, test } from './fixtures';

test('purchase order detail can import more products into current PO', async ({
    page,
    request,
    trackE2EProductId,
    trackE2EPurchaseOrderId,
}) => {
    const uniq = String(Date.now());
    const sku1 = `E2E-PO-MORE-A-${uniq}`;
    const sku2 = `E2E-PO-MORE-B-${uniq}`;

    const createProduct = async (sku: string): Promise<string> => {
        const res = await request.post('/api/v1/products', {
            data: { sku, description: `E2E ${sku}`, vendor: 'Plamod' },
        });
        expect(res.ok(), `Create product ${sku} failed: HTTP ${res.status()}`).toBeTruthy();
        const body = (await res.json()) as any;
        const productUuid = body?.data?.id as string | undefined;
        expect(productUuid).toBeTruthy();
        trackE2EProductId(productUuid);
        return productUuid;
    };

    await createProduct(sku1);
    await createProduct(sku2);

    const firstCsv = ['SKU,Unit cost,Qty ordered,Qty shipped,Qty received', `${sku1},11.00,1,1,`].join('\n') + '\n';
    const firstImport = await request.post('/api/v1/purchase-orders/import', {
        multipart: {
            vendor: 'Plamod',
            shipping_total: '6.00',
            file: {
                name: `po-more-first-${uniq}.csv`,
                mimeType: 'text/csv',
                buffer: Buffer.from(firstCsv, 'utf-8'),
            },
        },
    });
    expect(firstImport.ok(), `Initial PO import failed: HTTP ${firstImport.status()}`).toBeTruthy();
    const imported = (await firstImport.json()) as any;
    const poUuid = imported?.purchase_order_uuid as string | undefined;
    expect(poUuid).toBeTruthy();
    trackE2EPurchaseOrderId(poUuid);

    await page.goto(`/purchase-orders/${poUuid}`);
    await expect(page.getByText('Purchase Order Detail')).toBeVisible();
    await expect(page.getByText(sku1, { exact: true })).toBeVisible();

    const importMoreCsv = ['SKU,Unit cost,Qty ordered,Qty shipped,Qty received', `${sku2},22.00,2,2,`].join('\n') + '\n';
    await page.setInputFiles('input[type="file"][accept=".csv,text/csv"]', {
        name: `po-more-append-${uniq}.csv`,
        mimeType: 'text/csv',
        buffer: Buffer.from(importMoreCsv, 'utf-8'),
    });

    page.once('dialog', (dialog) => dialog.accept());
    const importResponsePromise = page.waitForResponse((res) => {
        return res.url().includes('/api/v1/purchase-orders/import') && res.request().method() === 'POST';
    });
    await page.getByRole('button', { name: 'Import more' }).click();
    const importResponse = await importResponsePromise;
    expect(importResponse.status()).toBe(200);

    await expect(page.getByText(sku1, { exact: true })).toBeVisible();
    await expect(page.getByText(sku2, { exact: true })).toBeVisible();
    await expect(page.getByText(/^Items:\s*2$/)).toBeVisible();
    await expect(page.locator('tfoot td').nth(8)).toHaveText('3');
    await expect(page.locator('tfoot td').nth(10)).toHaveText('0');
});

