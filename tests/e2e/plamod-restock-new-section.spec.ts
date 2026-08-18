import { expect, test } from '@playwright/test';

const TEST_SKU = 'E2E-NEW-FILTER';
const PAGE_STATE_KEY = 'plamod_restock_page_state';

function parseNewRowCount(text: string | null): number {
    if (!text) return 0;
    const filtered = text.match(/·\s*(\d+)\s+of\s+\d+\s+rows/);
    if (filtered) return Number(filtered[1]);
    const plain = text.match(/·\s*(\d+)\s+rows/);
    return Number(plain?.[1] ?? 0);
}

async function waitForNewRowsLoaded(page: import('@playwright/test').Page): Promise<number> {
    await expect(newSectionHeading(page)).toContainText(/·\s*[1-9]\d*\s+(?:of\s+\d+\s+)?rows/, {
        timeout: 30_000,
    });
    return parseNewRowCount(await newSectionHeading(page).textContent());
}

async function gotoRestock(page: import('@playwright/test').Page): Promise<void> {
    await page.addInitScript((key) => {
        localStorage.removeItem(key);
    }, PAGE_STATE_KEY);

    await page.goto('/restocking/plamod');
    await expect(page.getByRole('heading', { name: 'PLAMOD restock' })).toBeVisible();
    await expect(page.getByTestId('restock-tab-existing')).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByTestId('restock-cost-total')).toBeVisible();
    await expect(page.getByTestId('restock-cost-existing')).toBeVisible();
    await expect(page.getByTestId('restock-cost-new')).toBeVisible();
    await page.getByTestId('restock-tab-new').click();
    await expect(page.getByTestId('restock-tab-new')).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByTestId('restock-new-table')).toBeVisible();
    await waitForNewRowsLoaded(page);
}

function newSectionHeading(page: import('@playwright/test').Page) {
    return page.getByRole('heading', { name: /New on PLAMOD \(not in catalog\)/ });
}

function dataRows(page: import('@playwright/test').Page) {
    return page
        .getByTestId('restock-new-table')
        .locator('tbody tr')
        .filter({
            hasNot: page.locator('td[colspan]'),
        });
}

function existingRows(page: import('@playwright/test').Page) {
    return page
        .getByTestId('restock-existing-table')
        .locator('tbody tr')
        .filter({
            hasNot: page.locator('td[colspan]'),
        });
}

async function purgeRestockTestSku(): Promise<void> {
    const { execSync } = await import('node:child_process');
    execSync(
        'docker exec pricing-tool-php php artisan tinker --execute="' +
            "App\\Models\\PlamodInstockItem::where('sku','E2E-NEW-FILTER')->delete(); " +
            "App\\Models\\PlamodRestockSkuDecision::where('sku','E2E-NEW-FILTER')->delete(); " +
            "App\\Models\\PlamodRestockPlannedMaintain::where('sku','E2E-NEW-FILTER')->delete(); " +
            "App\\Models\\PlamodRestockReorderOverride::where('sku','E2E-NEW-FILTER')->delete();\"",
        { stdio: 'pipe' },
    );
}

async function resetTestSkuDecision(): Promise<void> {
    const { execSync } = await import('node:child_process');
    execSync(
        "docker exec pricing-tool-php php artisan tinker --execute=\"App\\Models\\PlamodRestockSkuDecision::where('sku','E2E-NEW-FILTER')->delete(); App\\Models\\PlamodRestockPlannedMaintain::where('sku','E2E-NEW-FILTER')->delete();\"",
        { stdio: 'pipe' },
    );
}

test.describe('PLAMOD restock — new products section', () => {
    test.beforeAll(async () => {
        const { execSync } = await import('node:child_process');
        execSync(
            "docker exec pricing-tool-php php artisan tinker --execute=\"App\\Models\\PlamodInstockItem::updateOrCreate(['sku'=>'E2E-NEW-FILTER'], ['product_name'=>'E2E Filter Test Kit','price_stock'=>'9.99','series'=>'30 Minutes Fantasy (30MF)','category'=>'Plastic Model Kits','release_date'=>'2026-06-01','release_date_label'=>'Jun 2026','source_image_url'=>'https://cdn.example/e2e.jpg','last_seen_at'=>now()]);\"",
            { stdio: 'pipe' },
        );
        await resetTestSkuDecision();
    });

    test.afterAll(async () => {
        await purgeRestockTestSku();
    });

    test('loads new table with sortable headers and optional filters @smoke', async ({ page }) => {
        await gotoRestock(page);

        await expect(page.getByTestId('restock-filter-undecided')).not.toBeChecked();
        await expect(page.getByTestId('restock-filter-recent')).not.toBeChecked();
        await expect(page.getByTestId('restock-filter-series')).toHaveValue('');

        await expect(page.getByTestId('restock-new-bulk-column')).toContainText('Bulk');
        await expect(page.getByTestId('restock-new-cart-column')).toContainText('Cart');
        await expect(page.getByRole('button', { name: /Release/ }).first()).toBeVisible();
        await expect(page.getByRole('button', { name: /Series/ }).first()).toBeVisible();
        await expect(dataRows(page).first()).toBeVisible({ timeout: 15_000 });
    });

    test('automatically hides present and future products matching a saved product line', async ({
        page,
        request,
    }) => {
        const settingsResponse = await request.get('/api/v1/plamod/restock/settings');
        expect(settingsResponse.ok()).toBeTruthy();
        const originalSettings = (await settingsResponse.json()).data;

        try {
            await gotoRestock(page);
            await page.getByTestId('restock-table-search').fill(TEST_SKU);
            const testRow = dataRows(page).filter({ hasText: TEST_SKU });
            await expect(testRow).toBeVisible();

            await page.getByTestId('restock-exclusion-product-term').fill('E2E Filter Test Kit');
            await page.getByTestId('restock-add-product-exclusion').click();

            await expect(testRow).toHaveCount(0);
            await page.getByTestId('restock-hide-dismissed').uncheck();
            await expect(testRow).toContainText(/dismissed/i);

            page.once('dialog', (dialog) => dialog.accept());
            await page.getByTestId('restock-remove-product-exclusion-E2E Filter Test Kit').click();
            await expect(testRow).toContainText(/undecided/i);
        } finally {
            await request.put('/api/v1/plamod/restock/settings', {
                data: originalSettings,
            });
        }
    });

    test('existing product type filter isolates rows and shows its budget', async ({ page }) => {
        await gotoRestock(page);
        await page.getByTestId('restock-tab-existing').click();
        await expect(page.getByTestId('restock-existing-table')).toBeVisible();
        await expect(page.getByTestId('restock-existing-suggested-summary')).toHaveText(
            /System suggests \d+ unique products? · \d+ units?/,
        );

        const typeFilter = page.getByTestId('restock-existing-type-filter');
        const type = await typeFilter.locator('option').nth(1).getAttribute('value');
        expect(type).toBeTruthy();

        await typeFilter.selectOption(type!);
        const rows = existingRows(page);
        const count = await rows.count();
        expect(count).toBeGreaterThan(0);

        for (let index = 0; index < Math.min(count, 10); index += 1) {
            await expect(rows.nth(index).locator('td:nth-child(3)')).toHaveText(type!);
        }
        await expect(page.getByTestId('restock-existing-type-budget')).toContainText(
            `${type} budget`,
        );
        await expect(page.getByTestId('restock-existing-type-budget')).toContainText(/Product \$/);
        await expect(page.getByTestId('restock-existing-type-budget')).toContainText(/Shipping \$/);
        await expect(page.getByTestId('restock-existing-type-budget')).toContainText(/Landed \$/);

        await typeFilter.selectOption('');
        await expect(page.getByTestId('restock-existing-type-budget')).toHaveCount(0);
    });

    test('undecided-only filter narrows or holds rows and stays checked', async ({ page }) => {
        await gotoRestock(page);

        const baseline = await waitForNewRowsLoaded(page);
        expect(baseline).toBeGreaterThan(0);

        await page.getByTestId('restock-filter-undecided').check();
        await expect(page.getByTestId('restock-filter-undecided')).toBeChecked();

        const filtered = parseNewRowCount(await newSectionHeading(page).textContent());
        expect(filtered).toBeGreaterThan(0);
        expect(filtered).toBeLessThanOrEqual(baseline);

        const statuses = await dataRows(page).locator('td:nth-child(9)').allTextContents();
        expect(statuses.length).toBeGreaterThan(0);
        expect(statuses.every((s) => s.trim().toLowerCase() === 'undecided')).toBe(true);

        await page.getByTestId('restock-filter-undecided').uncheck();
        await expect(parseNewRowCount(await newSectionHeading(page).textContent())).toBe(baseline);
    });

    test('recent-only filter shows Recent badges and clears cleanly', async ({ page }) => {
        await gotoRestock(page);

        const baseline = await waitForNewRowsLoaded(page);
        expect(baseline).toBeGreaterThan(0);

        await page.getByTestId('restock-filter-recent').check();
        await expect(page.getByTestId('restock-filter-recent')).toBeChecked();

        const recentCount = parseNewRowCount(await newSectionHeading(page).textContent());
        expect(recentCount).toBeGreaterThan(0);
        expect(recentCount).toBeLessThan(baseline);

        await expect(dataRows(page).getByText('Recent').first()).toBeVisible();

        await page.getByTestId('restock-filter-recent').uncheck();
        await expect(parseNewRowCount(await newSectionHeading(page).textContent())).toBe(baseline);
    });

    test('status checkboxes combine as a multi-select filter', async ({ page }) => {
        await gotoRestock(page);

        await page.getByTestId('restock-filter-undecided').check();
        await page.getByTestId('restock-filter-later').check();

        await expect(page.getByTestId('restock-filter-undecided')).toBeChecked();
        await expect(page.getByTestId('restock-filter-later')).toBeChecked();

        const combinedStatuses = await dataRows(page).locator('td:nth-child(9)').allTextContents();
        expect(combinedStatuses.length).toBeGreaterThan(0);
        expect(
            combinedStatuses.every((status) =>
                ['undecided', 'later'].includes(status.trim().toLowerCase()),
            ),
        ).toBe(true);

        await page.getByTestId('restock-filter-undecided').uncheck();
        await expect(page.getByTestId('restock-filter-later')).toBeChecked();
        const laterStatuses = await dataRows(page).locator('td:nth-child(9)').allTextContents();
        expect(laterStatuses.length).toBeGreaterThan(0);
        expect(laterStatuses.every((status) => status.trim().toLowerCase() === 'later')).toBe(true);
    });

    test('series filter matches series column values', async ({ page }) => {
        await gotoRestock(page);

        const seriesSelect = page.getByTestId('restock-filter-series');
        const optionValue = await seriesSelect.locator('option').nth(1).getAttribute('value');
        expect(optionValue).toBeTruthy();

        await seriesSelect.selectOption(optionValue!);

        const rows = dataRows(page);
        await expect(rows.first()).toBeVisible();
        const seriesCells = await rows.locator('td:nth-child(6)').allTextContents();
        expect(seriesCells.length).toBeGreaterThan(0);
        expect(seriesCells.every((s) => s.trim() === optionValue)).toBe(true);

        await seriesSelect.selectOption('');
        await expect(newSectionHeading(page)).toContainText(/\d+ rows/);
    });

    test('undecided + recent + series combination filter', async ({ page }) => {
        await gotoRestock(page);

        await page.getByTestId('restock-filter-undecided').check();
        await page.getByTestId('restock-filter-recent').check();

        const seriesSelect = page.getByTestId('restock-filter-series');
        const optionValue = '30 Minutes Fantasy (30MF)';
        if ((await seriesSelect.locator(`option[value="${optionValue}"]`).count()) === 0) {
            test.skip(true, '30MF series not in snapshot');
        }
        await seriesSelect.selectOption(optionValue);

        const rows = dataRows(page);
        const count = await rows.count();
        if (count === 0) {
            await expect(page.getByTestId('restock-new-table')).toContainText(/No rows match/);
            return;
        }

        for (let i = 0; i < Math.min(count, 5); i++) {
            const row = rows.nth(i);
            await expect(row.locator('td:nth-child(9)')).toHaveText(/undecided/i);
            await expect(row.locator('td:nth-child(6)')).toHaveText(optionValue);
            await expect(row.getByText('Recent')).toBeVisible();
        }
    });

    test('search with series filter combines correctly', async ({ page }) => {
        await gotoRestock(page);

        await page.getByTestId('restock-filter-series').selectOption('30 Minutes Fantasy (30MF)');
        await page.getByTestId('restock-table-search').fill('E2E Filter');

        const rows = dataRows(page);
        await expect(rows).toHaveCount(1);
        await expect(rows.first()).toContainText(TEST_SKU);
    });

    test('bulk select shows action bar and clears on deselect', async ({ page }) => {
        await gotoRestock(page);
        await page.getByTestId('restock-table-search').fill(TEST_SKU);
        await expect(dataRows(page)).toHaveCount(1);

        await page.getByTestId('restock-new-select-all').check();
        await expect(page.getByTestId('restock-new-bulk-bar')).toContainText('1 selected');
        await expect(page.getByTestId('restock-bulk-include')).toBeVisible();
        await expect(page.getByTestId('restock-bulk-dismiss')).toBeVisible();

        await page.getByTestId('restock-new-select-all').uncheck();
        await expect(page.getByTestId('restock-new-bulk-bar')).toHaveCount(0);
    });

    test('dismissed status shows dismissed rows while preserving hide preference', async ({
        page,
    }) => {
        await resetTestSkuDecision();
        await gotoRestock(page);
        await page.getByTestId('restock-table-search').fill(TEST_SKU);

        await dataRows(page).getByRole('button', { name: 'Dismiss' }).click();
        await expect(dataRows(page)).toHaveCount(0);

        await page.getByTestId('restock-filter-dismissed').check();
        await expect(page.getByTestId('restock-filter-dismissed')).toBeChecked();
        await expect(page.getByTestId('restock-hide-dismissed')).toBeChecked();
        await expect(dataRows(page)).toHaveCount(1, { timeout: 15_000 });
        await expect(dataRows(page).locator('td:nth-child(9)')).toHaveText(/dismissed/i);

        await page.getByTestId('restock-filter-dismissed').uncheck();
        await expect(page.getByTestId('restock-filter-dismissed')).not.toBeChecked();
        await expect(dataRows(page)).toHaveCount(0);

        await page.getByTestId('restock-hide-dismissed').uncheck();
        await expect(dataRows(page)).toHaveCount(1);

        await page.getByTestId('restock-hide-dismissed').check();
        await expect(dataRows(page)).toHaveCount(0);
    });

    test('include dialog uses blank qty defaults', async ({ page }) => {
        await resetTestSkuDecision();
        await gotoRestock(page);
        await page.getByTestId('restock-table-search').fill(TEST_SKU);
        await dataRows(page).getByRole('button', { name: 'Include' }).click();

        const dialog = page.getByTestId('restock-include-dialog');
        await expect(dialog).toBeVisible();
        const inputs = dialog.locator('input[type="number"]');
        await expect(inputs.nth(0)).toHaveValue('');
        await expect(inputs.nth(1)).toHaveValue('');

        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toHaveCount(0);
    });

    test('include, inline edit, exclude-to-dismissed, and re-include from dismissed', async ({
        page,
    }) => {
        test.setTimeout(120_000);

        await resetTestSkuDecision();
        await gotoRestock(page);
        await page.getByTestId('restock-hide-dismissed').uncheck();
        await page.getByTestId('restock-table-search').fill(TEST_SKU);

        const row = dataRows(page);
        await expect(row).toHaveCount(1);

        await row.getByRole('button', { name: 'Include' }).click();
        const dialog = page.getByTestId('restock-include-dialog');
        await dialog.locator('input[type="number"]').nth(0).fill('2');
        await dialog.locator('input[type="number"]').nth(1).fill('4');
        await dialog.getByTestId('restock-include-submit').click();
        await expect(dialog).toHaveCount(0, { timeout: 15_000 });

        await expect(row.getByTestId(`restock-new-order-${TEST_SKU}`)).toHaveValue('2', {
            timeout: 15_000,
        });
        await expect(row.getByTestId(`restock-new-maintain-${TEST_SKU}`)).toHaveValue('4');

        await page.getByTestId('restock-filter-included').check();
        await expect(dataRows(page)).toHaveCount(1);
        await page.getByTestId('restock-filter-included').uncheck();

        await row.getByTestId(`restock-new-order-${TEST_SKU}`).fill('3');
        await row.getByTestId(`restock-new-order-${TEST_SKU}`).dispatchEvent('change');
        await expect(row.getByTestId(`restock-new-order-${TEST_SKU}`)).toHaveValue('3', {
            timeout: 15_000,
        });

        await row.getByRole('button', { name: 'Exclude' }).click();
        await page.getByTestId('restock-hide-dismissed').check();
        await expect(dataRows(page)).toHaveCount(0);

        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.url().includes('/api/v1/plamod/restock/proposal') &&
                    response.status() === 200,
            ),
            page.getByTestId('restock-hide-dismissed').uncheck(),
        ]);
        await page.getByTestId('restock-table-search').fill(TEST_SKU);
        const dismissedRow = dataRows(page);
        await expect(dismissedRow).toHaveCount(1, { timeout: 15_000 });
        await expect(dismissedRow.locator('td:nth-child(9)')).toHaveText(/dismissed/i);

        await dismissedRow.getByRole('button', { name: 'Include' }).click();
        await dialog.locator('input[type="number"]').nth(0).fill('1');
        await dialog.locator('input[type="number"]').nth(1).fill('2');
        await dialog.getByTestId('restock-include-submit').click();
        await expect(dismissedRow.locator('td:nth-child(9)')).toHaveText(/included/i, {
            timeout: 15_000,
        });

        // cleanup
        await dismissedRow.getByRole('button', { name: 'Exclude' }).click();
    });

    test('image overlay opens and closes', async ({ page }) => {
        await resetTestSkuDecision();
        await gotoRestock(page);
        await page.getByTestId('restock-table-search').fill(TEST_SKU);
        await expect(dataRows(page)).toHaveCount(1);

        const imageBtn = page.getByTestId(`restock-new-image-${TEST_SKU}`);
        await expect(imageBtn).toBeVisible({ timeout: 10_000 });

        await imageBtn.click();
        await expect(page.getByTestId('restock-image-overlay')).toBeVisible();
        await page.getByTestId('restock-image-overlay-close').click();
        await expect(page.getByTestId('restock-image-overlay')).toHaveCount(0);
    });

    test('later action, later-only filter, and include from later', async ({ page }) => {
        test.setTimeout(120_000);

        await resetTestSkuDecision();
        await gotoRestock(page);
        await page.getByTestId('restock-table-search').fill(TEST_SKU);

        const row = dataRows(page);
        await expect(row).toHaveCount(1);

        await row.getByTestId(`restock-new-later-${TEST_SKU}`).click();
        await expect(row.locator('td:nth-child(9)')).toHaveText(/later/i, { timeout: 15_000 });

        await page.getByTestId('restock-filter-later').check();
        await expect(page.getByTestId('restock-filter-later')).toBeChecked();
        await expect(page.getByTestId('restock-filter-undecided')).not.toBeChecked();
        await expect(dataRows(page)).toHaveCount(1);
        await expect(row.locator('td:nth-child(9)')).toHaveText(/later/i);

        await page.getByTestId('restock-filter-later').uncheck();

        await row.getByRole('button', { name: 'Include' }).click();
        const dialog = page.getByTestId('restock-include-dialog');
        await dialog.locator('input[type="number"]').nth(0).fill('1');
        await dialog.locator('input[type="number"]').nth(1).fill('2');
        await dialog.getByTestId('restock-include-submit').click();
        await expect(row.locator('td:nth-child(9)')).toHaveText(/included/i, { timeout: 15_000 });

        await row.getByRole('button', { name: 'Exclude' }).click();
    });

    test('persists filter state to localStorage', async ({ page }) => {
        await resetTestSkuDecision();
        await gotoRestock(page);

        await page.getByTestId('restock-filter-recent').check();
        await page.getByTestId('restock-filter-series').selectOption('30 Minutes Fantasy (30MF)');

        const stored = await page.evaluate((key) => localStorage.getItem(key), PAGE_STATE_KEY);
        expect(stored).toBeTruthy();
        const parsed = JSON.parse(stored!) as { filterRecentOnly: boolean; filterSeries: string };
        expect(parsed.filterRecentOnly).toBe(true);
        expect(parsed.filterSeries).toBe('30 Minutes Fantasy (30MF)');
    });
});
