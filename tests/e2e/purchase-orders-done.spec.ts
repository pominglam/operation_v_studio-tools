import { expect, test } from './fixtures';

test('purchase orders history supports done checkbox and shows id first', async ({
    page,
    request,
    trackE2EProductId,
    trackE2EPurchaseOrderId,
}) => {
    const sku = `E2E-PO-DONE-${Date.now()}`;
    const createProduct = await request.post('/api/v1/products', {
        data: {
            sku,
            description: 'E2E PO Done Product',
            vendor: 'Plamod',
        },
    });
    expect(createProduct.ok()).toBeTruthy();
    const createdProduct = (await createProduct.json()) as { data?: { id?: string } };
    trackE2EProductId(createdProduct?.data?.id);

    const csv = [
        'SKU,Unit cost,Qty ordered,Qty shipped,Qty received',
        `${sku},10.00,1,,`,
    ].join('\n') + '\n';

    const create = await request.post('/api/v1/purchase-orders/import', {
        multipart: {
            vendor: 'Plamod',
            file: {
                name: 'po-done.csv',
                mimeType: 'text/csv',
                buffer: Buffer.from(csv, 'utf8'),
            },
        },
    });
    expect(create.ok(), `PO import failed: HTTP ${create.status()}`).toBeTruthy();

    const created = (await create.json()) as { purchase_order_uuid?: string };
    const poId = created.purchase_order_uuid;
    expect(poId).toBeTruthy();
    trackE2EPurchaseOrderId(poId);

    await page.goto('/purchase-orders');

    const headers = page.locator('table thead th');
    await expect(headers.nth(0)).toHaveText(/^\s*ID\s*$/i);

    const row = page.locator('tbody tr').filter({ has: page.getByRole('link', { name: String(poId) }) }).first();
    await expect(row).toBeVisible();

    const done = row.getByRole('checkbox').first();
    await expect(done).not.toBeChecked();
    await done.check();
    await expect(done).toBeChecked();

    await page.reload();
    const rowReloaded = page.locator('tbody tr').filter({ has: page.getByRole('link', { name: String(poId) }) }).first();
    await expect(rowReloaded).toBeVisible();
    await expect(rowReloaded.getByRole('checkbox').first()).toBeChecked();
});
