import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import PreordersPage from '../PreordersPage.vue';

const apiGet = vi.fn();
const apiPost = vi.fn();
const apiPut = vi.fn();

vi.mock('../../lib/api', () => ({
    api: {
        get: (...args: unknown[]) => apiGet(...args),
        post: (...args: unknown[]) => apiPost(...args),
        put: (...args: unknown[]) => apiPut(...args),
    },
}));

vi.mock('../../components/ui/MultiSelectFilter.vue', () => ({
    default: {
        name: 'MultiSelectFilter',
        props: ['modelValue', 'options', 'placeholder'],
        template: '<div data-testid="multi-select" />',
    },
}));

describe('PreordersPage', () => {
    beforeEach(() => {
        apiGet.mockReset();
        apiPost.mockReset();
        apiPut.mockReset();

        apiGet.mockImplementation((url: string) => {
            if (url === '/api/v1/preorders') {
                return Promise.resolve({
                    data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0, categories: [] } },
                });
            }
            if (url === '/api/v1/preorders/settings') {
                return Promise.resolve({ data: { data: { excluded_categories: [] } } });
            }
            if (url === '/api/v1/preorders/sync-status') {
                return Promise.resolve({ data: { data: { status: 'never', counts: {} } } });
            }
            return Promise.resolve({ data: {} });
        });
    });

    it('queues refresh from Plamod', async () => {
        apiPost.mockResolvedValue({ data: { data: { ok: true, sync_log_id: 1 } } });

        const wrapper = mount(PreordersPage);
        await Promise.resolve();
        await Promise.resolve();

        await wrapper.get('[data-testid="preorders-refresh"]').trigger('click');
        await Promise.resolve();

        expect(apiPost).toHaveBeenCalledWith('/api/v1/preorders/sync');
    });

    it('shows scraper error message when refresh returns 422', async () => {
        apiPost.mockRejectedValue({
            isAxiosError: true,
            response: {
                data: {
                    data: {
                        ok: false,
                        error_message: 'Plamod scraper is running outdated code. Restart the pricing-tool-plamod-scraper container, then retry.',
                    },
                },
            },
        });

        const wrapper = mount(PreordersPage);
        await Promise.resolve();
        await Promise.resolve();

        await wrapper.get('[data-testid="preorders-refresh"]').trigger('click');
        await Promise.resolve();
        await Promise.resolve();

        expect(wrapper.text()).toContain('Restart the pricing-tool-plamod-scraper container');
    });

    it('loads preorders on mount', async () => {
        mount(PreordersPage);
        await Promise.resolve();

        expect(apiGet).toHaveBeenCalledWith(
            '/api/v1/preorders',
            expect.objectContaining({
                params: expect.objectContaining({ per_page: 50 }),
            }),
        );
    });
});
