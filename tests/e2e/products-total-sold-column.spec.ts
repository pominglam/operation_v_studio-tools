import { expect, test } from './fixtures';

test('products list shows and sorts by total ordered / total sold columns', async ({ page }) => {
    await page.goto('/products');

    await expect(page.getByRole('columnheader', { name: /^Total ordered$/i })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: /^Total sold$/i })).toBeVisible();

    const totalOrderedReq = page.waitForRequest((req) => {
        const url = req.url();
        return req.method() === 'GET' &&
            url.includes('/api/v1/products') &&
            url.includes('sort_by=total_ordered');
    });
    await page.getByTestId('products-sort-total-ordered').click();
    await totalOrderedReq;

    const totalSoldReq = page.waitForRequest((req) => {
        const url = req.url();
        return req.method() === 'GET' &&
            url.includes('/api/v1/products') &&
            url.includes('sort_by=total_sold');
    });
    await page.getByTestId('products-sort-total-sold').click();
    await totalSoldReq;
});
