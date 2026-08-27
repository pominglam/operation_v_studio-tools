export type ReconciliationSummaryRow = {
    key: string;
    label: string;
    originalText: string | null;
    cadText: string | null;
    isPrimary?: boolean;
    isTotal?: boolean;
};

export type BuildCustomAsiaReconciliationSummaryInput = {
    productCostAmount: string;
    productCostCurrency: string;
    productCostCurrencyLabel: string;
    productFxRateToCad: string | null;
    shippingCostAmount: string;
    shippingCostCurrency: string;
    shippingCostCurrencyLabel: string;
    shippingFxRateToCad: string | null;
    landedCostCad: string | null;
    merchandiserMultiplier: string | null;
    merchandiserCommissionCad: string | null;
    payMerchandiserCad: string | null;
    customerPriceCad: string | null;
    opvMarginCad: string | null;
};

function parsePositive(value: string | null | undefined): number | null {
    if (value === null || value === undefined) {
        return null;
    }

    const trimmed = value.trim().replace(/^\$/, '').replace(/,/g, '');
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
): ReconciliationSummaryRow {
    const numeric = parsePositive(amount);
    if (numeric === null) {
        return { key, label, originalText: null, cadText: null };
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
    };
}

function buildCadOnlyRow(
    key: string,
    label: string,
    cad: string | null,
    options: { isPrimary?: boolean; isTotal?: boolean; originalText?: string | null } = {},
): ReconciliationSummaryRow {
    const cadValue = parsePositive(cad);
    if (cadValue === null) {
        return {
            key,
            label,
            originalText: options.originalText ?? null,
            cadText: null,
            isPrimary: options.isPrimary,
            isTotal: options.isTotal,
        };
    }

    return {
        key,
        label,
        originalText: options.originalText ?? null,
        cadText: formatCurrencyAmount(cadValue, 'CAD'),
        isPrimary: options.isPrimary,
        isTotal: options.isTotal,
    };
}

export function buildCustomAsiaReconciliationSummary(
    input: BuildCustomAsiaReconciliationSummaryInput,
): ReconciliationSummaryRow[] {
    const multiplier = parsePositive(input.merchandiserMultiplier);
    const commissionHint =
        multiplier !== null ? `${multiplier.toFixed(2)} × landed` : null;

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
        buildCadOnlyRow('merchandiser-commission', 'Merchandiser commission', input.merchandiserCommissionCad, {
            originalText: commissionHint,
        }),
        buildCadOnlyRow('pay-merchandiser', 'Pay merchandiser', input.payMerchandiserCad, {
            isPrimary: true,
        }),
        buildCadOnlyRow('customer-price', 'Customer price', input.customerPriceCad, {
            isTotal: true,
        }),
        buildCadOnlyRow('opv-margin', 'OPV margin', input.opvMarginCad),
    ];
}

export function reconciliationSummaryHasValues(rows: ReconciliationSummaryRow[]): boolean {
    return rows.some((row) => row.originalText !== null || row.cadText !== null);
}

export function reconciliationSummaryShowsOriginalColumn(rows: ReconciliationSummaryRow[]): boolean {
    return rows.some((row) => row.originalText !== null);
}

export function formatReconciliationSummaryCell(value: string | null): string {
    if (value === null || value.trim() === '') {
        return '—';
    }

    return value;
}
