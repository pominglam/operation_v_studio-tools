import { expect as baseExpect, request as playwrightRequest, test as baseTest } from '@playwright/test';

type E2EFixtures = {
    /**
     * Track product UUIDs created by the current test that must be deleted in teardown.
     * We only delete explicit UUIDs we created, to avoid touching real products.
     */
    trackE2EProductId: (uuid: unknown) => void;
    e2eProductIds: string[];

    /**
     * Worker-scoped list of all tracked UUIDs across the run.
     * Internal: used as a safety net cleanup.
     */
    _e2eAllProductIds: string[];
};

export const test = baseTest.extend<E2EFixtures>({
    // Worker-scoped list of all products created during this test run (for safety cleanup).
    // We delete ONLY UUIDs we tracked, never by wildcard.
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    _e2eAllProductIds: [async ({}, use) => {
        const ids: string[] = [];
        await use(ids);
    }, { scope: 'worker' }],

    e2eProductIds: async ({}, use) => {
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
});

export const expect = baseExpect;

test.afterEach(async ({ request, e2eProductIds, _e2eAllProductIds }) => {
    if (e2eProductIds.length === 0) return;

    // Delete ONLY the specific products we created in this test.
    const res = await request.post('/api/v1/products/bulk-delete', {
        data: { ids: e2eProductIds },
    });

    const body = (await res.json().catch(() => null)) as null | { deleted?: number };
    expect(res.ok(), `E2E cleanup failed: HTTP ${res.status()} body=${JSON.stringify(body)}`).toBeTruthy();
    expect(body?.deleted, `E2E cleanup mismatch: body=${JSON.stringify(body)}`).toBe(e2eProductIds.length);

    // Remove successfully deleted IDs from the worker-scoped list.
    for (const id of e2eProductIds) {
        const idx = _e2eAllProductIds.indexOf(id);
        if (idx >= 0) _e2eAllProductIds.splice(idx, 1);
    }
});

test.afterAll(async ({ _e2eAllProductIds }) => {
    if (_e2eAllProductIds.length === 0) return;

    // Safety net: if a test crashed before afterEach, ensure we still clean up
    // ONLY tracked UUIDs.
    const baseURL = process.env.E2E_BASE_URL ?? 'http://localhost:8020';
    const api = await playwrightRequest.newContext({ baseURL });
    try {
        const res = await api.post('/api/v1/products/bulk-delete', {
            data: { ids: _e2eAllProductIds },
        });
        const body = (await res.json().catch(() => null)) as null | { deleted?: number };
        expect(res.ok(), `E2E final cleanup failed: HTTP ${res.status()} body=${JSON.stringify(body)}`).toBeTruthy();
        expect(body?.deleted, `E2E final cleanup mismatch: body=${JSON.stringify(body)}`).toBe(_e2eAllProductIds.length);
        _e2eAllProductIds.splice(0, _e2eAllProductIds.length);
    } finally {
        await api.dispose();
    }
});

