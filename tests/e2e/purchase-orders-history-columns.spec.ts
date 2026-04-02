import { expect, test } from './fixtures';

test('purchase orders history shows ordered column next to created', async ({ page }) => {
    await page.goto('/purchase-orders');

    await expect(page.getByRole('columnheader', { name: /^Created/i })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: /^Ordered$/i })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: /^Estimated arrival$/i })).toBeVisible();
});
