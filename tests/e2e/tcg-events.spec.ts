import { expect, test } from './fixtures';

test('tcg events: page loads, filters work, refresh completes without timeout', async ({ page }) => {
    test.setTimeout(180_000);

    await page.goto('/tcg-events', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: 'TCG Events' })).toBeVisible();

    // Wait for initial list call.
    const initialList = await page.waitForResponse(
        (r) => r.url().includes('/api/v1/tcg/events') && r.request().method() === 'GET',
        { timeout: 30_000 },
    );
    expect(initialList.ok(), `Initial list request failed: HTTP ${initialList.status()}`).toBeTruthy();

    // Toggle "Hide 0 applicants" off triggers fetch with hide_zero_applicants=0.
    const listAfterToggle = page.waitForResponse((r) => {
        if (!r.ok()) return false;
        if (r.request().method() !== 'GET') return false;
        const u = r.url();
        return u.includes('/api/v1/tcg/events') && u.includes('hide_zero_applicants=0');
    });
    await page.getByLabel('Hide 0 applicants').click();
    await listAfterToggle;

    // Change status filter triggers fetch.
    const statusSelect = page.getByLabel('Status');
    const listAfterStatus = page.waitForResponse((r) => {
        if (!r.ok()) return false;
        if (r.request().method() !== 'GET') return false;
        const u = r.url();
        return u.includes('/api/v1/tcg/events') && u.includes('status=accepting');
    });
    await statusSelect.selectOption('accepting');
    await listAfterStatus;

    // Search triggers fetch.
    await page.getByLabel('Search').fill('montreal');
    const listAfterSearch = page.waitForResponse((r) => {
        if (!r.ok()) return false;
        if (r.request().method() !== 'GET') return false;
        const u = r.url();
        return u.includes('/api/v1/tcg/events') && u.toLowerCase().includes('search=montreal');
    });
    await page.getByRole('button', { name: 'Search' }).click();
    await listAfterSearch;

    // Refresh should not hit the 15s Axios timeout banner anymore.
    const refreshResponse = page.waitForResponse((r) => r.url().includes('/api/v1/tcg/events/refresh') && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Refresh' }).click();
    const rr = await refreshResponse;
    expect(rr.ok()).toBeTruthy();

    await expect(page.getByText('timeout of 15000ms exceeded')).toHaveCount(0);
});

