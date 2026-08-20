import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import MaintenancePage from '../MaintenancePage.vue';

vi.mock('../../lib/api', () => ({
    api: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));

import { api } from '../../lib/api';

const runningSnapshot = {
    data: {
        order_reconcile_interval_hours: 12,
        tasks: [
            {
                key: 'inventory_pull',
                label: 'Pull Shopify inventory',
                status: 'running',
                queued: false,
                last_started_at: '2026-08-19T22:40:00Z',
                last_finished_at: null,
                duration_ms: null,
                records_fetched: null,
                records_updated: null,
                error_summary: null,
                counts_json: null,
            },
        ],
    },
};

const completedSnapshot = {
    data: {
        ...runningSnapshot.data,
        tasks: [
            {
                ...runningSnapshot.data.tasks[0],
                status: 'completed',
                last_finished_at: '2026-08-19T22:41:00Z',
                records_fetched: 25,
                records_updated: 20,
            },
        ],
    },
};

function mockSettingsResponses(
    responses: Array<typeof runningSnapshot | Error>,
): ReturnType<typeof vi.mocked<typeof api.get>> {
    const getMock = vi.mocked(api.get);
    getMock.mockImplementation(async (url: string) => {
        if (url === '/api/v1/shopify/settings') {
            const callCount = getMock.mock.calls.filter(
                ([calledUrl]) => calledUrl === '/api/v1/shopify/settings',
            ).length;
            const response = responses[Math.min(callCount - 1, responses.length - 1)];
            if (response instanceof Error) throw response;

            return { data: response };
        }

        if (url === '/api/v1/maintenance/notes') {
            return { status: 200, data: { data: { body: '' } } };
        }
        if (url === '/api/v1/maintenance/db-backups') {
            return { status: 200, data: { data: [] } };
        }
        if (url === '/api/v1/maintenance/external-rate-limit') {
            return { data: { data: { hits_per_minute: 10 } } };
        }
        if (url === '/api/v1/maintenance/external-access') {
            return {
                data: {
                    data: {
                        enabled: false,
                        password_configured: false,
                        tunnel: null,
                    },
                },
            };
        }

        throw new Error(`Unexpected GET ${url}`);
    });

    return getMock;
}

function mountPage(): ReturnType<typeof mount> {
    return mount(MaintenancePage, {
        global: {
            stubs: {
                RouterLink: true,
                ConfirmDialog: true,
                MultiSelectFilter: true,
            },
        },
    });
}

describe('MaintenancePage Shopify status polling', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
        sessionStorage.clear();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ data: {} }),
            }),
        );
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('automatically refreshes task status and stops polling after unmount', async () => {
        const getMock = mockSettingsResponses([runningSnapshot, completedSnapshot]);
        const wrapper = mountPage();

        await flushPromises();
        expect(wrapper.text()).toContain('Running');

        await wrapper.get('input[type="number"]').setValue(24);
        await vi.advanceTimersByTimeAsync(5_000);
        await flushPromises();

        expect(wrapper.text()).toContain('Completed');
        expect(wrapper.text()).toContain('fetched 25, updated 20');
        expect(wrapper.get<HTMLInputElement>('input[type="number"]').element.value).toBe('24');
        expect(getMock).toHaveBeenCalledTimes(6);

        wrapper.unmount();
        await vi.advanceTimersByTimeAsync(10_000);

        expect(getMock).toHaveBeenCalledTimes(6);
    });

    it('keeps the last status when a background refresh fails', async () => {
        mockSettingsResponses([runningSnapshot, new Error('temporary failure')]);
        const wrapper = mountPage();

        await flushPromises();
        await vi.advanceTimersByTimeAsync(5_000);
        await flushPromises();

        expect(wrapper.text()).toContain('Running');
        expect(wrapper.text()).not.toContain('Failed to load Shopify settings.');

        wrapper.unmount();
    });
});
