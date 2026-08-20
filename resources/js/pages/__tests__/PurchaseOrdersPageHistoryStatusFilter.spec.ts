import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

import PurchaseOrdersPage from '../PurchaseOrdersPage.vue';

vi.mock('../../lib/api', () => {
    return {
        api: {
            get: vi.fn(),
            post: vi.fn(),
            patch: vi.fn(),
            put: vi.fn(),
            delete: vi.fn(),
        },
    };
});

import { api } from '../../lib/api';

function flush(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

const emptyPaginated = {
    data: [],
    meta: {
        current_page: 1,
        last_page: 1,
        per_page: 50,
        total: 0,
    },
};

describe('PurchaseOrdersPage history status filter', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.restoreAllMocks();

        const getMock = api.get as unknown as ReturnType<typeof vi.fn>;
        getMock.mockImplementation(async (url: string) => {
            if (url === '/api/v1/purchase-orders/filter-options') {
                return { status: 200, data: { data: { vendors: [] } } };
            }
            if (url === '/api/v1/products/filter-options') {
                return { status: 200, data: { data: { vendors: [] } } };
            }
            if (url === '/api/v1/purchase-orders') {
                return { status: 200, data: emptyPaginated };
            }
            throw new Error(`Unexpected GET ${url}`);
        });
    });

    it('defaults to active pipeline statuses without on_shelves', async () => {
        mount(PurchaseOrdersPage);
        await flush();
        await nextTick();

        const getMock = api.get as unknown as ReturnType<typeof vi.fn>;
        const poListCall = getMock.mock.calls.find((call) => call[0] === '/api/v1/purchase-orders');
        expect(poListCall).toBeDefined();
        expect(poListCall?.[1]?.params?.statuses).toEqual([
            'draft',
            'ordered',
            'shipped',
            'received',
        ]);
    });

    it('reset filters restores default statuses without on_shelves', async () => {
        localStorage.setItem(
            'purchase-orders:history-filters:v2',
            JSON.stringify({ selectedVendors: [], selectedStatuses: ['on_shelves'] }),
        );

        const wrapper = mount(PurchaseOrdersPage);
        await flush();
        await nextTick();

        const getMock = api.get as unknown as ReturnType<typeof vi.fn>;
        getMock.mockClear();

        const resetButton = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Reset filters');
        expect(resetButton).toBeDefined();
        await resetButton!.trigger('click');
        await flush();
        await nextTick();

        const poListCall = getMock.mock.calls.find((call) => call[0] === '/api/v1/purchase-orders');
        expect(poListCall?.[1]?.params?.statuses).toEqual([
            'draft',
            'ordered',
            'shipped',
            'received',
        ]);
    });

    it('shows plain tracking numbers while provider resolution is queued', async () => {
        const getMock = api.get as unknown as ReturnType<typeof vi.fn>;
        getMock.mockImplementation(async (url: string) => {
            if (url.endsWith('/filter-options')) {
                return { status: 200, data: { data: { vendors: [] } } };
            }
            if (url === '/api/v1/purchase-orders') {
                return {
                    status: 200,
                    data: {
                        ...emptyPaginated,
                        data: [
                            {
                                id: 'po-tracking-test',
                                status: 'shipped',
                                shipment_method: 'air',
                                shipment_tracking_numbers: ['1Z999AA10123456784', 'RR123456789CN'],
                                vendor: 'Test vendor',
                                supplier_order_id: null,
                                vendor_currency_code: 'CAD',
                                vendor_product_total: null,
                                vendor_shipping_total: null,
                                fx_rate_to_cad: null,
                                ordered_date: null,
                                shipped_date: null,
                                estimated_arrival_date: null,
                                received_date: null,
                                fully_on_shelves_date: null,
                                shipping_total: null,
                                surcharge_total: null,
                                product_total: null,
                                notes: null,
                                counts: { items: 0 },
                                created_at: null,
                            },
                        ],
                    },
                };
            }
            throw new Error(`Unexpected GET ${url}`);
        });
        const postMock = api.post as unknown as ReturnType<typeof vi.fn>;
        postMock.mockResolvedValue({
            status: 200,
            data: {
                data: [
                    {
                        tracking_number: '1Z999AA10123456784',
                        status: 'queued',
                        provider: null,
                        tracking_url: null,
                        retry_after: null,
                    },
                    {
                        tracking_number: 'RR123456789CN',
                        status: 'queued',
                        provider: null,
                        tracking_url: null,
                        retry_after: null,
                    },
                ],
            },
        });

        const wrapper = mount(PurchaseOrdersPage);
        await flush();
        await nextTick();

        const firstTracking = wrapper.get('[data-testid="po-history-tracking-po-tracking-test-0"]');
        expect(firstTracking.text()).toContain('1Z999AA10123456784');
        expect(firstTracking.attributes('href')).toBeUndefined();
        expect(firstTracking.find('[data-testid="tracking-resolution-spinner"]').exists()).toBe(
            true,
        );
    });

    it('links only to the provider that successfully resolved the tracking number', async () => {
        const getMock = api.get as unknown as ReturnType<typeof vi.fn>;
        getMock.mockImplementation(async (url: string) => {
            if (url.endsWith('/filter-options')) {
                return { status: 200, data: { data: { vendors: [] } } };
            }
            if (url === '/api/v1/purchase-orders') {
                return {
                    status: 200,
                    data: {
                        ...emptyPaginated,
                        data: [
                            {
                                id: 'po-resolved-tracking',
                                status: 'shipped',
                                shipment_method: 'air',
                                shipment_tracking_numbers: ['520704842993'],
                                vendor: 'Test vendor',
                                supplier_order_id: null,
                                vendor_currency_code: 'CAD',
                                vendor_product_total: null,
                                vendor_shipping_total: null,
                                fx_rate_to_cad: null,
                                ordered_date: null,
                                shipped_date: null,
                                estimated_arrival_date: null,
                                received_date: null,
                                fully_on_shelves_date: null,
                                shipping_total: null,
                                surcharge_total: null,
                                product_total: null,
                                notes: null,
                                counts: { items: 0 },
                                created_at: null,
                            },
                        ],
                    },
                };
            }
            throw new Error(`Unexpected GET ${url}`);
        });
        const postMock = api.post as unknown as ReturnType<typeof vi.fn>;
        postMock.mockResolvedValue({
            status: 200,
            data: {
                data: [
                    {
                        tracking_number: '520704842993',
                        status: 'resolved',
                        provider: 'kuaidi100',
                        tracking_url: 'https://www.kuaidi100.com/?nu=520704842993',
                        retry_after: null,
                    },
                ],
            },
        });

        const wrapper = mount(PurchaseOrdersPage);
        await flush();
        await nextTick();

        const tracking = wrapper.get('[data-testid="po-history-tracking-po-resolved-tracking-0"]');
        const link = tracking.get('a');
        expect(link.attributes('href')).toBe('https://www.kuaidi100.com/?nu=520704842993');
        expect(link.attributes('target')).toBe('_blank');
        expect(link.text()).toContain('520704842993');
    });
});
