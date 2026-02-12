import { expect, test } from './fixtures';

test('sync progress debug log renders multiple sources consistently', async ({ page, request, trackE2EProductId }) => {
    const uniq = String(Date.now());
    const sku = `E2E-LOG-${uniq}`;

    const create = await request.post('/api/v1/products', {
        data: { sku, description: `E2E Product ${uniq}`, barcode: `E2E-LOG-${uniq}-JAN`, vendor: 'Plamod' },
    });
    expect(create.ok()).toBeTruthy();
    const created = (await create.json()) as any;
    trackE2EProductId(created?.data?.id);

    const batchId = 'a0ff305a-594a-4b5c-b7bd-3d3eeb5a3f99';

    // Stub sync progress endpoints so the UI can be asserted deterministically.
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
                        counts: { queued: 0, running: 0, succeeded: 1, failed: 0, skipped: 0 },
                        queued: [],
                        running: [],
                        done: [
                            {
                                product_uuid: created?.data?.id,
                                sku,
                                product_name: `E2E Product ${uniq}`,
                                vendor: 'Plamod',
                                status: 'succeeded',
                                attempts: 1,
                                sync_uuid: 'sync-uuid',
                                last_error: null,
                                debug_log:
                                    '[job] sources=bandai,hlj,gundamplanet,plamod,competitor_price_research\n' +
                                    '[plamod][start]\n' +
                                    '[plamod][done] result=ok duration_ms=123 assets=4 has_description=true\n' +
                                    '[hlj][start]\n' +
                                    '[hlj][done] result=ok duration_ms=55\n' +
                                    '[bandai][start]\n' +
                                    '[bandai][done] result=skipped duration_ms=10\n' +
                                    '[competitor_price_research][start]\n' +
                                    '[competitor_price_research][done] result=ok duration_ms=99 processed=1 quotes_written=3\n' +
                                    '[gundamplanet][plan] terms_count=1\n' +
                                    '[gundamplanet][start] terms=RG 1/144 RX-78-2 GUNDAM Ver.2.0\n' +
                                    '[gundamplanet][pdp_not_found]\n' +
                                    '[job][summary] result=partial failed_sources=gundamplanet',
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

    await page.goto(`/sync-progress?batch_id=${batchId}`);
    await expect(page.getByText('Sync Progress')).toBeVisible();

    await expect(page.getByText(sku)).toBeVisible();
    await page.getByRole('button', { name: 'View details' }).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByText('sources: bandai,hlj,gundamplanet,plamod,competitor_price_research')).toBeVisible();

    // Ensure the standardized entries are readable (not just empty chips).
    const plamodEntry = dialog.locator('li', { hasText: 'assets 4' }).first();
    await expect(plamodEntry).toBeVisible();
    await expect(plamodEntry).toContainText('plamod');
    await expect(plamodEntry).toContainText('result');
    await expect(plamodEntry).toContainText('has_desc true');

    const competitorEntry = dialog.locator('li', { hasText: 'quotes 3' }).first();
    await expect(competitorEntry).toBeVisible();
    await expect(competitorEntry).toContainText('competitor_price_research');
});

