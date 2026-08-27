import { parseMoney } from './money';

export type PoItemSortKey =
    | 'sku'
    | 'product_name'
    | 'vendor'
    | 'unit_cost'
    | 'ship_per_unit'
    | 'surcharge_per_unit'
    | 'landed'
    | 'available'
    | 'maintain'
    | 'not_arrived'
    | 'reorder'
    | 'total_ordered'
    | 'total_sold'
    | 'selling_price'
    | 'latest_landed_unit_cost'
    | 'multiplier'
    | 'qty_ordered'
    | 'qty_shipped'
    | 'qty_received'
    | 'qty_damaged';

export type PoItemSortRow = {
    sku: string;
    product_name: string | null;
    vendor: string;
    product_vendor?: string | null;
    unit_cost: string | null;
    available: number | null;
    maintain: number | null;
    not_arrived: number | null;
    reorder: number | null;
    total_ordered: number | null;
    total_sold: number | null;
    selling_price: string | null;
    latest_landed_unit_cost: string | null;
    multiplier: string | null;
    qty_ordered: number | null;
    qty_shipped: number | null;
    qty_received: number | null;
    qty_damaged: number;
};

function compareNullableNumber(a: number | null, b: number | null): number {
    if (a === null && b === null) return 0;
    if (a === null) return 1;
    if (b === null) return -1;
    if (a === b) return 0;
    return a < b ? -1 : 1;
}

function compareNullableString(a: string | null | undefined, b: string | null | undefined): number {
    const as = (a ?? '').trim().toLowerCase();
    const bs = (b ?? '').trim().toLowerCase();
    if (as === '' && bs === '') return 0;
    if (as === '') return 1;
    if (bs === '') return -1;
    return as.localeCompare(bs);
}

function compareMoney(a: string | null, b: string | null): number {
    return compareNullableNumber(parseMoney(a), parseMoney(b));
}

function landedUnitCostCents(
    unitCost: string | null,
    shipPerUnitCents: number | null,
    surchargePerUnitCents: number | null,
): number | null {
    const unit = parseMoney(unitCost);
    if (unit === null) return null;
    const ship = shipPerUnitCents === null ? 0 : shipPerUnitCents / 100;
    const surcharge = surchargePerUnitCents === null ? 0 : surchargePerUnitCents / 100;
    return unit + ship + surcharge;
}

export function comparePoItems(
    a: PoItemSortRow,
    b: PoItemSortRow,
    key: PoItemSortKey,
    shipPerUnitCents: number | null,
    surchargePerUnitCents: number | null,
): number {
    switch (key) {
        case 'sku':
            return compareNullableString(a.sku, b.sku);
        case 'product_name':
            return compareNullableString(a.product_name, b.product_name);
        case 'vendor':
            return compareNullableString(
                a.product_vendor ?? a.vendor,
                b.product_vendor ?? b.vendor,
            );
        case 'unit_cost':
            return compareMoney(a.unit_cost, b.unit_cost);
        case 'ship_per_unit':
            return compareNullableNumber(shipPerUnitCents, shipPerUnitCents);
        case 'surcharge_per_unit':
            return compareNullableNumber(surchargePerUnitCents, surchargePerUnitCents);
        case 'landed':
            return compareNullableNumber(
                landedUnitCostCents(a.unit_cost, shipPerUnitCents, surchargePerUnitCents),
                landedUnitCostCents(b.unit_cost, shipPerUnitCents, surchargePerUnitCents),
            );
        case 'available':
            return compareNullableNumber(a.available, b.available);
        case 'maintain':
            return compareNullableNumber(a.maintain, b.maintain);
        case 'not_arrived':
            return compareNullableNumber(a.not_arrived, b.not_arrived);
        case 'reorder':
            return compareNullableNumber(a.reorder, b.reorder);
        case 'total_ordered':
            return compareNullableNumber(a.total_ordered, b.total_ordered);
        case 'total_sold':
            return compareNullableNumber(a.total_sold, b.total_sold);
        case 'selling_price':
            return compareMoney(a.selling_price, b.selling_price);
        case 'latest_landed_unit_cost':
            return compareMoney(a.latest_landed_unit_cost, b.latest_landed_unit_cost);
        case 'multiplier':
            return compareMoney(a.multiplier, b.multiplier);
        case 'qty_ordered':
            return compareNullableNumber(a.qty_ordered, b.qty_ordered);
        case 'qty_shipped':
            return compareNullableNumber(a.qty_shipped, b.qty_shipped);
        case 'qty_received':
            return compareNullableNumber(a.qty_received, b.qty_received);
        case 'qty_damaged':
            return compareNullableNumber(a.qty_damaged, b.qty_damaged);
        default:
            return 0;
    }
}

export function sortPoItems<T extends PoItemSortRow>(
    items: T[],
    key: PoItemSortKey,
    dir: 'asc' | 'desc',
    shipPerUnitCents: number | null,
    surchargePerUnitCents: number | null,
): T[] {
    if (items.length <= 1) return items;
    const sign = dir === 'asc' ? 1 : -1;
    return [...items].sort(
        (left, right) =>
            comparePoItems(left, right, key, shipPerUnitCents, surchargePerUnitCents) * sign,
    );
}

export function poItemSortIndicator(
    sortBy: PoItemSortKey,
    sortDir: 'asc' | 'desc',
    key: PoItemSortKey,
): string {
    if (sortBy !== key) return '';
    return sortDir === 'asc' ? ' ▲' : ' ▼';
}

export function poItemSortHeaderClass(sortBy: PoItemSortKey, key: PoItemSortKey): string {
    return sortBy === key ? 'text-slate-900' : 'text-slate-600';
}
