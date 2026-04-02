import { expect, test } from './fixtures';

test('purchase orders vendor input includes JS option', async ({ page }) => {
    await page.goto('/purchase-orders');
    await expect(page.getByRole('heading', { name: 'Purchase Orders' })).toBeVisible();

    const option = page.locator('datalist#vendor-options option[value="JS"]');
    await expect(option).toHaveCount(1);
});

