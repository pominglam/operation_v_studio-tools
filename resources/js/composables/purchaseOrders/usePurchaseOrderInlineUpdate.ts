import { api, extractApiError, normalizeCadMoneyInput } from '../../lib/api';
import { parseMoney } from '../../lib/money';
import {
    mergePurchaseOrderListRow,
    type PurchaseOrderListRow,
} from '../../types/purchaseOrderList';

export type PurchaseOrderInlineField =
    | 'shipment_method'
    | 'estimated_arrival_date'
    | 'received_date'
    | 'fully_on_shelves_date'
    | 'shipping_total'
    | 'surcharge_total';

export type PurchaseOrderInlinePatch = Partial<
    Pick<PurchaseOrderListRow, PurchaseOrderInlineField>
>;

export function emptyToNull(value: string): string | null {
    const trimmed = value.trim();
    return trimmed === '' ? null : trimmed;
}

export function moneyDraftEquals(current: string | null, draft: string): boolean {
    const nextRaw = normalizeCadMoneyInput(draft);
    if (nextRaw === '') {
        return current === null || current.trim() === '';
    }
    return parseMoney(current) === parseMoney(nextRaw);
}

export function parseMoneyDraft(draft: string): string | null {
    const nextRaw = normalizeCadMoneyInput(draft);
    if (nextRaw === '') {
        return null;
    }
    if (parseMoney(nextRaw) === null) {
        throw new Error('Enter a valid amount, or leave the field blank.');
    }
    return nextRaw;
}

export async function patchPurchaseOrderListRow(
    id: string,
    changes: PurchaseOrderInlinePatch,
): Promise<PurchaseOrderListRow> {
    const res = await api.patch<{ data: PurchaseOrderListRow }>(
        `/api/v1/purchase-orders/${id}`,
        changes,
    );
    return res.data.data;
}

export async function savePurchaseOrderInlineField(
    current: PurchaseOrderListRow,
    changes: PurchaseOrderInlinePatch,
): Promise<PurchaseOrderListRow> {
    try {
        const updated = await patchPurchaseOrderListRow(current.id, changes);
        return mergePurchaseOrderListRow(current, updated);
    } catch (err: unknown) {
        throw new Error(extractApiError(err) || 'Failed to save purchase order.');
    }
}
