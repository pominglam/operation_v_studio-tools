import { expect, test } from './fixtures';

test('price research table hides shipped column', async ({ page }) => {
    await page.goto('/price-research');

    await expect(page.getByRole('columnheader', { name: 'AVAILABLE' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: /SHIPPED/i })).toHaveCount(0);
});

test('pricing row opens PO lines beside the selling price', async ({
    page,
    request,
    trackE2EProductId,
    trackE2EPurchaseOrderId,
}) => {
    const uniq = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const sku = `E2E-PRICE-PO-${uniq}`;

    const create = await request.post('/api/v1/products', {
        data: {
            sku,
            description: `E2E pricing PO lines ${uniq}`,
            vendor: 'Plamod',
            type: 'HG',
        },
    });
    expect(create.ok()).toBeTruthy();
    const created = (await create.json()) as { data?: { id?: string } };
    trackE2EProductId(created.data?.id);

    const csv =
        ['SKU,Unit cost,Qty ordered,Qty shipped,Qty received', `${sku},12.34,2,,`].join('\n') +
        '\n';
    const imported = await request.post('/api/v1/purchase-orders/import', {
        multipart: {
            vendor: 'Plamod',
            shipping_total: '2.00',
            file: {
                name: `pricing-po-lines-${uniq}.csv`,
                mimeType: 'text/csv',
                buffer: Buffer.from(csv, 'utf-8'),
            },
        },
    });
    expect(imported.ok()).toBeTruthy();
    const importedBody = (await imported.json()) as { purchase_order_uuid?: string };
    trackE2EPurchaseOrderId(importedBody.purchase_order_uuid);

    await page.goto('/price-research');
    const productsResponse = page.waitForResponse(
        (response) =>
            response.url().includes('/api/v1/price-research/products') &&
            response.request().method() === 'GET',
    );
    await page.getByPlaceholder('Search SKU / barcode / description…').fill(sku);
    await productsResponse;

    const row = page.locator('tbody tr', { has: page.getByText(sku, { exact: true }) });
    await row.getByRole('button', { name: `PO lines for ${sku}` }).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText(`${sku} — PO Lines`);
    await expect(dialog.getByText('Landed', { exact: true })).toBeVisible();
});
