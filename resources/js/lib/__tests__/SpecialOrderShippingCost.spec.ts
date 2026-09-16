import { describe, expect, it } from 'vitest';

import {
    shippingCostAmountFromWeightKg,
    shouldSeedActualShippingFromQuote,
    syncShippingCostAmountFromWeight,
} from '../SpecialOrderShippingCost';

describe('SpecialOrderShippingCost', () => {
    it('keeps the current amount when switching to weight before kg is entered', () => {
        expect(
            syncShippingCostAmountFromWeight({
                mode: 'weight',
                weightKg: '',
                ratePerKgCny: '29.00',
                currentAmount: '100.00',
            }),
        ).toEqual({ amount: '100.00', currency: 'CNY' });
    });

    it('computes RMB when type=number v-model supplies a numeric kg', () => {
        expect(shippingCostAmountFromWeightKg(2, 29)).toBe('58.00');
        expect(
            syncShippingCostAmountFromWeight({
                mode: 'weight',
                weightKg: 2,
                ratePerKgCny: 29,
                currentAmount: 100,
            }),
        ).toEqual({ amount: '58.00', currency: 'CNY' });
    });

    it('does not reseed reconciliation shipping after the operator chooses weight', () => {
        expect(
            shouldSeedActualShippingFromQuote({
                actualMode: 'weight',
                actualAmount: '',
                actualWeightKg: '',
                quoteMode: 'amount',
                quoteAmount: '100.00',
                quoteWeightKg: '',
            }),
        ).toBe(false);
    });
});
