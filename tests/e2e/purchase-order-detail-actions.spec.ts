import { expect, test } from './fixtures';

test('shows the total ordered quantity in the PO header', async ({
    page,
    request,
    trackE2EProductId,
    trackE2EPurchaseOrderId,
}) => {
    const uniq = String(Date.now());
    const sku = `E2E-PO-QTY-${uniq}`;

    const create = await request.post('/api/v1/products', {
        data: { sku, description: `E2E PO Quantity ${uniq}`, vendor: 'Plamod' },
    });
    expect(create.ok()).toBeTruthy();
    const created = (await create.json()) as any;
    trackE2EProductId(created?.data?.id as string | undefined);

    const csv =
        ['SKU,Unit cost,Qty ordered,Qty shipped,Qty received', `${sku},1.23,7,,`].join('\n') + '\n';
    const poImport = await request.post('/api/v1/purchase-orders/import', {
        multipart: {
            vendor: 'Plamod',
            file: {
                name: `po-quantity-${uniq}.csv`,
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

    await expect(page.getByTestId('po-total-quantity')).toHaveText(/Total quantity:\s*7/);
});

test('saves shipment tracking numbers and links each from PO detail and history', async ({
    page,
    request,
    trackE2EProductId,
    trackE2EPurchaseOrderId,
}) => {
    const uniq = String(Date.now());
    const sku = `E2E-PO-TRACK-${uniq}`;
    const trackingNumbers = ['1Z999AA10123456784', 'RR123456789CN'];

    const create = await request.post('/api/v1/products', {
        data: { sku, description: `E2E PO Tracking ${uniq}`, vendor: 'Plamod' },
    });
    expect(create.ok()).toBeTruthy();
    const created = (await create.json()) as any;
    trackE2EProductId(created?.data?.id as string | undefined);

    const csv =
        ['SKU,Unit cost,Qty ordered,Qty shipped,Qty received', `${sku},1.23,1,,`].join('\n') + '\n';
    const poImport = await request.post('/api/v1/purchase-orders/import', {
        multipart: {
            vendor: 'Plamod',
            file: {
                name: `po-tracking-${uniq}.csv`,
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

    let resolutionCalls = 0;
    await page.route('**/api/v1/shipment-tracking/resolutions', async (route) => {
        resolutionCalls += 1;
        const requestBody = route.request().postDataJSON() as { tracking_numbers?: string[] };
        const status = resolutionCalls === 1 ? 'queued' : 'resolved';
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                data: (requestBody.tracking_numbers ?? []).map((trackingNumber) => ({
                    tracking_number: trackingNumber,
                    status,
                    provider: status === 'resolved' ? '17track' : null,
                    tracking_url:
                        status === 'resolved'
                            ? `https://t.17track.net/en#nums=${trackingNumber}`
                            : null,
                    retry_after: null,
                })),
            }),
        });
    });

    await page.goto(`/purchase-orders/${poUuid}`);
    await page.getByRole('button', { name: 'Edit', exact: true }).click();
    await page.getByTestId('po-tracking-input').fill(trackingNumbers.join('\n'));
    await page.getByRole('button', { name: 'Save', exact: true }).click();

    for (const [index, trackingNumber] of trackingNumbers.entries()) {
        const detailTracking = page.getByTestId(`po-tracking-link-${index}`);
        await expect(detailTracking).toContainText(trackingNumber);
        await expect(detailTracking.getByTestId('tracking-resolution-spinner')).toBeVisible();
    }
    for (const [index, trackingNumber] of trackingNumbers.entries()) {
        const detailTracking = page.getByTestId(`po-tracking-link-${index}`);
        await expect(detailTracking.locator('a')).toHaveAttribute(
            'href',
            `https://t.17track.net/en#nums=${trackingNumber}`,
            { timeout: 5_000 },
        );
    }

    await page.goto('/purchase-orders');
    for (const [index, trackingNumber] of trackingNumbers.entries()) {
        const historyTracking = page.getByTestId(`po-history-tracking-${poUuid}-${index}`);
        await expect(historyTracking).toContainText(trackingNumber);
        await expect(historyTracking.getByTestId('tracking-resolution-spinner')).toBeVisible();
    }
    for (const [index, trackingNumber] of trackingNumbers.entries()) {
        const historyTracking = page.getByTestId(`po-history-tracking-${poUuid}-${index}`);
        await expect(historyTracking.locator('a')).toHaveAttribute(
            'href',
            `https://t.17track.net/en#nums=${trackingNumber}`,
            { timeout: 5_000 },
        );
    }
});

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
    const csv =
        ['SKU,Unit cost,Qty ordered,Qty shipped,Qty received', `${sku},1.23,1,,`].join('\n') + '\n';
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
    await expect(page.getByTestId('po-total-quantity')).toHaveText(/Total quantity:\s*1/);

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

test('opens the Products grid in a new tab filtered to the current PO', async ({
    page,
    request,
    trackE2EProductId,
    trackE2EPurchaseOrderId,
}) => {
    const uniq = String(Date.now());
    const sku = `E2E-PO-GRID-${uniq}`;

    const create = await request.post('/api/v1/products', {
        data: { sku, description: `E2E PO Grid Product ${uniq}`, vendor: 'Plamod' },
    });
    expect(create.ok()).toBeTruthy();
    const created = (await create.json()) as any;
    trackE2EProductId(created?.data?.id as string | undefined);

    const csv =
        ['SKU,Unit cost,Qty ordered,Qty shipped,Qty received', `${sku},1.23,1,,`].join('\n') + '\n';
    const poImport = await request.post('/api/v1/purchase-orders/import', {
        multipart: {
            vendor: 'Plamod',
            file: {
                name: `po-grid-${uniq}.csv`,
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

    const productsGridLink = page.getByRole('link', { name: 'View products in grid' });
    await expect(productsGridLink).toHaveAttribute(
        'href',
        `/products?purchase_order_uuid=${poUuid}`,
    );
    await expect(productsGridLink).toHaveAttribute('target', '_blank');

    const productsPagePromise = page.waitForEvent('popup');
    await productsGridLink.click();
    const productsPage = await productsPagePromise;
    await productsPage.waitForLoadState('domcontentloaded');
    await expect(productsPage).toHaveURL(new RegExp(`/products\\?purchase_order_uuid=${poUuid}$`));
    await expect(productsPage.getByText(sku, { exact: true })).toBeVisible();
    await productsPage.close();
});
