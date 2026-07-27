import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';

import PoWorkflowPushInventoryDialog, {
    type PoPushInventoryPreview,
} from '../PoWorkflowPushInventoryDialog.vue';

describe('PoWorkflowPushInventoryDialog', () => {
    function preview(): PoPushInventoryPreview {
        return {
            location_gid: 'gid://shopify/Location/1',
            location_name: 'Main Store',
            write_products_scope_ok: true,
            write_inventory_scope_ok: true,
            write_publications_scope_ok: true,
            images_enabled: true,
            tunnel_url: 'https://example.test',
            products: [
                {
                    product_uuid: '00000000-0000-0000-0000-000000000001',
                    sku: 'SKU-1',
                    description: 'MGEX Strike Freedom',
                    product_type: 'MGEX',
                    type_rank: 1,
                    product_created_at: '2026-07-01T00:00:00Z',
                    handle: 'mgex-strike',
                    erp_available_qty: 2,
                    erp_hold_qty: 0,
                    shopify_push_qty: 2,
                    shopify_available_qty: 2,
                    selling_price: '238.99',
                    push_action: 'update',
                    push_eligible: true,
                    skip_reason: null,
                },
            ],
            push_count: 1,
            product_uuids: ['00000000-0000-0000-0000-000000000001'],
        };
    }

    function mountDialog(receivedDate: string | null = '2026-07-20') {
        return mount(PoWorkflowPushInventoryDialog, {
            props: {
                open: true,
                busy: false,
                preview: preview(),
                pushSummary: null,
                error: null,
                receivedDate,
            },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });
    }

    it('shows a warning and disables Push to Shopify when received date is missing', () => {
        const wrapper = mountDialog(null);

        expect(wrapper.get('[data-testid="push-inventory-missing-received-date"]').text()).toContain(
            'Received date',
        );
        expect(
            wrapper.get('[data-testid="push-inventory-confirm"]').attributes('disabled'),
        ).toBeDefined();
    });

    it('enables Push to Shopify when received date is set', () => {
        const wrapper = mountDialog('2026-07-20');

        expect(wrapper.find('[data-testid="push-inventory-missing-received-date"]').exists()).toBe(
            false,
        );
        expect(wrapper.get('[data-testid="push-inventory-confirm"]').attributes('disabled')).toBeUndefined();
    });

    it('emits confirm when received date is set and button is clicked', async () => {
        const wrapper = mountDialog('2026-07-20');

        await wrapper.get('[data-testid="push-inventory-confirm"]').trigger('click');

        expect(wrapper.emitted('confirm')).toHaveLength(1);
    });
});
