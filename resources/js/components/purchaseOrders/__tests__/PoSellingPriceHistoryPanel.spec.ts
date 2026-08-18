import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PoSellingPriceHistoryPanel, {
    type PoSellingPriceHistoryEntry,
} from '../PoSellingPriceHistoryPanel.vue';

describe('PoSellingPriceHistoryPanel', () => {
    const entry: PoSellingPriceHistoryEntry = {
        id: 1,
        product_uuid: '00000000-0000-0000-0000-000000000001',
        sku: '5060401',
        description: '1/144 HGUC ∀ GUNDAM',
        previous_price: '5.99',
        new_price: '6.99',
        currency: 'CAD',
        source: 'po_workflow',
        created_at: '2026-08-12T17:00:00-04:00',
    };

    it('renders empty state when there are no entries', () => {
        const wrapper = mount(PoSellingPriceHistoryPanel, {
            props: {
                entries: [],
                loading: false,
                error: null,
            },
        });

        expect(wrapper.text()).toContain('No selling price changes recorded for this PO yet.');
    });

    it('renders price change rows with previous and new values', () => {
        const wrapper = mount(PoSellingPriceHistoryPanel, {
            props: {
                entries: [entry],
                loading: false,
                error: null,
            },
        });

        expect(wrapper.text()).toContain('5060401');
        expect(wrapper.text()).toContain('5.99 → 6.99');
        expect(wrapper.text()).toContain('po workflow');
    });

    it('sorts rows by sku when the SKU header is clicked', async () => {
        const wrapper = mount(PoSellingPriceHistoryPanel, {
            props: {
                entries: [
                    entry,
                    {
                        ...entry,
                        id: 2,
                        sku: '1000001',
                        description: 'Earlier sku',
                    },
                ],
                loading: false,
                error: null,
            },
        });

        await wrapper.get('[data-testid="po-selling-price-history-sort-sku"]').trigger('click');

        const rows = wrapper.findAll('tbody tr');
        expect(rows[0]?.text()).toContain('1000001');
        expect(rows[1]?.text()).toContain('5060401');
    });
});
