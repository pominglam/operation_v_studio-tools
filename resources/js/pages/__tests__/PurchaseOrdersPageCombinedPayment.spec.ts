import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import PurchaseOrdersPage from '../PurchaseOrdersPage.vue';

vi.mock('../../lib/api', () => ({
    api: {
        get: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));

import { api } from '../../lib/api';

function flush(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

const airId = '11111111-1111-4111-8111-111111111111';
const seaId = '22222222-2222-4222-8222-222222222222';
const purchaseOrders = [
    {
        id: airId,
        status: 'ordered' as const,
        shipment_method: 'air' as const,
        vendor: 'Dspiae',
        supplier_order_id: 'ORDER-1',
        vendor_currency_code: 'HKD',
        vendor_product_total: '1000.00',
        vendor_shipping_total: '100.00',
        fx_rate_to_cad: null,
        ordered_date: '2026-08-01',
        shipped_date: null,
        estimated_arrival_date: null,
        received_date: null,
        fully_on_shelves_date: null,
        shipping_total: null,
        surcharge_total: '0.00',
        product_total: null,
        notes: null,
        counts: { items: 1 },
        created_at: '2026-08-01T12:00:00-04:00',
    },
    {
        id: seaId,
        status: 'ordered' as const,
        shipment_method: 'sea' as const,
        vendor: 'Dspiae',
        supplier_order_id: 'ORDER-1',
        vendor_currency_code: 'HKD',
        vendor_product_total: '500.00',
        vendor_shipping_total: '50.00',
        fx_rate_to_cad: null,
        ordered_date: '2026-08-01',
        shipped_date: null,
        estimated_arrival_date: null,
        received_date: null,
        fully_on_shelves_date: null,
        shipping_total: null,
        surcharge_total: '0.00',
        product_total: null,
        notes: null,
        counts: { items: 1 },
        created_at: '2026-08-01T12:00:00-04:00',
    },
];

const preview = {
    data: {
        id: null,
        vendor_currency_code: 'HKD',
        vendor_total: '1650.00',
        total_paid_cad: '300.00',
        fx_rate_to_cad: '0.181818',
        includes_shipping: true,
        allocations: [
            {
                purchase_order_id: airId,
                vendor: 'Dspiae',
                supplier_order_id: 'ORDER-1',
                shipment_method: 'air',
                vendor_product_total: '1000.00',
                vendor_shipping_total: '100.00',
                product_total_cad: '181.82',
                shipping_total_cad: '18.18',
                fx_rate_to_cad: '0.181818',
            },
            {
                purchase_order_id: seaId,
                vendor: 'Dspiae',
                supplier_order_id: 'ORDER-1',
                shipment_method: 'sea',
                vendor_product_total: '500.00',
                vendor_shipping_total: '50.00',
                product_total_cad: '90.91',
                shipping_total_cad: '9.09',
                fx_rate_to_cad: '0.181818',
            },
        ],
    },
};

describe('PurchaseOrdersPage combined payment', () => {
    beforeEach(() => {
        localStorage.clear();
        document.body.innerHTML = '';
        vi.clearAllMocks();

        vi.mocked(api.get).mockImplementation(async (url: string) => {
            if (url === '/api/v1/purchase-orders/filter-options') {
                return { status: 200, data: { data: { vendors: ['Dspiae'] } } };
            }
            if (url === '/api/v1/products/filter-options') {
                return { status: 200, data: { data: { vendors: [] } } };
            }
            if (url === '/api/v1/purchase-orders') {
                return {
                    status: 200,
                    data: {
                        data: purchaseOrders,
                        meta: { current_page: 1, last_page: 1, per_page: 50, total: 2 },
                    },
                };
            }
            throw new Error(`Unexpected GET ${url}`);
        });
    });

    it('selects two POs, previews allocation, and records the combined payment', async () => {
        vi.mocked(api.post)
            .mockResolvedValueOnce({ status: 200, data: preview })
            .mockResolvedValueOnce({
                status: 201,
                data: { data: { ...preview.data, id: '33333333-3333-4333-8333-333333333333' } },
            });

        const wrapper = mount(PurchaseOrdersPage);
        await flush();
        await nextTick();

        const selectors = wrapper.findAll('[data-testid="po-history-select"]');
        expect(selectors).toHaveLength(2);
        await selectors[0].setValue(true);
        await selectors[1].setValue(true);
        await wrapper.get('[data-testid="combined-payment-open"]').trigger('click');

        const total = document.body.querySelector<HTMLInputElement>(
            '[data-testid="combined-payment-total"]',
        );
        const includesShipping = document.body.querySelector<HTMLInputElement>(
            '[data-testid="combined-payment-includes-shipping"]',
        );
        const previewButton = document.body.querySelector<HTMLButtonElement>(
            '[data-testid="combined-payment-preview"]',
        );
        expect(total).not.toBeNull();
        total!.value = '300.00';
        total!.dispatchEvent(new Event('input', { bubbles: true }));
        includesShipping!.checked = true;
        includesShipping!.dispatchEvent(new Event('change', { bubbles: true }));
        await nextTick();
        previewButton!.click();
        await flush();
        await nextTick();

        expect(api.post).toHaveBeenNthCalledWith(
            1,
            '/api/v1/purchase-orders/combined-payments/preview',
            {
                purchase_order_ids: [airId, seaId],
                total_paid_cad: '300.00',
                includes_shipping: true,
            },
        );
        expect(document.body.textContent).toContain('181.82');

        document.body
            .querySelector<HTMLButtonElement>('[data-testid="combined-payment-confirm"]')!
            .click();
        await flush();
        await nextTick();

        expect(api.post).toHaveBeenNthCalledWith(2, '/api/v1/purchase-orders/combined-payments', {
            purchase_order_ids: [airId, seaId],
            total_paid_cad: '300.00',
            includes_shipping: true,
        });
        expect(wrapper.find('[data-testid="combined-payment-open"]').exists()).toBe(false);
    });
});
