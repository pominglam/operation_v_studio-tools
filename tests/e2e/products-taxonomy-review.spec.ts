import { expect, test } from './fixtures';
import {
    fetchTaxonomyVerifications,
    filterTaxonomyStatus,
    gotoTaxonomyReview,
    waitForTaxonomyTable,
} from './helpers/taxonomy-review';

test.describe.configure({ mode: 'serial' });

test.describe('taxonomy review', () => {
    test.setTimeout(240_000);

    test.beforeEach(async ({ page }) => {
        await gotoTaxonomyReview(page);
        await waitForTaxonomyTable(page);
    });

    test('loads grid without legacy series-resolution column headers', async ({ page }) => {
        await expect(page.getByTestId('taxonomy-review-table')).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Plamod' })).toHaveCount(0);
        await expect(page.getByRole('columnheader', { name: 'Series resolution' })).toHaveCount(0);
        await expect(page.getByRole('columnheader', { name: 'Series' })).toBeVisible();
    });

    test('research selected requires selection and confirmation', async ({ page }) => {
        await expect(page.getByTestId('taxonomy-research-selected')).toBeDisabled();

        let researchPosts = 0;
        await page.route('**/api/v1/products/taxonomy/verifications/research', async (route) => {
            researchPosts += 1;
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ data: { researched: 1, skipped: 0, failed: 0 } }),
            });
        });

        await page.getByTestId('taxonomy-select-row').first().check();
        await expect(page.getByTestId('taxonomy-research-selected')).toBeEnabled();
        await expect(page.getByTestId('taxonomy-research-selected')).toContainText('(1)');

        await page.getByTestId('taxonomy-research-selected').click();
        await expect(page.getByText(/Research 1 selected row\(s\)/)).toBeVisible();
        await expect(page.getByTestId('taxonomy-research-field-series')).toBeChecked();
        await expect(page.getByText(/ERP is not updated until you approve/i)).toBeVisible();

        await page.getByRole('button', { name: 'Cancel' }).click();
        await expect(page.getByText(/Research 1 selected row\(s\)/)).toHaveCount(0);
        expect(researchPosts).toBe(0);

        await page.getByTestId('taxonomy-research-selected').click();
        await page.getByTestId('taxonomy-research-series-only').click();
        await expect(page.getByTestId('taxonomy-research-field-series')).toBeChecked();
        await expect(page.getByTestId('taxonomy-research-field-grade')).not.toBeChecked();

        let postedFields: string[] = [];
        await page.unroute('**/api/v1/products/taxonomy/verifications/research');
        await page.route('**/api/v1/products/taxonomy/verifications/research', async (route) => {
            researchPosts += 1;
            const body = route.request().postDataJSON() as { fields?: string[] };
            postedFields = body.fields ?? [];
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ data: { researched: 1, skipped: 0, failed: 0 } }),
            });
        });

        await page.getByTestId('taxonomy-research-confirm').click();
        await expect(page.getByText(/Researched 1 row\(s\) \(series\)/)).toBeVisible({
            timeout: 15_000,
        });
        expect(researchPosts).toBe(1);
        expect(postedFields).toEqual(['series']);
    });

    test('verified rows are selectable and enable bulk update', async ({ page }) => {
        await filterTaxonomyStatus(page, 'verified');

        const firstCheckbox = page.getByTestId('taxonomy-select-row').first();
        await expect(firstCheckbox).toBeEnabled();
        await expect(page.getByTestId('taxonomy-bulk-update')).toBeDisabled();

        await firstCheckbox.check();
        await expect(page.getByTestId('taxonomy-bulk-update')).toBeEnabled();
        await expect(page.getByTestId('taxonomy-bulk-update')).toContainText('(1)');
    });

    test('bulk update applies idempotent scale change on a verified row', async ({
        page,
        request,
    }) => {
        const rows = await fetchTaxonomyVerifications(request, {
            status: 'verified',
            per_page: 5,
            departments: ['model kits'],
        });
        const target =
            rows.find((row) => row.product.scale && row.product.scale.trim() !== '') ?? rows[0];
        expect(target, 'Need at least one verified model-kit row for bulk update e2e').toBeTruthy();

        await page.getByTestId('taxonomy-search').fill(target.product.sku);
        await page.getByTestId('taxonomy-search').press('Enter');
        await waitForTaxonomyTable(page);

        const skuCell = page.getByText(target.product.sku, { exact: true });
        await expect(skuCell).toBeVisible();
        const row = page.locator('tr', { has: skuCell });
        await row.getByTestId('taxonomy-select-row').check();

        await page.getByTestId('taxonomy-bulk-update').click();
        await expect(page.getByText(`Bulk update 1 selected`)).toBeVisible();

        await page.getByTestId('taxonomy-bulk-apply-scale').check();
        const scaleSelect = page.getByTestId('taxonomy-bulk-value-scale');
        if (target.product.scale) {
            await scaleSelect.selectOption(target.product.scale);
        } else {
            await scaleSelect.selectOption({ index: 1 });
        }

        const bulkUpdateResponse = page.waitForResponse(
            (response) =>
                response.url().includes('/api/v1/products/taxonomy/verifications/bulk-update') &&
                response.request().method() === 'POST',
            { timeout: 30_000 },
        );
        await page.getByTestId('taxonomy-bulk-update-confirm').click();
        const response = await bulkUpdateResponse;
        expect(response.ok(), await response.text()).toBeTruthy();

        await expect(page.getByText(/Updated 1 row\(s\)/)).toBeVisible({ timeout: 15_000 });
    });

    test('series cell opens decision dialog with sources and saves idempotently', async ({
        page,
    }) => {
        await page.getByTestId('taxonomy-department').selectOption('model kits');
        await waitForTaxonomyTable(page);

        const seriesCell = page.getByTestId('taxonomy-series-cell').first();
        await expect(seriesCell).toBeVisible();
        const sku = await page
            .locator('tr', { has: seriesCell })
            .locator('td')
            .nth(1)
            .textContent();
        expect(sku?.trim()).toBeTruthy();

        await seriesCell.click();
        await expect(page.getByTestId('taxonomy-series-decision-dialog')).toBeVisible();

        const sourceTable = page.getByRole('columnheader', { name: 'Source' });
        if (await sourceTable.isVisible().catch(() => false)) {
            await expect(page.getByRole('columnheader', { name: 'Value' })).toBeVisible();
            await expect(page.getByText('ERP stored')).toBeVisible();
        } else {
            await expect(
                page.getByText(/No multi-source series resolution for this product/i),
            ).toBeVisible();
        }

        const seriesSelect = page.getByTestId('taxonomy-series-decision-value');
        const selectedSeries = await seriesSelect.inputValue();
        expect(selectedSeries.trim()).not.toBe('');

        const saveResponse = page.waitForResponse(
            (response) =>
                response.url().includes('/api/v1/products/taxonomy/verifications/') &&
                response.url().includes('/approve') &&
                response.request().method() === 'PATCH',
            { timeout: 30_000 },
        );
        await page.getByTestId('taxonomy-series-decision-save').click();
        const response = await saveResponse;
        expect(response.ok(), await response.text()).toBeTruthy();

        await expect(page.getByText(new RegExp(`Saved series for ${sku?.trim()}`))).toBeVisible({
            timeout: 15_000,
        });
        await expect(page.getByTestId('taxonomy-series-decision-dialog')).toHaveCount(0);
    });
});
