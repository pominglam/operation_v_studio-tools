import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

import PoWorkflowSetPricesDialog, {
    type PoSetPricePreview,
} from '../PoWorkflowSetPricesDialog.vue';

function flush(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

describe('PoWorkflowSetPricesDialog', () => {
    function preview(): PoSetPricePreview {
        return {
            multiplier: '1.5',
            landed_cost_warning: null,
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
            landed_cost_warning: null,
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
            landed_cost_warning: null,
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
            landed_cost_warning: null,
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

    it('can explicitly apply a price update row suggested price', async () => {
        const wrapper = mountDialog({
            multiplier: '1.5',
            landed_cost_warning: null,
            new_prices: [],
            updates: [
                {
                    product_uuid: '00000000-0000-0000-0000-000000000005',
                    sku: 'SKU-UPDATE',
                    description: 'Update product',
                    is_new_on_po: false,
                    landed_unit_cost: '10.45',
                    current_price: '14.99',
                    current_multiplier: '1.43',
                    proposed_price: '15.99',
                    proposed_multiplier: '1.53',
                },
            ],
            unchanged: [],
            skipped_no_cost: [],
            apply_count: 1,
        });

        await wrapper
            .get('button[aria-label="Use suggested price for SKU-UPDATE"]')
            .trigger('click');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Apply prices')!
            .trigger('click');

        expect(wrapper.emitted('confirm')?.[0]?.[0]).toEqual({
            overrides: [
                {
                    product_uuid: '00000000-0000-0000-0000-000000000005',
                    price: '15.99',
                },
            ],
        });
    });

    it('can explicitly apply the unchanged row suggested price', async () => {
        const wrapper = mountDialog({
            multiplier: '1.5',
            landed_cost_warning: null,
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

        await wrapper.get('button[aria-label="Use suggested price for SKU-3"]').trigger('click');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Apply prices')!
            .trigger('click');

        expect(wrapper.emitted('confirm')?.[0]?.[0]).toEqual({
            overrides: [
                {
                    product_uuid: '00000000-0000-0000-0000-000000000003',
                    price: '34.99',
                },
            ],
        });
    });

    it('shows Use suggested when current and suggested prices are equal', async () => {
        const wrapper = mountDialog({
            multiplier: '1.5',
            landed_cost_warning: null,
            new_prices: [],
            updates: [],
            unchanged: [
                {
                    product_uuid: '00000000-0000-0000-0000-000000000004',
                    sku: 'SKU-EQUAL',
                    description: 'Already matches suggestion',
                    is_new_on_po: true,
                    landed_unit_cost: '29.46',
                    current_price: '43.99',
                    current_multiplier: '1.49',
                    proposed_price: '43.99',
                    proposed_multiplier: '1.49',
                    keep_reason: null,
                },
            ],
            skipped_no_cost: [],
            apply_count: 0,
        });

        await wrapper
            .get('button[aria-label="Use suggested price for SKU-EQUAL"]')
            .trigger('click');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Apply prices')!
            .trigger('click');

        expect(wrapper.emitted('confirm')?.[0]?.[0]).toEqual({
            overrides: [
                {
                    product_uuid: '00000000-0000-0000-0000-000000000004',
                    price: '43.99',
                },
            ],
        });
    });

    it('highlights displayed multipliers outside 1.45x through 1.60x in red', async () => {
        const pricePreview = preview();
        pricePreview.new_prices[0].proposed_multiplier = '1.61';
        const wrapper = mountDialog(pricePreview);

        const highMultiplier = wrapper.findAll('span').find((span) => span.text() === '1.61x');
        expect(highMultiplier?.classes()).toContain('bg-rose-100');
        expect(highMultiplier?.classes()).toContain('ring-rose-300');

        await wrapper.find('input[aria-label="Override price for SKU-1"]').setValue('28.80');

        const lowMultiplier = wrapper.findAll('span').find((span) => span.text() === '1.44x');
        expect(lowMultiplier?.classes()).toContain('bg-rose-100');
        expect(lowMultiplier?.classes()).toContain('ring-rose-300');
    });

    it('shows incomplete landed-cost warning and sticky table headers', () => {
        const pricePreview = preview();
        pricePreview.landed_cost_warning =
            'Shipping total is not entered. Landed currently equals PO unit cost plus any surcharge.';
        const wrapper = mountDialog(pricePreview);

        expect(wrapper.text()).toContain('Shipping total is not entered');
        expect(wrapper.find('thead').classes()).toContain('sticky');
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

    it('sorts unchanged rows by landed cost when the header is clicked', async () => {
        const wrapper = mountDialog({
            multiplier: '1.5',
            landed_cost_warning: null,
            new_prices: [],
            updates: [],
            unchanged: [
                {
                    product_uuid: '00000000-0000-0000-0000-000000000010',
                    sku: 'SKU-HIGH',
                    description: 'Higher landed',
                    is_new_on_po: false,
                    landed_unit_cost: '30.00',
                    current_price: '45.99',
                    current_multiplier: '1.53',
                    proposed_price: '44.99',
                    proposed_multiplier: '1.50',
                },
                {
                    product_uuid: '00000000-0000-0000-0000-000000000011',
                    sku: 'SKU-LOW',
                    description: 'Lower landed',
                    is_new_on_po: true,
                    landed_unit_cost: '10.00',
                    current_price: '15.99',
                    current_multiplier: '1.60',
                    proposed_price: '14.99',
                    proposed_multiplier: '1.50',
                },
            ],
            skipped_no_cost: [],
            apply_count: 0,
        });

        const unchangedSkuOrder = (): string[] =>
            wrapper
                .findAll('section')
                .find((section) => section.text().includes('No price change (2)'))!
                .findAll('tbody tr')
                .map((row) => row.findAll('td')[0]?.text()?.trim() ?? '');

        expect(unchangedSkuOrder()).toEqual(['SKU-HIGH', 'SKU-LOW']);

        await wrapper.get('[data-testid="po-set-prices-unchanged-landed"]').trigger('click');
        await nextTick();
        await flush();
        await nextTick();

        expect(wrapper.get('[data-testid="po-set-prices-unchanged-landed"]').text()).toContain('▲');
        expect(unchangedSkuOrder()).toEqual(['SKU-LOW', 'SKU-HIGH']);

        await wrapper.get('[data-testid="po-set-prices-unchanged-landed"]').trigger('click');
        await nextTick();
        await flush();
        await nextTick();

        expect(unchangedSkuOrder()).toEqual(['SKU-HIGH', 'SKU-LOW']);
        expect(wrapper.get('[data-testid="po-set-prices-unchanged-landed"]').text()).toContain('▼');
    });

    it('keeps row order stable while editing override until the sort header is clicked again', async () => {
        const wrapper = mountDialog({
            multiplier: '1.5',
            landed_cost_warning: null,
            new_prices: [],
            updates: [],
            unchanged: [
                {
                    product_uuid: '00000000-0000-0000-0000-000000000020',
                    sku: 'SKU-HIGH-MULT',
                    description: 'Higher multiplier',
                    is_new_on_po: false,
                    landed_unit_cost: '10.00',
                    current_price: '16.99',
                    current_multiplier: '1.70',
                    proposed_price: '14.99',
                    proposed_multiplier: '1.50',
                },
                {
                    product_uuid: '00000000-0000-0000-0000-000000000021',
                    sku: 'SKU-LOW-MULT',
                    description: 'Lower multiplier',
                    is_new_on_po: false,
                    landed_unit_cost: '10.00',
                    current_price: '12.99',
                    current_multiplier: '1.30',
                    proposed_price: '14.99',
                    proposed_multiplier: '1.50',
                },
            ],
            skipped_no_cost: [],
            apply_count: 0,
        });

        const unchangedSection = () =>
            wrapper
                .findAll('section')
                .find((section) => section.text().includes('No price change (2)'))!;

        const unchangedSkuOrder = (): string[] =>
            unchangedSection()
                .findAll('tbody tr')
                .map((row) => row.findAll('td')[0]?.text()?.trim() ?? '');

        const multSortButton = () =>
            unchangedSection()
                .findAll('button')
                .find((button) => button.text().trim().startsWith('Mult.'))!;

        await multSortButton().trigger('click');
        await nextTick();
        await flush();
        await nextTick();

        expect(unchangedSkuOrder()).toEqual(['SKU-LOW-MULT', 'SKU-HIGH-MULT']);

        await wrapper.find('input[aria-label="Override price for SKU-HIGH-MULT"]').setValue('9.99');
        await nextTick();

        expect(unchangedSkuOrder()).toEqual(['SKU-LOW-MULT', 'SKU-HIGH-MULT']);
    });
});
