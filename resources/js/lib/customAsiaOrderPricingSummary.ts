export type PricingSummaryRow = {
    key: string;
    label: string;
    originalText: string | null;
    cadText: string | null;
    editable: 'merchandiser-commission' | 'opv-markup' | 'customer-price' | 'deposit' | null;
    tierControls?: 'merchandiser' | 'our-offer' | null;
};

export type BuildCustomAsiaPricingSummaryInput = {
    productCostAmount: string;
    productCostCurrency: string;
    productCostCurrencyLabel: string;
    productFxRateToCad: string | null;
    shippingCostAmount: string;
    shippingCostCurrency: string;
    shippingCostCurrencyLabel: string;
    shippingFxRateToCad: string | null;
    landedCostCad: string | null;
    merchandiserCommissionCad: string | null;
    opvMarkupCad: string | null;
    sellingPriceCad: string | null;
    depositCad: string | null;
    remainingCad: string | null;
};

function parsePositive(value: string | null | undefined): number | null {
    if (value === null || value === undefined) {
        return null;
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    const numeric = Number(trimmed);
    if (!Number.isFinite(numeric) || numeric < 0) {
        return null;
    }

    return numeric;
}

function formatCurrencyAmount(value: number, currencyLabel: string): string {
    return `${value.toFixed(2)} ${currencyLabel}`;
}

function cadFromForeignAmount(
    amount: string,
    currency: string,
    fxRateToCad: string | null,
): string | null {
    const numeric = parsePositive(amount);
    if (numeric === null) {
        return null;
    }

    if (currency === 'CAD') {
        return numeric.toFixed(2);
    }

    const fx = parsePositive(fxRateToCad);
    if (fx === null) {
        return null;
    }

    return (numeric * fx).toFixed(2);
}

function buildCostRow(
    key: string,
    label: string,
    amount: string,
    currency: string,
    currencyLabel: string,
    fxRateToCad: string | null,
): PricingSummaryRow {
    const numeric = parsePositive(amount);
    if (numeric === null) {
        return { key, label, originalText: null, cadText: null, editable: null };
    }

    const cad = cadFromForeignAmount(amount, currency, fxRateToCad);
    const originalText =
        currency === 'CAD'
            ? null
            : formatCurrencyAmount(numeric, currencyLabel || currency);

    return {
        key,
        label,
        originalText,
        cadText: cad !== null ? formatCurrencyAmount(Number(cad), 'CAD') : null,
        editable: null,
    };
}

function buildCadOnlyRow(
    key: string,
    label: string,
    cad: string | null,
    editable: PricingSummaryRow['editable'] = null,
    tierControls: PricingSummaryRow['tierControls'] = null,
): PricingSummaryRow {
    const cadValue = parsePositive(cad);
    if (cadValue === null) {
        return { key, label, originalText: null, cadText: null, editable, tierControls };
    }

    return {
        key,
        label,
        originalText: null,
        cadText: formatCurrencyAmount(cadValue, 'CAD'),
        editable,
        tierControls,
    };
}

export function buildCustomAsiaPricingSummary(
    input: BuildCustomAsiaPricingSummaryInput,
): PricingSummaryRow[] {
    return [
        buildCostRow(
            'source-cost',
            'Source cost',
            input.productCostAmount,
            input.productCostCurrency,
            input.productCostCurrencyLabel,
            input.productFxRateToCad,
        ),
        buildCostRow(
            'shipping-cost',
            'Shipping cost',
            input.shippingCostAmount,
            input.shippingCostCurrency,
            input.shippingCostCurrencyLabel,
            input.shippingFxRateToCad,
        ),
        buildCadOnlyRow('landed-cost', 'Landed cost', input.landedCostCad),
        buildCadOnlyRow(
            'merchandiser-commission',
            'Merchandiser commission',
            input.merchandiserCommissionCad,
            'merchandiser-commission',
            'merchandiser',
        ),
        buildCadOnlyRow('opv-markup', 'OPV markup', input.opvMarkupCad, 'opv-markup', 'our-offer'),
        buildCadOnlyRow('selling-price', 'Selling price', input.sellingPriceCad, 'customer-price'),
        buildCadOnlyRow('deposit', 'Deposit', input.depositCad, 'deposit'),
        buildCadOnlyRow('remaining', 'Remaining', input.remainingCad),
    ];
}

export function pricingSummaryHasValues(rows: PricingSummaryRow[]): boolean {
    return rows.some((row) => row.originalText !== null || row.cadText !== null);
}

export function pricingSummaryShowsOriginalColumn(rows: PricingSummaryRow[]): boolean {
    return rows.some((row) => row.originalText !== null);
}

export function formatPricingSummaryCell(value: string | null): string {
    if (value === null || value.trim() === '') {
        return '—';
    }

    return value;
}

