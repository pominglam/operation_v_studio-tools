import { expect, test } from './fixtures';

test('products list supports reorder > 1 filter and not-arrived/reorder sorting', async ({ page }) => {
    await page.goto('/products');

    const reorderGtOne = page.getByTestId('products-filter-reorder-gt-one');
    await expect(reorderGtOne).toBeVisible();

    const reorderFilterReq = page.waitForRequest((req) => {
        const url = req.url();
        return req.method() === 'GET'
            && url.includes('/api/v1/products')
            && url.includes('reorder_gt_one=1');
    });
    await reorderGtOne.check();
    await reorderFilterReq;

    const reorderSortReq = page.waitForRequest((req) => {
        const url = req.url();
        return req.method() === 'GET'
            && url.includes('/api/v1/products')
            && url.includes('sort_by=reorder');
    });
    await page.getByTestId('products-sort-reorder').click();
    await reorderSortReq;

    const notArrivedSortReq = page.waitForRequest((req) => {
        const url = req.url();
        return req.method() === 'GET'
            && url.includes('/api/v1/products')
            && url.includes('sort_by=not_arrived');
    });
    await page.getByTestId('products-sort-not-arrived').click();
    await notArrivedSortReq;
});
