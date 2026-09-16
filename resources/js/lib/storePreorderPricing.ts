import { parseMoney } from './money';

/** Same rounding as `PlamodRestockCostCalculator::newLandedBreakdown`. */
export function estimatedLandedFromCost(
    productCost: string | number | null | undefined,
    shippingPercent: number,
): string | null {
    const product = parseMoney(productCost);
    if (product === null || product <= 0) {
        return null;
    }

    const percent = Number.isFinite(shippingPercent) ? Math.max(0, shippingPercent) : 5;
    const shipping = Math.round(product * (percent / 100) * 100) / 100;
    const landed = Math.round((product + shipping) * 100) / 100;

    return landed.toFixed(2);
}

/** Sell $ ÷ estimated landed, two decimals — same as PO set-prices Mult. */
export function multiplierFromSellAndLanded(
    sellPrice: string | number | null | undefined,
    landed: string | null | undefined,
): string | null {
    const sell = parseMoney(sellPrice);
    const cost = parseMoney(landed);
    if (sell === null || cost === null || sell <= 0 || cost <= 0) {
        return null;
    }

    return (sell / cost).toFixed(2);
}

export function formatShippingPercentLabel(shippingPercent: number): string {
    if (!Number.isFinite(shippingPercent)) {
        return '5%';
    }

    const rounded = Math.round(shippingPercent * 100) / 100;
    const label = Number.isInteger(rounded)
        ? String(rounded)
        : rounded.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');

    return `${label}%`;
}
