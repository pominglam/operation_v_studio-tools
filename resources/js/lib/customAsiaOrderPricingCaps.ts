export const DEFAULT_MERCHANDISER_COMMISSION_CAP_CAD = '50.00';

export const DEFAULT_OPV_MARGIN_CAP_CAD = '150.00';

export type CustomAsiaOrderPricingCaps = {
    merchandiserCommissionCapCad: string;
    opvMarginCapCad: string;
};

export function defaultCustomAsiaOrderPricingCaps(): CustomAsiaOrderPricingCaps {
    return {
        merchandiserCommissionCapCad: DEFAULT_MERCHANDISER_COMMISSION_CAP_CAD,
        opvMarginCapCad: DEFAULT_OPV_MARGIN_CAP_CAD,
    };
}

export function applyPricingCap(amount: number, capCad: string | null | undefined): number {
    if (!Number.isFinite(amount)) {
        return 0;
    }

    const capRaw = capCad?.trim() ?? '';
    if (capRaw === '') {
        return Math.max(0, amount);
    }

    const cap = Number(capRaw);
    if (!Number.isFinite(cap) || cap < 0) {
        return Math.max(0, amount);
    }

    return Math.max(0, Math.min(amount, cap));
}
