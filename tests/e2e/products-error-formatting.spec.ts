import { expect, test } from './fixtures';

test('products page shows safe error message for non-string API message', async ({ page }) => {
    await page.route('**/api/v1/products*', async (route) => {
        await route.fulfill({
            status: 500,
            contentType: 'application/json',
            body: JSON.stringify({
                message: { not: 'a string' },
            }),
        });
    });

    await page.goto('/products');

    // The page auto-loads; we should see an error banner that does NOT crash.
    const banner = page.getByTestId('products-error');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('Failed to load products');
    await expect(banner).toContainText('HTTP 500');

    // Must not surface the old internal formatting exception.
    await expect(banner).not.toContainText('trim is not a function');
});

