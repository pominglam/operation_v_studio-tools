import { expect, test } from './fixtures';

test('previews one CAD payment across separate air and sea POs', async ({
    page,
    request,
    trackE2EProductId,
    trackE2EPurchaseOrderId,
}) => {
    const unique = String(Date.now());
    const supplierOrderId = `E2E-COMBINED-${unique}`;

    const createPo = async (
        suffix: string,
        shipment: 'air' | 'sea',
        productHkd: string,
        freightHkd: string,
    ): Promise<string> => {
        const sku = `E2E-COMBINED-${suffix}-${unique}`;
        const product = await request.post('/api/v1/products', {
            data: {
                sku,
                description: `Combined payment ${shipment} ${unique}`,
                vendor: 'Stedi',
            },
        });
        expect(product.ok()).toBeTruthy();
        const productBody = (await product.json()) as { data?: { id?: string } };
        trackE2EProductId(productBody.data?.id);

        const invoice = [
            'Customer,Item,SKU,Qty,unit price,Amount',
            `PM,Combined payment ${shipment},${sku},1,${productHkd},${productHkd}`,
            `PM,${shipment} shipping service,,1,${freightHkd},${freightHkd}`,
            '',
        ].join('\n');
        const imported = await request.post('/api/v1/purchase-orders/import', {
            multipart: {
                vendor: 'Stedi',
                supplier_order_id: supplierOrderId,
                shipment_method: shipment,
                file: {
                    name: `combined-${shipment}-${unique}.csv`,
                    mimeType: 'text/csv',
                    buffer: Buffer.from(invoice, 'utf-8'),
                },
            },
        });
        expect(imported.ok(), await imported.text()).toBeTruthy();
        const importedBody = (await imported.json()) as { purchase_order_uuid?: string };
        expect(importedBody.purchase_order_uuid).toBeTruthy();
        trackE2EPurchaseOrderId(importedBody.purchase_order_uuid);

        return importedBody.purchase_order_uuid!;
    };

    const airId = await createPo('AIR', 'air', '1000.00', '100.00');
    const seaId = await createPo('SEA', 'sea', '500.00', '50.00');

    await page.goto('/purchase-orders');
    const airRow = page.locator('tr', { has: page.getByRole('link', { name: airId }) });
    const seaRow = page.locator('tr', { has: page.getByRole('link', { name: seaId }) });
    await airRow.getByRole('checkbox').check();
    await seaRow.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Record combined payment' }).click();

    await page.getByLabel('Product + shipping split').check();
    await page.getByLabel('Combined product cost (CAD)').fill('240.00');
    await page.getByLabel('Combined shipping cost (CAD)').fill('60.00');
    await expect(page.getByText('Calculated total: 300.00 CAD')).toBeVisible();
    await page.getByRole('button', { name: 'Preview allocation' }).click();

    const dialog = page.getByRole('dialog', { name: 'Record combined payment' });
    await expect(page.getByText('1650.00 HKD')).toBeVisible();
    await expect(dialog.getByText('Paid', { exact: true }).locator('..')).toContainText(
        '300.00 CAD',
    );
    await expect(page.getByText('0.181818')).toBeVisible();
    const airAllocation = dialog.locator('tbody tr').filter({ hasText: 'Air' });
    const seaAllocation = dialog.locator('tbody tr').filter({ hasText: 'Sea' });
    await expect(airAllocation).toContainText('160.00');
    await expect(airAllocation).toContainText('40.00');
    await expect(seaAllocation).toContainText('80.00');
    await expect(seaAllocation).toContainText('20.00');

    await page.getByLabel(/Enter exact CAD amounts manually/).check();
    await expect(dialog.getByText('Remaining: 0.00 CAD', { exact: true })).toBeVisible();
    await expect(dialog.getByText('Product remaining: 0.00 CAD', { exact: true })).toBeVisible();
    await expect(dialog.getByText('Shipping remaining: 0.00 CAD', { exact: true })).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Record payment' })).toBeEnabled();

    await seaAllocation.getByLabel('Shipping total CAD').fill('19.99');
    await expect(dialog.getByText('Remaining: 0.01 CAD', { exact: true })).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Record payment' })).toBeDisabled();

    await page.getByRole('button', { name: 'Cancel' }).click();
});
