import { expect, test } from './fixtures';

test('employee inventory count page scans and edits quantities', async ({
    page,
    request,
    trackE2EProductId,
}) => {
    const uniq = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const sku = `E2E-EMP-COUNT-${uniq}`;
    const barcode = `98765${Date.now()}`.slice(0, 13);

    const create = await request.post('/api/v1/products', {
        data: {
            sku,
            barcode,
            description: `Employee count ${uniq}`,
            vendor: 'Plamod',
            available: 2,
        },
    });
    const createBody = await create.text();
    expect(create.ok(), `Create failed: HTTP ${create.status()} body=${createBody}`).toBeTruthy();
    const id = (((JSON.parse(createBody) as any) ?? null)?.data?.id as string | undefined) ?? '';
    trackE2EProductId(id);

    await page.goto('/employee/inventory-count');
    await expect(page.getByTestId('employee-scan-input')).toBeVisible();

    await page.getByTestId('employee-scan-input').fill(barcode);
    await page.getByTestId('employee-scan-submit').click();

    await expect(page.locator('tr', { hasText: sku }).first()).toBeVisible();

    const line = page.locator('tr', { hasText: sku }).first();
    await expect(line).toBeVisible();
    await expect(line.locator('input[type="number"]')).toHaveValue('1');

    await line.getByRole('button', { name: '+' }).click();
    await expect(line.locator('input[type="number"]')).toHaveValue('2');

    await line.locator('input[type="number"]').fill('7');
    await line.locator('input[type="number"]').dispatchEvent('change');
    await expect(line.locator('input[type="number"]')).toHaveValue('7');

    await line.getByRole('button', { name: 'Remove' }).click();
    await expect(page.locator('tr', { hasText: sku })).toHaveCount(0);
});

