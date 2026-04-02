import { expect, test } from './fixtures';

test('products reset filters button clears filter inputs', async ({ page }) => {
    await page.goto('/products');

    const availableFilter = page.getByTestId('products-filter-available');
    await availableFilter.fill('7');
    await expect(availableFilter).toHaveValue('7');

    await page.getByTestId('products-reset-filters-button').click();

    await expect(availableFilter).toHaveValue('');
});
