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

const samplePo = {
    id: '3f48fe7c-4822-46c0-a81c-4e73ea8db975',
    status: 'ordered' as const,
    shipment_method: 'air' as const,
    vendor: 'Plamod',
    supplier_order_id: '12345',
    ordered_date: '2026-08-04',
    shipped_date: null,
    estimated_arrival_date: '2026-08-20',
    received_date: null,
    fully_on_shelves_date: null,
    shipping_total: null,
    surcharge_total: '0.00',
    product_total: '2728.12',
    notes: null,
    counts: { items: 44 },
    created_at: '2026-08-04T12:00:00-04:00',
};

describe('PurchaseOrdersPage delete', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.restoreAllMocks();

        const getMock = api.get as unknown as ReturnType<typeof vi.fn>;
        getMock.mockImplementation(async (url: string) => {
            if (url === '/api/v1/purchase-orders/filter-options') {
                return { status: 200, data: { data: { vendors: ['Plamod'] } } };
            }
            if (url === '/api/v1/products/filter-options') {
                return { status: 200, data: { data: { vendors: [] } } };
            }
            if (url === '/api/v1/purchase-orders') {
                return {
                    status: 200,
                    data: {
                        data: [samplePo],
                        meta: {
                            current_page: 1,
                            last_page: 1,
                            per_page: 50,
                            total: 1,
                        },
                    },
                };
            }
            throw new Error(`Unexpected GET ${url}`);
        });
    });

    it('opens confirm dialog before deleting a purchase order', async () => {
        const wrapper = mount(PurchaseOrdersPage);
        await flush();
        await nextTick();

        await wrapper.get('[data-testid="po-history-delete"]').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Delete purchase order');
        expect(document.body.textContent).toContain('Plamod');
        expect(document.body.textContent).toContain('supplier order 12345');

        const deleteMock = api.delete as unknown as ReturnType<typeof vi.fn>;
        expect(deleteMock).not.toHaveBeenCalled();
    });

    it('deletes purchase order after confirmation and reloads history', async () => {
        const deleteMock = api.delete as unknown as ReturnType<typeof vi.fn>;
        deleteMock.mockResolvedValue({ status: 200, data: { message: 'Deleted.' } });

        const wrapper = mount(PurchaseOrdersPage);
        await flush();
        await nextTick();

        await wrapper.get('[data-testid="po-history-delete"]').trigger('click');
        await nextTick();

        const confirmButton = Array.from(document.querySelectorAll('button')).find(
            (button) => button.textContent?.trim() === 'Delete',
        );
        expect(confirmButton).toBeDefined();
        confirmButton!.click();
        await flush();
        await nextTick();

        expect(deleteMock).toHaveBeenCalledWith(
            `/api/v1/purchase-orders/${samplePo.id}`,
            expect.objectContaining({ validateStatus: expect.any(Function) }),
        );

        const getMock = api.get as unknown as ReturnType<typeof vi.fn>;
        const poListCalls = getMock.mock.calls.filter(
            (call) => call[0] === '/api/v1/purchase-orders',
        );
        expect(poListCalls.length).toBeGreaterThanOrEqual(2);
    });

    it('shows server error when delete is blocked', async () => {
        const deleteMock = api.delete as unknown as ReturnType<typeof vi.fn>;
        deleteMock.mockResolvedValue({
            status: 422,
            data: {
                message:
                    'Cannot delete a purchase order that has received inventory/lots. This would corrupt inventory history.',
            },
        });

        const wrapper = mount(PurchaseOrdersPage);
        await flush();
        await nextTick();

        await wrapper.get('[data-testid="po-history-delete"]').trigger('click');
        await nextTick();

        const confirmButton = Array.from(document.querySelectorAll('button')).find(
            (button) => button.textContent?.trim() === 'Delete',
        );
        confirmButton!.click();
        await flush();
        await nextTick();

        expect(document.body.textContent).toContain('received inventory/lots');
    });
});
