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

function baseItem(id: number, qtyOrdered: number, qtyReceived: number | null) {
    return {
        id,
        product_id: `p-${id}`,
        product_name: `P${id}`,
        product_barcode: null,
        product_handle: null,
        sku: `SKU-${id}`,
        vendor: 'Dspiae',
        unit_cost: '1.00',
        vendor_unit_cost: null,
        qty_ordered: qtyOrdered,
        qty_shipped: qtyOrdered,
        qty_received: qtyReceived,
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

describe('PurchaseOrderDetailPage qty received highlight', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    it('highlights qty received when it differs from qty ordered', async () => {
        const poId = '00000000-0000-0000-0000-000000123456';
        const matchingItemId = 11;
        const mismatchItemId = 12;

        const getMock = api.get as unknown as ReturnType<typeof vi.fn>;
        getMock.mockImplementation(async (url: string) => {
            if (url === `/api/v1/purchase-orders/${poId}`) {
                return {
                    status: 200,
                    data: {
                        data: {
                            id: poId,
                            vendor: 'Dspiae',
                            supplier_order_id: null,
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
                            counts: { items: 2 },
                            items: [
                                baseItem(matchingItemId, 6, 6),
                                baseItem(mismatchItemId, 4, 3),
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

        const matchingInput = wrapper.get(`[data-testid="qty-received-input-${matchingItemId}"]`);
        const mismatchInput = wrapper.get(`[data-testid="qty-received-input-${mismatchItemId}"]`);

        expect(matchingInput.classes()).not.toContain('bg-yellow-100');
        expect(mismatchInput.classes()).toContain('bg-yellow-100');
    });
});
