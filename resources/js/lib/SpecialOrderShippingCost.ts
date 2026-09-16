export type SpecialOrderShippingCostInputMode = 'amount' | 'weight';

function trimmedText(value: string | number | null | undefined): string {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value).trim();
}

export function normalizeShippingCostInputMode(
    mode: string | null | undefined,
): SpecialOrderShippingCostInputMode {
    return mode === 'weight' ? 'weight' : 'amount';
}

export function shippingCostAmountFromWeightKg(
    weightKg: string | number | null | undefined,
    ratePerKgCny: string | number | null | undefined,
): string | null {
    const weightRaw = trimmedText(weightKg);
    const rateRaw = trimmedText(ratePerKgCny);
    if (weightRaw === '' || rateRaw === '') {
        return null;
    }

    const weight = Number(weightRaw);
    const rate = Number(rateRaw);
    if (!Number.isFinite(weight) || weight < 0 || !Number.isFinite(rate) || rate < 0) {
        return null;
    }

    return (weight * rate).toFixed(2);
}

export function syncShippingCostAmountFromWeight(input: {
    mode: SpecialOrderShippingCostInputMode;
    weightKg: string | number;
    ratePerKgCny: string | number;
    currentAmount: string | number;
}): { amount: string; currency: 'CNY' } {
    const currentAmount = trimmedText(input.currentAmount);

    if (input.mode !== 'weight') {
        return { amount: currentAmount, currency: 'CNY' };
    }

    const computed = shippingCostAmountFromWeightKg(input.weightKg, input.ratePerKgCny);

    return {
        amount: computed ?? currentAmount,
        currency: 'CNY',
    };
}

export function shouldSeedActualShippingFromQuote(input: {
    actualMode: SpecialOrderShippingCostInputMode;
    actualAmount: string | number;
    actualWeightKg: string | number;
    quoteMode: SpecialOrderShippingCostInputMode;
    quoteAmount: string | number;
    quoteWeightKg: string | number;
}): boolean {
    if (input.actualMode === 'weight') {
        return false;
    }

    if (trimmedText(input.actualAmount) !== '' || trimmedText(input.actualWeightKg) !== '') {
        return false;
    }

    return (
        trimmedText(input.quoteAmount) !== '' ||
        (input.quoteMode === 'weight' && trimmedText(input.quoteWeightKg) !== '')
    );
}
