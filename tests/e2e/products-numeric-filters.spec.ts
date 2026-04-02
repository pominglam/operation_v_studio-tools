import { expect, test } from './fixtures';

test('products list shows numeric filters for available, not-arrived, and reorder', async ({
    page,
}) => {
    await page.goto('/products');

    const availableInput = page.getByTestId('products-filter-available');
    const notArrivedInput = page.getByTestId('products-filter-not-arrived');
    const reorderInput = page.getByTestId('products-filter-reorder');

    await expect(availableInput).toBeVisible();
    await expect(notArrivedInput).toBeVisible();
    await expect(reorderInput).toBeVisible();

    await availableInput.fill('0');
    await notArrivedInput.fill('5');
    await reorderInput.fill('3');

    await expect(availableInput).toHaveValue('0');
    await expect(notArrivedInput).toHaveValue('5');
    await expect(reorderInput).toHaveValue('3');
});
