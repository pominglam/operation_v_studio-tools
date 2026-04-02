import { expect, test } from './fixtures';

test('price research table hides shipped column', async ({ page }) => {
    await page.goto('/price-research');

    await expect(page.getByRole('columnheader', { name: 'AVAILABLE' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: /SHIPPED/i })).toHaveCount(0);
});
