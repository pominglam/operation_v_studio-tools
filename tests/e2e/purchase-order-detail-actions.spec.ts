import { expect, test } from './fixtures';

test('purchase order detail shows PO actions and persisted workflow checklist', async ({
    page,
    request,
    trackE2EProductId,
    trackE2EPurchaseOrderId,
}) => {
    const uniq = String(Date.now());
    const sku = `E2E-PO-${uniq}`;
    const barcode = `E2E-${uniq}-111`;
    const handle = `e2e-handle-${uniq}`;

    // Seed a product so the PO line links to a real product UUID.
    const create = await request.post('/api/v1/products', {
        data: { sku, description: `E2E PO Product ${uniq}`, barcode, handle, vendor: 'Plamod' },
    });
    expect(create.ok()).toBeTruthy();
    const created = (await create.json()) as any;
    const productUuid = created?.data?.id as string | undefined;
    trackE2EProductId(productUuid);

    // Import a PO with a single line (standard format). Keep qty received blank so it can be deleted in cleanup.
    const csv = ['SKU,Unit cost,Qty ordered,Qty shipped,Qty received', `${sku},1.23,1,,`].join('\n') + '\n';
    const poImport = await request.post('/api/v1/purchase-orders/import', {
        multipart: {
            vendor: 'Plamod',
            file: {
                name: `po-${uniq}.csv`,
                mimeType: 'text/csv',
                buffer: Buffer.from(csv, 'utf-8'),
            },
        },
    });
    expect(poImport.ok(), `PO import failed: HTTP ${poImport.status()}`).toBeTruthy();
    const imported = (await poImport.json()) as any;
    const poUuid = imported?.purchase_order_uuid as string | undefined;
    expect(poUuid).toBeTruthy();
    trackE2EPurchaseOrderId(poUuid);

    await page.goto(`/purchase-orders/${poUuid}`);

    await expect(page.getByText('PO actions (products in this PO)')).toBeVisible();
    await expect(page.getByText('Workflow checklist')).toBeVisible();
    await expect(page.getByText(/Totals check:\s*OK/i)).toBeVisible();
    await expect(page.getByText(/Landed Σ Δ/)).toBeVisible();

    // Items table shows barcode + handle under product name.
    const skuCell = page.getByText(sku, { exact: true });
    await skuCell.scrollIntoViewIfNeeded();
    const row = page.locator('tr', { has: skuCell });
    await expect(row.getByText('Barcode:')).toBeVisible();
    await expect(row.getByText(barcode)).toBeVisible();
    await expect(row.getByText('Handle:')).toBeVisible();
    await expect(row.getByText(handle)).toBeVisible();

    // Recrawl dialog opens with correct count.
    await page.getByRole('button', { name: 'Recrawl' }).click();
    await expect(page.getByText('Recrawl selected products')).toBeVisible();
    await expect(page.getByText(/Queue recrawl for\s*1\s*selected product/i)).toBeVisible();
    await page.getByRole('button', { name: 'Close' }).click();

    // Export dialog opens with correct count.
    await page.getByRole('button', { name: 'Export products to Shopify (get handles)' }).click();
    await expect(page.getByText('Export selected products')).toBeVisible();
    await expect(page.getByText(/Export\s*1\s*selected product/i)).toBeVisible();
    await page.getByRole('button', { name: 'Close' }).click();

    // Import cards toggle open.
    await page.getByRole('button', { name: 'Import product handles' }).click();
    await expect(page.getByText('Import handles (Shopify)')).toBeVisible();

    await page.getByRole('button', { name: 'Import product quantity' }).click();
    await expect(page.getByText('Import inventory quantity override (barcode scan)')).toBeVisible();

    // New PO action button should be available.
    await expect(page.getByRole('button', { name: 'Add qty received to available' })).toBeVisible();
    await expect(page.getByLabel('Ensure all products have barcode')).toBeVisible();

    // Checklist persists.
    const importPoCheckbox = page.getByLabel('Import PO');
    await importPoCheckbox.check();
    await expect(page.getByText('Saving…')).toBeVisible();
    await expect(page.getByText('Saving…')).toHaveCount(0);

    await page.reload();
    await expect(page.getByLabel('Import PO')).toBeChecked();
});

