import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';

import PoWorkflowSetPricesDialog, {
    type PoSetPricePreview,
} from '../PoWorkflowSetPricesDialog.vue';

describe('PoWorkflowSetPricesDialog', () => {
    function preview(): PoSetPricePreview {
        return {
            multiplier: '1.5',
            new_prices: [
                {
                    product_uuid: '00000000-0000-0000-0000-000000000001',
                    sku: 'SKU-1',
                    description: 'Existing catalog product',
                    is_new_on_po: true,
                    landed_unit_cost: '20.00',
                    current_price: '27.99',
                    current_multiplier: '1.40',
                    proposed_price: '30.99',
                    proposed_multiplier: '1.55',
                },
            ],
            updates: [],
            unchanged: [],
            skipped_no_cost: [],
            apply_count: 1,
        };
    }

    function mountDialog(pricePreview = preview()) {
        const wrapper = mount(PoWorkflowSetPricesDialog, {
            props: {
                open: true,
                busy: false,
                preview: pricePreview,
                error: null,
            },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });

        return wrapper;
    }

    it('shows the current selling price and editable proposed price in the new prices table', () => {
        const wrapper = mountDialog();

        expect(wrapper.text()).toContain('$27.99');
        expect(wrapper.find('input[aria-label="Override price for SKU-1"]').element).toHaveProperty(
            'value',
            '30.99',
        );
    });

    it('emits changed manual price overrides on confirm', async () => {
        const wrapper = mountDialog();

        await wrapper.find('input[aria-label="Override price for SKU-1"]').setValue('32.49');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Apply prices')!
            .trigger('click');

        expect(wrapper.emitted('confirm')?.[0]?.[0]).toEqual({
            overrides: [
                {
                    product_uuid: '00000000-0000-0000-0000-000000000001',
                    price: '32.49',
                },
            ],
        });
    });

    it('recalculates the target multiplier from the manual price override', async () => {
        const wrapper = mountDialog({
            multiplier: '1.5',
            new_prices: [],
            updates: [
                {
                    product_uuid: '00000000-0000-0000-0000-000000000002',
                    sku: 'SKU-2',
                    description: 'Update product',
                    is_new_on_po: false,
                    landed_unit_cost: '20.00',
                    current_price: '27.99',
                    current_multiplier: '1.40',
                    proposed_price: '30.99',
                    proposed_multiplier: '1.55',
                },
            ],
            unchanged: [],
            skipped_no_cost: [],
            apply_count: 1,
        });

        expect(wrapper.text()).toContain('1.40x → 1.55x');

        await wrapper.find('input[aria-label="Override price for SKU-2"]').setValue('40.00');

        expect(wrapper.text()).toContain('1.40x → 2.00x');
    });

    it('shows the multiplier arrow for manual overrides even when the multiplier is unchanged', async () => {
        const wrapper = mountDialog({
            multiplier: '1.5',
            new_prices: [],
            updates: [
                {
                    product_uuid: '00000000-0000-0000-0000-000000000004',
                    sku: 'SKU-4',
                    description: 'Same multiplier product',
                    is_new_on_po: true,
                    landed_unit_cost: '613.28',
                    current_price: '850.00',
                    current_multiplier: '1.39',
                    proposed_price: '919.99',
                    proposed_multiplier: '1.50',
                },
            ],
            unchanged: [],
            skipped_no_cost: [],
            apply_count: 1,
        });

        await wrapper.find('input[aria-label="Override price for SKU-4"]').setValue('850');

        expect(wrapper.text()).toContain('1.39x → 1.39x');
    });

    it('shows unchanged section help only after clicking the info button', async () => {
        const wrapper = mountDialog({
            multiplier: '1.5',
            new_prices: [],
            updates: [],
            unchanged: [
                {
                    product_uuid: '00000000-0000-0000-0000-000000000003',
                    sku: 'SKU-3',
                    description: 'Kept current product',
                    is_new_on_po: false,
                    landed_unit_cost: '22.99',
                    current_price: '35.99',
                    current_multiplier: '1.57',
                    proposed_price: '34.99',
                    proposed_multiplier: '1.52',
                    keep_reason: 'current_higher_than_formula',
                },
            ],
            skipped_no_cost: [],
            apply_count: 0,
        });

        expect(wrapper.text()).toContain('No price change (1)');
        expect(wrapper.text()).not.toContain('Apply won');
        expect(wrapper.text()).not.toContain('formula $34.99');

        await wrapper
            .find('button[aria-label="Why can Current and Override differ?"]')
            .trigger('click');

        expect(wrapper.text()).toContain('Apply won');
        expect(wrapper.text()).toContain('Override shows the formula price');
    });

    it('blocks apply while a manual price override is invalid', async () => {
        const wrapper = mountDialog();

        await wrapper.find('input[aria-label="Override price for SKU-1"]').setValue('abc');

        expect(wrapper.text()).toContain('Use 0.00 format.');
        expect(
            wrapper
                .findAll('button')
                .find((button) => button.text() === 'Apply prices')!
                .attributes('disabled'),
        ).toBeDefined();
    });
});
