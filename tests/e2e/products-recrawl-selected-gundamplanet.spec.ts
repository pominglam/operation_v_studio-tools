import { expect, test } from './fixtures';

test('recrawl selected sends only gundamplanet when selected', async ({ page, request, trackE2EProductId }) => {
    const uniq = String(Date.now());
    const sku = `E2E-REC-${uniq}`;

    const create = await request.post('/api/v1/products', {
        data: { sku, description: `E2E Product ${uniq}`, barcode: `E2E-REC-${uniq}-JAN`, vendor: 'Plamod' },
    });
    expect(create.ok()).toBeTruthy();
    const created = (await create.json()) as any;
    trackE2EProductId(created?.data?.id);

    const batchId = 'a0ff305a-594a-4b5c-b7bd-3d3eeb5a3f26';

    // Capture payload to ensure only GundamPlanet is sent.
    let seenPayload: any = null;
    await page.route('**/api/v1/products/recrawl/selected', async (route) => {
        const postData = route.request().postData() ?? '';
        try {
            seenPayload = JSON.parse(postData);
        } catch {
            seenPayload = postData;
        }
        await route.fulfill({
            status: 202,
            contentType: 'application/json',
            body: JSON.stringify({ ok: true, batch_id: batchId, queued: 1 }),
        });
    });

    // Stub sync progress endpoints so navigation works deterministically.
    await page.route('**/api/v1/job-batches/**', async (route) => {
        const url = new URL(route.request().url());
        if (url.pathname === '/api/v1/job-batches' && url.searchParams.get('limit') === '50') {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    ok: true,
                    data: [
                        {
                            id: batchId,
                            name: 'recrawl_selected_products',
                            total_jobs: 1,
                            pending_jobs: 0,
                            failed_jobs: 0,
                            created_at: Math.floor(Date.now() / 1000),
                            finished_at: Math.floor(Date.now() / 1000),
                            cancelled_at: null,
                        },
                    ],
                }),
            });
            return;
        }
        if (url.pathname === `/api/v1/job-batches/${batchId}`) {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    ok: true,
                    data: {
                        id: batchId,
                        name: 'recrawl_selected_products',
                        total_jobs: 1,
                        pending_jobs: 0,
                        processed_jobs: 1,
                        failed_jobs: 0,
                        progress_percent: 100,
                        cancelled: false,
                        finished_at: new Date().toISOString(),
                        cancelled_at: null,
                    },
                }),
            });
            return;
        }
        if (url.pathname === `/api/v1/job-batches/${batchId}/items`) {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    ok: true,
                    data: {
                        counts: { queued: 0, running: 0, succeeded: 0, failed: 0, skipped: 1 },
                        queued: [],
                        running: [],
                        done: [
                            {
                                product_uuid: '00000000-0000-0000-0000-000000070100',
                                sku,
                                vendor: 'Plamod',
                                status: 'skipped',
                                attempts: 1,
                                sync_uuid: 'sync-uuid',
                                last_error: 'no_sources_found',
                                debug_log:
                                    '[job] sources=gundamplanet\n' +
                                    '[gundamplanet][plan] terms_count=1\n' +
                                    '[gundamplanet][plan] q=RG 1/144 RX-78-2 GUNDAM Ver.2.0 url=https://www.gundamplanet.com/search?q=RG%201%2F144%20RX-78-2%20GUNDAM%20Ver.2.0&options%5Bprefix%5D=last\n' +
                                    '[gundamplanet][start] terms=RG 1/144 RX-78-2 GUNDAM Ver.2.0\n' +
                                    '[gundamplanet][pdp_not_found]',
                                started_at: new Date().toISOString(),
                                finished_at: new Date().toISOString(),
                            },
                        ],
                    },
                }),
            });
            return;
        }

        await route.continue();
    });

    await page.goto('/products');

    // Filter to ensure the seeded SKU is visible in the table.
    await page.getByPlaceholder('Search SKU / barcode / name…').fill(sku);
    await expect(page.getByText(sku, { exact: true })).toBeVisible();

    // Select the row by checking the checkbox in the row containing the SKU.
    const row = page.locator('tr', { hasText: sku });
    await row.locator('input[type="checkbox"]').first().check();

    await page.getByRole('button', { name: 'Recrawl selected' }).click();

    // Uncheck everything except GundamPlanet.
    const modal = page.locator('[role="dialog"]');
    await expect(modal.getByText('Recrawl selected products')).toBeVisible();

    // Ensure only GundamPlanet is checked.
    const labels = modal.locator('label');
    const labelCount = await labels.count();
    for (let i = 0; i < labelCount; i++) {
        const label = labels.nth(i);
        const text = (await label.textContent()) ?? '';
        const cb = label.locator('input[type="checkbox"]');
        if ((await cb.count()) === 0) continue;
        if (text.includes('GundamPlanet')) {
            await cb.check();
        } else {
            await cb.uncheck();
        }
    }

    await modal.getByRole('button', { name: 'Recrawl' }).click();

    // Assert the request payload is correct.
    expect(seenPayload).toBeTruthy();
    expect(seenPayload.ids).toEqual(expect.any(Array));
    expect(seenPayload.sources).toEqual(['gundamplanet']);

    // Should navigate to Sync Progress and show debug details toggle.
    await expect(page.getByText('Sync Progress')).toBeVisible();
    await expect(page.getByText(sku)).toBeVisible();
    await page.getByRole('button', { name: 'View details' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page.getByText('sources: gundamplanet')).toBeVisible();
    await expect(page.getByText('www.gundamplanet.com/search?q=')).toBeVisible();
});

