import { expect, test } from './fixtures';

test('bulk update can archive and unarchive selected products', async ({
    page,
    request,
    trackE2EProductId,
}) => {
    const uniq = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const sku = `E2E-BULK-ARCHIVE-${uniq}`;

    const create = await request.post('/api/v1/products', {
        data: {
            sku,
            description: `E2E bulk archive ${uniq}`,
            vendor: 'Plamod',
            main_type: 'model kit',
        },
    });
    const createBody = await create.text();
    expect(create.ok(), `Create failed: HTTP ${create.status()} body=${createBody}`).toBeTruthy();
    const created = (JSON.parse(createBody) as any) ?? null;
    const id = created?.data?.id as string | undefined;
    trackE2EProductId(id);

    await page.goto('/products');
    await page.waitForResponse(
        (r) => r.url().includes('/api/v1/products') && r.request().method() === 'GET',
        { timeout: 30_000 },
    );
    const includeArchivedToggle = page.getByLabel('Include archived');
    if (await includeArchivedToggle.isChecked()) {
        await includeArchivedToggle.uncheck();
    }

    await page.getByPlaceholder('Search SKU / barcode / name…').fill(sku);
    await page.waitForResponse(
        (r) => r.url().includes('/api/v1/products') && r.request().method() === 'GET',
        { timeout: 30_000 },
    );

    const skuCell = page.getByText(sku, { exact: true });
    await expect(skuCell).toBeVisible();
    const row = page.locator('tr', { has: skuCell });
    await row.locator('input[type="checkbox"]').first().check();

    await page.locator('#app').getByRole('button', { name: 'Update selected' }).click();
    await expect(page.getByText('Bulk update products')).toBeVisible();
    await expect(
        page.locator('#bulk-update-main-type-options option[value="__empty__"]'),
    ).toHaveCount(0);

    await page.getByTestId('bulk-archive-status-apply').check();
    await page.getByTestId('bulk-archive-status-select').selectOption('archive');
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Update selected' })
        .click({ force: true });
    await expect(page.getByText('Updated')).toBeVisible();

    const archivedRes = await request.get(
        `/api/v1/products?per_page=10&search=${encodeURIComponent(sku)}&include_archived=1`,
    );
    expect(
        archivedRes.ok(),
        `Archived verification failed: HTTP ${archivedRes.status()} body=${await archivedRes.text()}`,
    ).toBeTruthy();
    const archivedJson = (await archivedRes.json()) as any;
    const archivedProduct = (archivedJson?.data ?? []).find((x: any) => x?.sku === sku) ?? null;
    expect(archivedProduct).toBeTruthy();
    expect(archivedProduct?.is_archived).toBe(true);

    await includeArchivedToggle.check();
    await expect(page.getByText(sku, { exact: true })).toBeVisible();

    const rowArchived = page.locator('tr', { has: page.getByText(sku, { exact: true }) });
    await rowArchived.locator('input[type="checkbox"]').first().check();
    await page.locator('#app').getByRole('button', { name: 'Update selected' }).click();
    await expect(page.getByText('Bulk update products')).toBeVisible();

    await page.getByTestId('bulk-archive-status-apply').check();
    await page.getByTestId('bulk-archive-status-select').selectOption('unarchive');
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Update selected' })
        .click({ force: true });
    await expect(page.getByText('Updated')).toBeVisible();

    await includeArchivedToggle.uncheck();
    await expect(page.getByText(sku, { exact: true })).toBeVisible();

    const unarchivedRes = await request.get(
        `/api/v1/products?per_page=10&search=${encodeURIComponent(sku)}&include_archived=1`,
    );
    expect(
        unarchivedRes.ok(),
        `Unarchived verification failed: HTTP ${unarchivedRes.status()} body=${await unarchivedRes.text()}`,
    ).toBeTruthy();
    const unarchivedJson = (await unarchivedRes.json()) as any;
    const unarchivedProduct = (unarchivedJson?.data ?? []).find((x: any) => x?.sku === sku) ?? null;
    expect(unarchivedProduct).toBeTruthy();
    expect(unarchivedProduct?.is_archived).toBe(false);
});

