import { expect, test } from './fixtures';

test('purchase orders history supports status filter and shows id first', async ({
    page,
    request,
    trackE2EProductId,
    trackE2EPurchaseOrderId,
}) => {
    const uniq = Date.now();
    const sku = `E2E-PO-STATUS-${uniq}`;
    const createProduct = await request.post('/api/v1/products', {
        data: {
            sku,
            description: 'E2E PO Status Product',
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
                name: 'po-status.csv',
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

    const orderedPoRes = await request.post('/api/v1/purchase-orders/import', {
        multipart: {
            vendor: 'Plamod',
            ordered_date: '2026-02-01',
            file: {
                name: 'po-status-ordered.csv',
                mimeType: 'text/csv',
                buffer: Buffer.from(csv, 'utf8'),
            },
        },
    });
    expect(orderedPoRes.ok(), `PO import failed: HTTP ${orderedPoRes.status()}`).toBeTruthy();
    const orderedPo = (await orderedPoRes.json()) as { purchase_order_uuid?: string };
    const orderedPoId = orderedPo.purchase_order_uuid;
    expect(orderedPoId).toBeTruthy();
    trackE2EPurchaseOrderId(orderedPoId);

    await page.goto('/purchase-orders');
    await page.getByRole('button', { name: 'Reset filters' }).click();

    const headers = page.locator('table thead th');
    await expect(headers.nth(0)).toHaveText(/^\s*ID\s*$/i);

    const row = page.locator('tbody tr').filter({ has: page.getByRole('link', { name: String(poId) }) }).first();
    await expect(row).toBeVisible();
    await expect(row.getByText('Draft')).toBeVisible();
    await expect(row.getByRole('checkbox')).toHaveCount(0);

    await page.getByTestId('po-history-status-filter-button').click();
    await page
        .getByTestId('po-history-status-filter-panel')
        .locator('label')
        .filter({ hasText: 'Ordered' })
        .first()
        .click();
    await page.keyboard.press('Escape');
    await expect(page.locator('tbody tr').filter({ has: page.getByRole('link', { name: String(poId) }) })).toHaveCount(0);
    await expect(
        page.locator('tbody tr').filter({ has: page.getByRole('link', { name: String(orderedPoId) }) }),
    ).toHaveCount(1);

    // Filter selection persists across reload.
    await page.reload();
    await expect(
        page.locator('tbody tr').filter({ has: page.getByRole('link', { name: String(orderedPoId) }) }),
    ).toHaveCount(1);
    await expect(page.locator('tbody tr').filter({ has: page.getByRole('link', { name: String(poId) }) })).toHaveCount(0);

    // Reset brings back default (no filters).
    await page.getByRole('button', { name: 'Reset filters' }).click();
    await expect(page.locator('tbody tr').filter({ has: page.getByRole('link', { name: String(poId) }) })).toHaveCount(1);
    await expect(
        page.locator('tbody tr').filter({ has: page.getByRole('link', { name: String(orderedPoId) }) }),
    ).toHaveCount(1);
});
