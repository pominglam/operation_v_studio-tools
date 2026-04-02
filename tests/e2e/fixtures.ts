import { expect as baseExpect, request as playwrightRequest, test as baseTest } from '@playwright/test';

type E2EFixtures = {
    /**
     * Track product UUIDs created by the current test that must be deleted in teardown.
     * We only delete explicit UUIDs we created, to avoid touching real products.
     */
    trackE2EProductId: (uuid: unknown) => void;
    e2eProductIds: string[];
    trackE2EPurchaseOrderId: (uuid: unknown) => void;
    e2ePurchaseOrderIds: string[];

    /**
     * Worker-scoped list of all tracked UUIDs across the run.
     * Internal: used as a safety net cleanup.
     */
    _e2eAllProductIds: string[];
    _e2eAllPurchaseOrderIds: string[];
};

export const test = baseTest.extend<E2EFixtures>({
    // Worker-scoped list of all products created during this test run (for safety cleanup).
    // We delete ONLY UUIDs we tracked, never by wildcard.
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    _e2eAllProductIds: [async ({}, use) => {
        const ids: string[] = [];
        await use(ids);
    }, { scope: 'worker' }],
    _e2eAllPurchaseOrderIds: [async ({}, use) => {
        const ids: string[] = [];
        await use(ids);
    }, { scope: 'worker' }],

    e2eProductIds: async ({}, use) => {
        const ids: string[] = [];
        await use(ids);
    },
    e2ePurchaseOrderIds: async ({}, use) => {
        const ids: string[] = [];
        await use(ids);
    },

    trackE2EProductId: async ({ e2eProductIds, _e2eAllProductIds }, use) => {
        const isUuid = (v: unknown): v is string =>
            typeof v === 'string' &&
            /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(v);

        const track = (uuid: unknown): void => {
            if (!isUuid(uuid)) {
                throw new Error(`E2E cleanup: expected UUID string, got: ${String(uuid)}`);
            }
            e2eProductIds.push(uuid);
            _e2eAllProductIds.push(uuid);
        };

        await use(track);
    },
    trackE2EPurchaseOrderId: async (
        { e2ePurchaseOrderIds, _e2eAllPurchaseOrderIds },
        use,
    ) => {
        const isUuid = (v: unknown): v is string =>
            typeof v === 'string' &&
            /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(v);

        const track = (uuid: unknown): void => {
            if (!isUuid(uuid)) {
                throw new Error(`E2E cleanup: expected UUID string, got: ${String(uuid)}`);
            }
            e2ePurchaseOrderIds.push(uuid);
            _e2eAllPurchaseOrderIds.push(uuid);
        };

        await use(track);
    },
});

export const expect = baseExpect;

test.afterEach(async ({
    request,
    e2ePurchaseOrderIds,
    _e2eAllPurchaseOrderIds,
    e2eProductIds,
    _e2eAllProductIds,
}) => {
    const cleanupErrors: string[] = [];

    if (e2ePurchaseOrderIds.length > 0) {
        // Delete tracked POs first so product cleanup is not blocked by PO references.
        for (const id of e2ePurchaseOrderIds) {
            const res = await request.delete(`/api/v1/purchase-orders/${id}`);
            const body = await res.text().catch(() => '');
            if (!res.ok()) {
                cleanupErrors.push(
                    `E2E PO cleanup failed for ${id}: HTTP ${res.status()} body=${body}`,
                );
                continue;
            }
            const idx = _e2eAllPurchaseOrderIds.indexOf(id);
            if (idx >= 0) {
                _e2eAllPurchaseOrderIds.splice(idx, 1);
            }
        }
    }

    if (e2eProductIds.length > 0) {
        // Delete ONLY the specific products we created in this test.
        const res = await request.post('/api/v1/products/bulk-delete', {
            data: { ids: e2eProductIds },
        });

        const body = (await res.json().catch(() => null)) as null | { deleted?: number };
        if (!res.ok()) {
            cleanupErrors.push(
                `E2E product cleanup failed: HTTP ${res.status()} body=${JSON.stringify(body)}`,
            );
        } else if (body?.deleted !== e2eProductIds.length) {
            cleanupErrors.push(
                `E2E product cleanup mismatch: expected=${e2eProductIds.length} body=${JSON.stringify(body)}`,
            );
        } else {
            // Remove successfully deleted IDs from the worker-scoped list.
            for (const id of e2eProductIds) {
                const idx = _e2eAllProductIds.indexOf(id);
                if (idx >= 0) _e2eAllProductIds.splice(idx, 1);
            }
        }
    }

    expect(cleanupErrors, cleanupErrors.join('\n')).toEqual([]);
});

test.afterAll(async ({ _e2eAllPurchaseOrderIds, _e2eAllProductIds }) => {
    if (_e2eAllPurchaseOrderIds.length === 0 && _e2eAllProductIds.length === 0) return;

    // Safety net: if a test crashed before afterEach, ensure we still clean up
    // ONLY tracked UUIDs.
    const baseURL = process.env.E2E_BASE_URL ?? 'http://localhost:8020';
    const api = await playwrightRequest.newContext({ baseURL });
    try {
        const cleanupErrors: string[] = [];

        if (_e2eAllPurchaseOrderIds.length > 0) {
            for (const id of _e2eAllPurchaseOrderIds) {
                const res = await api.delete(`/api/v1/purchase-orders/${id}`);
                const body = await res.text().catch(() => '');
                if (!res.ok()) {
                    cleanupErrors.push(
                        `E2E final PO cleanup failed for ${id}: HTTP ${res.status()} body=${body}`,
                    );
                }
            }
            _e2eAllPurchaseOrderIds.splice(0, _e2eAllPurchaseOrderIds.length);
        }

        if (_e2eAllProductIds.length > 0) {
            const res = await api.post('/api/v1/products/bulk-delete', {
                data: { ids: _e2eAllProductIds },
            });
            const body = (await res.json().catch(() => null)) as null | { deleted?: number };
            if (!res.ok()) {
                cleanupErrors.push(
                    `E2E final product cleanup failed: HTTP ${res.status()} body=${JSON.stringify(body)}`,
                );
            } else if (body?.deleted !== _e2eAllProductIds.length) {
                cleanupErrors.push(
                    `E2E final product cleanup mismatch: expected=${_e2eAllProductIds.length} body=${JSON.stringify(body)}`,
                );
            }
            _e2eAllProductIds.splice(0, _e2eAllProductIds.length);
        }

        expect(cleanupErrors, cleanupErrors.join('\n')).toEqual([]);
    } finally {
        await api.dispose();
    }
});

