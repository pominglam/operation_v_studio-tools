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

    it('links a listed purchase order tracking number to 17TRACK', async () => {
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

        const wrapper = mount(PurchaseOrdersPage);
        await flush();
        await nextTick();

        const firstLink = wrapper.get('[data-testid="po-history-tracking-po-tracking-test-0"]');
        expect(firstLink.text()).toContain('1Z999AA10123456784');
        expect(firstLink.attributes('href')).toBe(
            'https://t.17track.net/en#nums=1Z999AA10123456784',
        );
        expect(firstLink.attributes('target')).toBe('_blank');

        const secondLink = wrapper.get('[data-testid="po-history-tracking-po-tracking-test-1"]');
        expect(secondLink.text()).toContain('RR123456789CN');
        expect(secondLink.attributes('href')).toBe('https://t.17track.net/en#nums=RR123456789CN');
    });
});
