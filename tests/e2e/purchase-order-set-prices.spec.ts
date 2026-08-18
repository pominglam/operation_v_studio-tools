import { expect, test } from './fixtures';

test('PO selling-price review uses PO cost and flags review multipliers', async ({
    page,
    request,
    trackE2EProductId,
    trackE2EPurchaseOrderId,
}) => {
    const uniq = String(Date.now());
    const sku = `E2E-PRICE-${uniq}`;

    const create = await request.post('/api/v1/products', {
        data: {
            sku,
            description: `E2E price product ${uniq}`,
            barcode: `E2E-PRICE-${uniq}-111`,
            vendor: 'Plamod',
        },
    });
    expect(create.ok()).toBeTruthy();
    const created = (await create.json()) as { data?: { id?: string } };
    trackE2EProductId(created.data?.id);

    const csv = ['SKU,Unit cost,Qty ordered,Qty shipped,Qty received', `${sku},1.23,1,,`].join(
        '\n',
    );
    const poImport = await request.post('/api/v1/purchase-orders/import', {
        multipart: {
            vendor: 'Plamod',
            shipping_total: '0.00',
            file: {
                name: `po-price-${uniq}.csv`,
                mimeType: 'text/csv',
                buffer: Buffer.from(`${csv}\n`, 'utf-8'),
            },
        },
    });
    expect(poImport.ok(), `PO import failed: HTTP ${poImport.status()}`).toBeTruthy();
    const imported = (await poImport.json()) as { purchase_order_uuid?: string };
    expect(imported.purchase_order_uuid).toBeTruthy();
    trackE2EPurchaseOrderId(imported.purchase_order_uuid);

    const establishCurrent = await request.post(
        `/api/v1/purchase-orders/${imported.purchase_order_uuid}/workflow-actions/set-prices`,
        {
            data: {
                overrides: [{ product_uuid: created.data?.id, price: '3.99' }],
            },
        },
    );
    expect(establishCurrent.ok()).toBeTruthy();

    await page.goto(`/purchase-orders/${imported.purchase_order_uuid}`);
    await page.getByRole('button', { name: 'Set/review', exact: true }).click();

    const priceDialog = page.getByRole('dialog');
    await expect(priceDialog.getByLabel(`Override price for ${sku}`)).toHaveValue('1.99');
    await expect(priceDialog.getByText('1.62x', { exact: true })).toHaveClass(/bg-rose-100/);
    await expect(priceDialog.getByText('1.62x', { exact: true })).toHaveClass(/ring-rose-300/);
    await expect(priceDialog.locator('thead')).toHaveCSS('position', 'sticky');

    await priceDialog.getByRole('button', { name: `Use suggested price for ${sku}` }).click();
    const applyResponse = page.waitForResponse(
        (response) =>
            response.url().includes('/workflow-actions/set-prices') &&
            response.request().method() === 'POST',
    );
    await priceDialog.getByRole('button', { name: 'Apply prices' }).click();
    expect((await applyResponse).ok()).toBeTruthy();

    const persistedPreview = await request.get(
        `/api/v1/purchase-orders/${imported.purchase_order_uuid}/workflow-actions/set-prices/preview`,
    );
    expect(persistedPreview.ok()).toBeTruthy();
    const persistedBody = (await persistedPreview.json()) as {
        data?: { unchanged?: Array<{ sku?: string; current_price?: string }> };
    };
    expect(persistedBody.data?.unchanged?.find((row) => row.sku === sku)?.current_price).toBe(
        '1.99',
    );

    await page.getByRole('button', { name: 'Set/review', exact: true }).click();
    const equalPriceDialog = page.getByRole('dialog');
    await expect(
        equalPriceDialog.getByRole('button', { name: `Use suggested price for ${sku}` }),
    ).toBeVisible();
    await equalPriceDialog.getByRole('button', { name: 'Cancel' }).click();
});
