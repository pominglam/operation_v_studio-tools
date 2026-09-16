import { api } from './api';
import {
    specialOrderWorkflowStatusLabel,
    resolveSpecialOrderWorkflowStatus,
    type SpecialOrderWorkflowStatus,
} from './specialOrderWorkflow';
import type { SpecialOrder } from '../types/specialOrders';

export type SpecialOrderListStatusActionId =
    | 'mark_considering'
    | 'clear_considering'
    | 'reject'
    | 'revive'
    | 'deposit_received'
    | 'merchandiser_ordered'
    | 'product_received'
    | 'balance_received';

export type SpecialOrderListStatusAction = {
    id: SpecialOrderListStatusActionId;
    label: string;
    confirmMessage?: string;
};

const ACTION_ENDPOINTS: Record<SpecialOrderListStatusActionId, string> = {
    mark_considering: 'mark-customer-considering',
    clear_considering: 'clear-customer-considering',
    reject: 'reject',
    revive: 'revive',
    deposit_received: 'deposit-received',
    merchandiser_ordered: 'merchandiser-ordered',
    product_received: 'product-received',
    balance_received: 'balance-received',
};

function pushAction(
    actions: SpecialOrderListStatusAction[],
    id: SpecialOrderListStatusActionId,
    targetStatus: SpecialOrderWorkflowStatus,
    confirmMessage?: string,
): void {
    actions.push({
        id,
        label: specialOrderWorkflowStatusLabel(targetStatus),
        confirmMessage,
    });
}

export function specialOrderListStatusActions(
    order: SpecialOrder,
): SpecialOrderListStatusAction[] {
    const current = resolveSpecialOrderWorkflowStatus(order);
    const actions: SpecialOrderListStatusAction[] = [];

    if (order.rejected_at) {
        if (current === 'rejected') {
            pushAction(actions, 'revive', 'priced');
        }

        return actions;
    }

    if (order.customer_considering_at) {
        if (current === 'considering') {
            const resumeStatus: SpecialOrderWorkflowStatus = order.offer_locked_at
                ? 'offer_locked'
                : 'priced';
            pushAction(actions, 'clear_considering', resumeStatus);
        }
    } else if (
        order.pricing_status === 'priced' &&
        order.deposit_received_at == null &&
        order.merchandiser_ordered_at == null &&
        (current === 'priced' || current === 'offer_locked')
    ) {
        pushAction(actions, 'mark_considering', 'considering');
    }

    if (current === 'offer_locked') {
        if (order.deposit_received_at == null) {
            pushAction(actions, 'deposit_received', 'deposit_in');
        }
        if (order.quote_status === 'quoted' && order.merchandiser_ordered_at == null) {
            pushAction(actions, 'merchandiser_ordered', 'ordered');
        }
    } else if (current === 'deposit_in' && order.merchandiser_ordered_at == null) {
        pushAction(actions, 'merchandiser_ordered', 'ordered');
    } else if (current === 'ordered' && order.product_received_at == null) {
        pushAction(actions, 'product_received', 'received');
    } else if (current === 'balance_paid' && order.product_received_at == null) {
        pushAction(actions, 'product_received', 'done');
    } else if (
        current === 'received' &&
        order.deposit_received_at &&
        order.balance_received_at == null
    ) {
        pushAction(actions, 'balance_received', 'done');
    }

    if (current !== 'rejected' && current !== 'balance_paid' && current !== 'done') {
        actions.push({
            id: 'reject',
            label: specialOrderWorkflowStatusLabel('rejected'),
            confirmMessage:
                'Reject this order? Pricing and notes are kept; you can revive it later if the customer returns.',
        });
    }

    return actions.filter((action) => action.label !== specialOrderWorkflowStatusLabel(current));
}

export async function applySpecialOrderListStatusAction(
    orderId: string,
    actionId: SpecialOrderListStatusActionId,
): Promise<SpecialOrder> {
    const res = await api.post<{ data: SpecialOrder }>(
        `/api/v1/special-orders/${orderId}/${ACTION_ENDPOINTS[actionId]}`,
    );

    return res.data.data;
}
