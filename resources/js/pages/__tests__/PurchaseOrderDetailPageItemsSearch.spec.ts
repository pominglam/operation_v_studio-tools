import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { nextTick } from 'vue';

import PurchaseOrderDetailPage from '../PurchaseOrderDetailPage.vue';

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

function baseItem(id: number, sku: string, productName: string, barcode: string | null = null) {
    return {
        id,
        product_id: `p-${id}`,
        product_name: productName,
        product_barcode: barcode,
        product_handle: null,
        sku,
        vendor: 'Plamod',
        unit_cost: '1.00',
        vendor_unit_cost: null,
        qty_ordered: 1,
        qty_shipped: 1,
        qty_received: 1,
        available: 1,
        maintain: 1,
        not_arrived: 0,
        reorder: 0,
        total_ordered: 0,
        total_sold: 0,
        latest_landed_unit_cost: null,
        selling_price: null,
        multiplier: null,
    };
}

describe('PurchaseOrderDetailPage items search', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    it('filters rows by SKU, barcode, and product name', async () => {
        const poId = '00000000-0000-0000-0000-000000123456';

        const getMock = api.get as unknown as ReturnType<typeof vi.fn>;
        getMock.mockImplementation(async (url: string) => {
            if (url === `/api/v1/purchase-orders/${poId}`) {
                return {
                    status: 200,
                    data: {
                        data: {
                            id: poId,
                            vendor: 'Plamod',
                            supplier_order_id: null,
                            shipment_tracking_numbers: ['1Z999AA10123456784', 'RR123456789CN'],
                            vendor_currency_code: 'CAD',
                            ordered_date: null,
                            shipped_date: null,
                            estimated_arrival_date: null,
                            received_date: null,
                            fully_on_shelves_date: null,
                            shipping_total: null,
                            surcharge_total: null,
                            product_total: null,
                            vendor_product_total: null,
                            fx_rate_to_cad: null,
                            fx_rate_cad_to_vendor: null,
                            notes: null,
                            workflow_checklist: null,
                            status: 'draft',
                            counts: { items: 3 },
                            items: [
                                {
                                    ...baseItem(11, '5061290', 'MG ZETA GUNDAM Ver.Ka'),
                                    qty_ordered: 2,
                                },
                                {
                                    ...baseItem(
                                        12,
                                        '5058740',
                                        'HG GUNDAM AGE-1 Normal',
                                        '4573102558740',
                                    ),
                                    qty_ordered: 3,
                                },
                                {
                                    ...baseItem(13, '5072548', '30MM ARMORED CORE VI'),
                                    qty_ordered: 4,
                                },
                            ],
                            created_at: null,
                        },
                    },
                };
            }

            if (url === '/api/v1/inventory-check') {
                return {
                    status: 200,
                    data: { data: [], meta: { last_page: 1 } },
                };
            }

            throw new Error(`unexpected GET ${url}`);
        });

        const router = createRouter({
            history: createMemoryHistory(),
            routes: [
                {
                    path: '/purchase-orders/:id',
                    name: 'purchase-order-detail',
                    component: PurchaseOrderDetailPage,
                },
                {
                    path: '/products',
                    name: 'products',
                    component: { template: '<div />' },
                },
            ],
        });
        await router.push({ name: 'purchase-order-detail', params: { id: poId } });
        await router.isReady();

        const wrapper = mount(PurchaseOrderDetailPage, {
            global: {
                plugins: [router],
                stubs: {
                    BulkUpdatePoItemsDialog: true,
                    BulkRecrawlDialog: true,
                    BulkExportDialog: true,
                    ImportHandlesCard: true,
                    ImportInventoryQuantityOverrideCard: true,
                },
            },
        });

        await nextTick();
        await flush();
        await nextTick();

        expect(wrapper.text()).toContain('5061290');
        expect(wrapper.text()).toContain('5058740');
        expect(wrapper.text()).toContain('5072548');
        expect(wrapper.get('[data-testid="po-total-quantity"]').text()).toContain(
            'Total quantity: 9',
        );
        const firstTrackingLink = wrapper.get('[data-testid="po-tracking-link-0"]');
        expect(firstTrackingLink.text()).toBe('1Z999AA10123456784');
        expect(firstTrackingLink.attributes('href')).toBe(
            'https://t.17track.net/en#nums=1Z999AA10123456784',
        );
        const secondTrackingLink = wrapper.get('[data-testid="po-tracking-link-1"]');
        expect(secondTrackingLink.text()).toBe('RR123456789CN');
        expect(secondTrackingLink.attributes('href')).toBe(
            'https://t.17track.net/en#nums=RR123456789CN',
        );

        await wrapper.get('[data-testid="po-items-search"]').setValue('zeta');
        await nextTick();

        expect(wrapper.text()).toContain('5061290');
        expect(wrapper.text()).not.toContain('5058740');
        expect(wrapper.get('[data-testid="po-items-search-summary"]').text()).toContain(
            'Showing 1 of 3 items',
        );

        await wrapper.get('[data-testid="po-items-search"]').setValue('4573102558740');
        await nextTick();

        expect(wrapper.text()).toContain('5058740');
        expect(wrapper.text()).not.toContain('5061290');
    });
});
