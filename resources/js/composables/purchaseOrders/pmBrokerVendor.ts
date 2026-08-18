export const PM_BROKER_VENDORS = ['Dspiae', 'Stedi', 'Other/multi'] as const;

export function isPmBrokerVendor(value: string): boolean {
    const normalized = value.trim().toLowerCase();
    return PM_BROKER_VENDORS.some((vendor) => vendor.toLowerCase() === normalized);
}

export function isOtherMultiVendor(value: string): boolean {
    return value.trim().toLowerCase() === 'other/multi';
}
