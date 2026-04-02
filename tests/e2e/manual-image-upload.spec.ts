import { expect, test } from './fixtures';

test('uploads manual images in product drawer (@smoke)', async ({ page, request, trackE2EProductId }) => {
    const uniq = String(Date.now());
    const sku = `E2E-MANUAL-UPLOAD-${uniq}`;

    const create = await request.post('/api/v1/products', {
        data: { sku, description: `E2E Manual Upload ${uniq}`, vendor: 'Plamod' },
    });
    expect(create.ok()).toBeTruthy();
    const created = (await create.json()) as any;
    const productUuid = created?.data?.id as string | undefined;
    trackE2EProductId(productUuid);

    await page.goto('/products');
    await page.getByPlaceholder('Search SKU / barcode / name…').fill(sku);
    await page.getByPlaceholder('Search SKU / barcode / name…').press('Enter');

    await page.waitForResponse((r) => {
        if (!r.ok()) return false;
        if (r.request().method() !== 'GET') return false;
        const u = r.url().toLowerCase();
        return u.includes('/api/v1/products') && u.includes(`search=${encodeURIComponent(sku).toLowerCase()}`);
    });

    const rowSku = page.getByText(sku, { exact: true });
    await expect(rowSku).toBeVisible();

    // Open the PDP info drawer.
    await page.locator('tr', { has: rowSku }).getByTestId('product-info-open').click();
    await expect(page.getByText('Photos')).toBeVisible();

    // Upload a 1x1 PNG.
    const png = Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMB/6VnVJ8AAAAASUVORK5CYII=',
        'base64',
    );

    const fileInput = page.locator('input[type="file"][multiple][accept^="image/"]');
    await fileInput.setInputFiles([{ name: 'e2e.png', mimeType: 'image/png', buffer: png }]);

    await expect(page.getByText(/Uploaded\s+1\s+image/)).toBeVisible();

    // Drag-and-drop upload should work too.
    const dataTransfer = await page.evaluateHandle((b64) => {
        const dt = new DataTransfer();
        const bytes = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
        const file = new File([bytes], 'e2e-drag.png', { type: 'image/png' });
        dt.items.add(file);
        return dt;
    }, 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMB/6VnVJ8AAAAASUVORK5CYII=');
    await page.getByTestId('manual-image-dropzone').dispatchEvent('drop', { dataTransfer });

    await expect(page.getByText(/Uploaded\s+1\s+image/)).toBeVisible();

    // Manual upload source should be visible and active.
    await expect(page.getByText(/Manual upload\s*\(2\)/)).toBeVisible();
    await expect(page.getByText('Manual upload', { exact: false }).first()).toBeVisible();
});

