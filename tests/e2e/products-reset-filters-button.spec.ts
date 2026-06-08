import { expect, test } from './fixtures';

test('products reset filters button clears filter inputs', async ({ page }) => {
    await page.goto('/products');

    const availableMinFilter = page.getByTestId('products-filter-available-min');
    await availableMinFilter.fill('7');
    await expect(availableMinFilter).toHaveValue('7');

    await page.getByTestId('products-reset-filters-button').click();

    await expect(availableMinFilter).toHaveValue('');
});
