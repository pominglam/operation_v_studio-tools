import { expect, type APIRequestContext, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

export function readExternalPasswordFromDotEnv(): string | null {
    try {
        const raw = fs.readFileSync(path.resolve(process.cwd(), '.env'), 'utf8');
        const match = raw.match(/^\s*EXTERNAL_ACCESS_PASSWORD\s*=\s*(.+)\s*$/m);
        if (!match) {
            return null;
        }
        let value = String(match[1] ?? '').trim();
        if (
            (value.startsWith('"') && value.endsWith('"')) ||
            (value.startsWith("'") && value.endsWith("'"))
        ) {
            value = value.slice(1, -1);
        }
        return value.trim() !== '' ? value.trim() : null;
    } catch {
        return null;
    }
}

export async function passExternalAccessGateIfPresent(page: Page): Promise<void> {
    const externalAccess = page.getByRole('heading', { name: 'External access' });
    if (!(await externalAccess.isVisible().catch(() => false))) {
        return;
    }
    const password = readExternalPasswordFromDotEnv();
    if (!password) {
        throw new Error('EXTERNAL_ACCESS_PASSWORD is required to pass the tunnel login in e2e');
    }
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Log in' }).click();
}

export async function gotoTaxonomyReview(page: Page): Promise<void> {
    await page.goto('/products/taxonomy', { waitUntil: 'domcontentloaded' });
    await passExternalAccessGateIfPresent(page);
    await expect(page.getByRole('heading', { name: 'Taxonomy review' })).toBeVisible({
        timeout: 30_000,
    });
}

export async function waitForTaxonomyTable(page: Page): Promise<void> {
    // Do not call response.json() in the matcher — Playwright consumes the body and breaks the SPA fetch.
    await page.waitForResponse(
        (response) =>
            response.url().includes('/api/v1/products/taxonomy/verifications') &&
            response.request().method() === 'GET' &&
            response.ok(),
        { timeout: 120_000 },
    );
    await expect(page.getByTestId('taxonomy-review-table')).toBeVisible({ timeout: 30_000 });
    await page.waitForFunction(
        () =>
            document.querySelectorAll('[data-testid="taxonomy-review-table"] tbody tr').length > 0,
        { timeout: 120_000 },
    );
    await expect(page.getByTestId('taxonomy-select-row').first()).toBeVisible({ timeout: 15_000 });
}

export async function filterTaxonomyStatus(
    page: Page,
    status: '' | 'proposed' | 'verified' | 'overridden',
): Promise<void> {
    await page.getByTestId('taxonomy-status').selectOption(status);
    await waitForTaxonomyTable(page);
}

export type TaxonomyVerificationRow = {
    id: string;
    status: string;
    product: {
        sku: string;
        description: string;
        scale: string | null;
        series: string | null;
        department: string | null;
    };
    series_resolution: {
        erp: string | null;
        final_decision: string | null;
        confidence: string;
    } | null;
};

export async function fetchTaxonomyVerifications(
    request: APIRequestContext,
    params: Record<string, string | number | string[]>,
): Promise<TaxonomyVerificationRow[]> {
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (Array.isArray(value)) {
            for (const item of value) {
                search.append(`${key}[]`, item);
            }
            continue;
        }
        search.set(key, String(value));
    }

    const response = await request.get(
        `/api/v1/products/taxonomy/verifications?${search.toString()}`,
    );
    expect(response.ok(), `taxonomy verifications failed: ${await response.text()}`).toBeTruthy();
    const body = (await response.json()) as { data?: TaxonomyVerificationRow[] };
    return body.data ?? [];
}
