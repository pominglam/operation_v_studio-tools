import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, describe, expect, it } from 'vitest';

import PoCombinedPaymentDialog, {
    type PoCombinedPaymentPreview,
} from '../PoCombinedPaymentDialog.vue';

const preview: PoCombinedPaymentPreview = {
    id: null,
    vendor_currency_code: 'HKD',
    vendor_total: '1650.00',
    total_paid_cad: '300.00',
    fx_rate_to_cad: '0.181818',
    includes_shipping: true,
    allocations: [
        {
            purchase_order_id: '11111111-1111-4111-8111-111111111111',
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
            purchase_order_id: '22222222-2222-4222-8222-222222222222',
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
};

describe('PoCombinedPaymentDialog', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('requests a preview with the CAD total and shipping choice', async () => {
        const wrapper = mount(PoCombinedPaymentDialog, {
            props: {
                open: true,
                busy: false,
                selectedCount: 2,
                preview: null,
                error: null,
            },
        });

        const total = document.body.querySelector<HTMLInputElement>(
            '[data-testid="combined-payment-total"]',
        )!;
        const includesShipping = document.body.querySelector<HTMLInputElement>(
            '[data-testid="combined-payment-includes-shipping"]',
        )!;
        total.value = '300.00';
        total.dispatchEvent(new Event('input', { bubbles: true }));
        includesShipping.checked = true;
        includesShipping.dispatchEvent(new Event('change', { bubbles: true }));
        await nextTick();
        document.body
            .querySelector<HTMLButtonElement>('[data-testid="combined-payment-preview"]')!
            .click();

        expect(wrapper.emitted('preview')).toEqual([
            [{ total_paid_cad: '300.00', includes_shipping: true }],
        ]);
    });

    it('calculates total paid from combined product and shipping amounts', async () => {
        const wrapper = mount(PoCombinedPaymentDialog, {
            props: {
                open: true,
                busy: false,
                selectedCount: 2,
                preview: null,
                error: null,
            },
        });

        const splitMode = document.body.querySelector<HTMLInputElement>(
            '[data-testid="combined-payment-amount-mode-split"]',
        )!;
        splitMode.checked = true;
        splitMode.dispatchEvent(new Event('change', { bubbles: true }));
        await nextTick();

        for (const [testId, value] of [
            ['combined-payment-product-paid', '240.00'],
            ['combined-payment-shipping-paid', '60.00'],
        ]) {
            const input = document.body.querySelector<HTMLInputElement>(
                `[data-testid="${testId}"]`,
            )!;
            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
        await nextTick();

        expect(document.body.textContent).toContain('Calculated total: 300.00 CAD');
        document.body
            .querySelector<HTMLButtonElement>('[data-testid="combined-payment-preview"]')!
            .click();

        expect(wrapper.emitted('preview')).toEqual([
            [
                {
                    total_paid_cad: '300.00',
                    product_paid_cad: '240.00',
                    shipping_paid_cad: '60.00',
                    includes_shipping: true,
                },
            ],
        ]);
    });

    it('shows per-shipment HKD and allocated CAD totals before confirmation', async () => {
        const wrapper = mount(PoCombinedPaymentDialog, {
            props: {
                open: true,
                busy: false,
                selectedCount: 2,
                preview,
                error: null,
            },
        });
        await nextTick();

        expect(document.body.textContent).toContain('Air');
        expect(document.body.textContent).toContain('Sea');
        expect(document.body.textContent).toContain('1000.00');
        expect(document.body.textContent).toContain('181.82');
        expect(document.body.textContent).toContain('18.18');

        const confirm = document.body.querySelector<HTMLButtonElement>(
            '[data-testid="combined-payment-confirm"]',
        );
        expect(confirm?.disabled).toBe(false);
        confirm?.click();
        expect(wrapper.emitted('confirm')).toHaveLength(1);
    });

    it('emits exact per-PO CAD amounts when manual allocation reconciles', async () => {
        const wrapper = mount(PoCombinedPaymentDialog, {
            props: {
                open: true,
                busy: false,
                selectedCount: 2,
                preview,
                error: null,
            },
        });
        await nextTick();

        const manual = document.body.querySelector<HTMLInputElement>(
            '[data-testid="combined-payment-manual-allocation"]',
        )!;
        manual.checked = true;
        manual.dispatchEvent(new Event('change', { bubbles: true }));
        await nextTick();

        const productInputs = Array.from(
            document.body.querySelectorAll<HTMLInputElement>(
                '[data-testid="combined-payment-product-cad"]',
            ),
        );
        const shippingInputs = Array.from(
            document.body.querySelectorAll<HTMLInputElement>(
                '[data-testid="combined-payment-shipping-cad"]',
            ),
        );
        for (const [input, value] of productInputs.map((input, index) => [
            input,
            ['150.00', '100.00'][index],
        ]) as Array<[HTMLInputElement, string]>) {
            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
        for (const [input, value] of shippingInputs.map((input, index) => [
            input,
            ['30.00', '20.00'][index],
        ]) as Array<[HTMLInputElement, string]>) {
            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
        await nextTick();

        expect(document.body.textContent).toContain('Remaining: 0.00 CAD');
        const confirm = document.body.querySelector<HTMLButtonElement>(
            '[data-testid="combined-payment-confirm"]',
        )!;
        expect(confirm.disabled).toBe(false);
        confirm.click();

        expect(wrapper.emitted('confirm')).toEqual([
            [
                {
                    total_paid_cad: '300.00',
                    includes_shipping: true,
                    allocations: [
                        {
                            purchase_order_id: preview.allocations[0].purchase_order_id,
                            product_total_cad: '150.00',
                            shipping_total_cad: '30.00',
                        },
                        {
                            purchase_order_id: preview.allocations[1].purchase_order_id,
                            product_total_cad: '100.00',
                            shipping_total_cad: '20.00',
                        },
                    ],
                },
            ],
        ]);
    });
});
