import { formatMoney2OrEmpty } from './money';
import { applyPricingCap } from './customAsiaOrderPricingCaps';

/**
 * Custom Asia order pricing (additive on landed CAD):
 *
 * - Source / shipping: operator input (foreign + FX → CAD)
 * - Landed = source CAD + shipping CAD
 * - Merchandiser commission = landed × (merchandiser multiplier − 1), or override CAD
 * - OPV spread = landed + merchandiser commission
 * - OPV margin = spread × (our multiplier − merchandiser multiplier), or override CAD
 * - Selling price = spread + OPV margin (= landed + merchandiser commission + OPV margin)
 *
 * Formula-derived commission and margin are capped by maintenance settings unless CAD override is set.
 */
export type CustomAsiaPricingMathInput = {
    landedCostCad: string | null;
    merchandiserMultiplier: string;
    ourPriceMultiplier: string;
    merchandiserPriceCad: string;
    customerPriceCad: string;
    formulaMerchandiserPriceCad: string | null | undefined;
    formulaOurPriceCad: string | null | undefined;
    merchandiserCommissionOverrideCad: string;
    opvMarginOverrideCad: string;
    merchandiserCommissionCapCad?: string | null;
    opvMarginCapCad?: string | null;
};

function parseAmount(value: string | null | undefined): number | null {
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

function parseMultiplier(value: string | null | undefined): number | null {
    const numeric = parseAmount(value);
    if (numeric === null || numeric <= 0) {
        return null;
    }

    return numeric;
}

function formatCadAmount(value: number): string {
    return value.toFixed(2);
}

export function resolveMerchandiserCommissionCad(input: CustomAsiaPricingMathInput): string | null {
    const override = input.merchandiserCommissionOverrideCad.trim();
    if (override !== '') {
        return formatMoney2OrEmpty(override) || null;
    }

    const landed = parseAmount(input.landedCostCad);
    if (landed === null) {
        return null;
    }

    const merchandiserMultiplier = parseMultiplier(input.merchandiserMultiplier);
    if (merchandiserMultiplier !== null && merchandiserMultiplier >= 1) {
        return formatCadAmount(
            applyPricingCap(
                landed * (merchandiserMultiplier - 1),
                input.merchandiserCommissionCapCad,
            ),
        );
    }

    const merchandiserPrice =
        parseAmount(input.merchandiserPriceCad) ??
        parseAmount(input.formulaMerchandiserPriceCad ?? null);
    if (merchandiserPrice !== null) {
        return formatCadAmount(
            applyPricingCap(
                Math.max(0, merchandiserPrice - landed),
                input.merchandiserCommissionCapCad,
            ),
        );
    }

    return null;
}

export function resolveOpvSpreadCad(input: CustomAsiaPricingMathInput): string | null {
    const landed = parseAmount(input.landedCostCad);
    const merchandiserCommission = parseAmount(resolveMerchandiserCommissionCad(input));
    if (landed === null || merchandiserCommission === null) {
        return null;
    }

    return formatCadAmount(landed + merchandiserCommission);
}

export function resolveOpvMarginCad(input: CustomAsiaPricingMathInput): string | null {
    const override = input.opvMarginOverrideCad.trim();
    if (override !== '') {
        return formatMoney2OrEmpty(override) || null;
    }

    const spread = parseAmount(resolveOpvSpreadCad(input));
    if (spread === null) {
        return null;
    }

    const ourMultiplier = parseMultiplier(input.ourPriceMultiplier);
    const merchandiserMultiplier = parseMultiplier(input.merchandiserMultiplier);
    if (
        ourMultiplier !== null &&
        merchandiserMultiplier !== null &&
        ourMultiplier >= merchandiserMultiplier
    ) {
        return formatCadAmount(
            applyPricingCap(
                spread * (ourMultiplier - merchandiserMultiplier),
                input.opvMarginCapCad,
            ),
        );
    }

    const customerPrice = parseAmount(input.customerPriceCad);
    if (customerPrice !== null) {
        return formatCadAmount(
            applyPricingCap(Math.max(0, customerPrice - spread), input.opvMarginCapCad),
        );
    }

    return null;
}

export function resolveSellingPriceCad(input: CustomAsiaPricingMathInput): string | null {
    const explicitCustomer = input.customerPriceCad.trim();
    if (explicitCustomer !== '') {
        return formatMoney2OrEmpty(explicitCustomer) || null;
    }

    const landed = parseAmount(input.landedCostCad);
    const merchandiserCommission = parseAmount(resolveMerchandiserCommissionCad(input));
    const opvMargin = parseAmount(resolveOpvMarginCad(input));
    if (landed === null || merchandiserCommission === null || opvMargin === null) {
        return null;
    }

    return formatCadAmount(landed + merchandiserCommission + opvMargin);
}

export function merchandiserCommissionFromMultiplier(
    landed: number,
    multiplier: number,
    capCad?: string | null,
): number {
    return applyPricingCap(Math.max(0, landed * (multiplier - 1)), capCad);
}

export function merchandiserMultiplierFromCommission(landed: number, commission: number): number {
    if (landed <= 0) {
        return 1;
    }

    return 1 + commission / landed;
}

export function opvMarginFromOurMultiplier(
    spread: number,
    ourMultiplier: number,
    merchandiserMultiplier: number,
    capCad?: string | null,
): number {
    return applyPricingCap(
        Math.max(0, spread * (ourMultiplier - merchandiserMultiplier)),
        capCad,
    );
}

export function ourMultiplierFromOpvMargin(
    spread: number,
    merchandiserMultiplier: number,
    margin: number,
): number {
    if (spread <= 0) {
        return merchandiserMultiplier;
    }

    return merchandiserMultiplier + margin / spread;
}

export function buildCustomAsiaPricingMathInput(
    partial: CustomAsiaPricingMathInput,
): CustomAsiaPricingMathInput {
    return partial;
}

export function syncPricesAfterMerchandiserCommissionChange(
    input: CustomAsiaPricingMathInput,
    commissionCad: number,
): { merchandiserPriceCad: string; customerPriceCad: string } {
    const landed = parseAmount(input.landedCostCad) ?? 0;
    const spread = landed + commissionCad;
    const overrideMargin = parseAmount(input.opvMarginOverrideCad);
    let opvMargin = overrideMargin;

    if (opvMargin === null) {
        const ourMultiplier = parseMultiplier(input.ourPriceMultiplier);
        const merchandiserMultiplier = parseMultiplier(input.merchandiserMultiplier);
        if (
            ourMultiplier !== null &&
            merchandiserMultiplier !== null &&
            ourMultiplier >= merchandiserMultiplier
        ) {
            opvMargin = applyPricingCap(
                spread * (ourMultiplier - merchandiserMultiplier),
                input.opvMarginCapCad,
            );
        } else {
            const customerPrice = parseAmount(input.customerPriceCad);
            const opvResolutionInput: CustomAsiaPricingMathInput = {
                ...input,
                merchandiserCommissionOverrideCad: '',
            };
            const priorMerchandiserCommission = parseAmount(
                resolveMerchandiserCommissionCad(opvResolutionInput),
            );
            const priorSpread =
                priorMerchandiserCommission === null ? null : landed + priorMerchandiserCommission;
            if (customerPrice !== null && priorSpread !== null) {
                opvMargin = Math.max(0, customerPrice - priorSpread);
            }
        }
    }

    const resolvedOpvMargin = opvMargin ?? 0;

    return {
        merchandiserPriceCad: formatCadAmount(spread),
        customerPriceCad: formatCadAmount(spread + resolvedOpvMargin),
    };
}

export function syncPricesAfterOpvMarginChange(
    input: CustomAsiaPricingMathInput,
    marginCad: number,
): { customerPriceCad: string } {
    const spread = parseAmount(resolveOpvSpreadCad(input)) ?? 0;

    return {
        customerPriceCad: formatCadAmount(spread + marginCad),
    };
}

export function opvMarginFromSellingPrice(spread: number, sellingPrice: number): number {
    return Math.max(0, sellingPrice - spread);
}

export function syncPricesAfterSellingPriceChange(
    input: CustomAsiaPricingMathInput,
    sellingPriceCad: number,
): {
    opvMarginOverrideCad: string;
    ourPriceMultiplier: string;
    customerPriceCad: string;
} {
    const spread = parseAmount(resolveOpvSpreadCad(input)) ?? 0;
    const margin = opvMarginFromSellingPrice(spread, sellingPriceCad);
    const merchandiserMultiplier = parseMultiplier(input.merchandiserMultiplier) ?? 1;
    const ourMultiplier = ourMultiplierFromOpvMargin(spread, merchandiserMultiplier, margin);

    return {
        opvMarginOverrideCad: formatCadAmount(margin),
        ourPriceMultiplier: ourMultiplier.toFixed(2),
        customerPriceCad: formatCadAmount(sellingPriceCad),
    };
}

export function syncPricesFromMultipliers(input: CustomAsiaPricingMathInput): {
    merchandiserPriceCad: string | null;
    customerPriceCad: string | null;
} {
    const landed = parseAmount(input.landedCostCad);
    if (landed === null) {
        return { merchandiserPriceCad: null, customerPriceCad: null };
    }

    const merchandiserCommission = parseAmount(
        resolveMerchandiserCommissionCad({
            ...input,
            merchandiserCommissionOverrideCad: '',
            opvMarginOverrideCad: '',
        }),
    );
    const opvMargin = parseAmount(
        resolveOpvMarginCad({
            ...input,
            merchandiserCommissionOverrideCad: '',
            opvMarginOverrideCad: '',
        }),
    );

    if (merchandiserCommission === null || opvMargin === null) {
        return { merchandiserPriceCad: null, customerPriceCad: null };
    }

    return {
        merchandiserPriceCad: formatCadAmount(landed + merchandiserCommission),
        customerPriceCad: formatCadAmount(landed + merchandiserCommission + opvMargin),
    };
}
