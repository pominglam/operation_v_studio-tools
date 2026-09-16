import type { SpecialOrder } from '../types/specialOrders';

export type SpecialOrderWorkflowStatus =
    | 'pending_quote'
    | 'quoted'
    | 'priced'
    | 'considering'
    | 'offer_locked'
    | 'deposit_in'
    | 'ordered'
    | 'received'
    | 'balance_paid'
    | 'done'
    | 'rejected';

type WorkflowOrder = Pick<
    SpecialOrder,
    | 'quote_status'
    | 'pricing_status'
    | 'offer_locked_at'
    | 'customer_considering_at'
    | 'deposit_received_at'
    | 'balance_received_at'
    | 'merchandiser_ordered_at'
    | 'product_received_at'
    | 'rejected_at'
>;

export function resolveSpecialOrderWorkflowStatus(
    row: WorkflowOrder,
): SpecialOrderWorkflowStatus {
    if (row.rejected_at) return 'rejected';
    if (row.balance_received_at && row.product_received_at) return 'done';
    if (row.balance_received_at) return 'balance_paid';
    if (row.product_received_at) return 'received';
    if (row.merchandiser_ordered_at) return 'ordered';
    if (row.deposit_received_at) return 'deposit_in';
    if (row.customer_considering_at) return 'considering';
    if (row.offer_locked_at) return 'offer_locked';
    if (row.pricing_status === 'priced') return 'priced';
    if (row.quote_status === 'quoted') return 'quoted';

    return 'pending_quote';
}

export function specialOrderWorkflowStatusLabel(status: SpecialOrderWorkflowStatus): string {
    switch (status) {
        case 'rejected':
            return 'Rejected';
        case 'balance_paid':
            return 'Paid in full';
        case 'done':
            return 'Done';
        case 'received':
            return 'In hand';
        case 'ordered':
            return 'Ordered';
        case 'deposit_in':
            return 'Deposit in';
        case 'considering':
            return 'Customer thinking';
        case 'offer_locked':
            return 'Offer locked';
        case 'priced':
            return 'Priced';
        case 'quoted':
            return 'Quoted';
        default:
            return 'Pending quote';
    }
}

export function specialOrderWorkflowStatusTailwindClass(
    status: SpecialOrderWorkflowStatus,
): string {
    switch (status) {
        case 'rejected':
            return 'bg-rose-100 text-rose-800';
        case 'balance_paid':
            return 'bg-green-100 text-green-900';
        case 'done':
            return 'bg-emerald-200 text-emerald-950';
        case 'received':
            return 'bg-teal-100 text-teal-900';
        case 'ordered':
            return 'bg-indigo-100 text-indigo-800';
        case 'deposit_in':
            return 'bg-violet-100 text-violet-800';
        case 'considering':
            return 'bg-orange-100 text-orange-900';
        case 'offer_locked':
            return 'bg-fuchsia-100 text-fuchsia-800';
        case 'priced':
            return 'bg-sky-100 text-sky-800';
        case 'quoted':
            return 'bg-emerald-100 text-emerald-800';
        default:
            return 'bg-amber-100 text-amber-800';
    }
}

export function specialOrderWorkflowStatusIsPending(
    status: SpecialOrderWorkflowStatus,
): boolean {
    return status === 'pending_quote' || status === 'quoted' || status === 'priced';
}

/** Main pipeline left → right; rejected sits off the happy path. */
export const SPECIAL_ORDER_WORKFLOW_TIMELINE: SpecialOrderWorkflowStatus[] = [
    'pending_quote',
    'quoted',
    'priced',
    'considering',
    'offer_locked',
    'deposit_in',
    'ordered',
    'received',
    'balance_paid',
    'done',
    'rejected',
];

/** Happy-path steps on the timeline row (excludes rejected — shown on the group row). */
export const SPECIAL_ORDER_WORKFLOW_PIPELINE: SpecialOrderWorkflowStatus[] =
    SPECIAL_ORDER_WORKFLOW_TIMELINE.filter((status) => status !== 'rejected');

/** Pre-deposit quote/pricing steps. */
export const SPECIAL_ORDER_WORKFLOW_QUOTING: SpecialOrderWorkflowStatus[] = [
    'pending_quote',
    'quoted',
    'priced',
    'considering',
    'offer_locked',
];

/** Fulfillment and payment steps after offer lock. */
export const SPECIAL_ORDER_WORKFLOW_PROCESSING: SpecialOrderWorkflowStatus[] = [
    'deposit_in',
    'ordered',
    'received',
    'balance_paid',
    'done',
];

export const SPECIAL_ORDER_WORKFLOW_REJECTED: SpecialOrderWorkflowStatus[] = ['rejected'];

export const SPECIAL_ORDER_WORKFLOW_DEFAULT_VISIBLE: SpecialOrderWorkflowStatus[] =
    SPECIAL_ORDER_WORKFLOW_TIMELINE.filter((s) => s !== 'considering' && s !== 'rejected');

export function specialOrderWorkflowStatusSetsEqual(
    a: SpecialOrderWorkflowStatus[],
    b: SpecialOrderWorkflowStatus[],
): boolean {
    if (a.length !== b.length) {
        return false;
    }

    const sortedA = [...a].sort();
    const sortedB = [...b].sort();

    return sortedA.every((value, index) => value === sortedB[index]);
}

export function specialOrderWorkflowTimelineShortLabel(
    status: SpecialOrderWorkflowStatus,
): string {
    switch (status) {
        case 'pending_quote':
            return 'Pending';
        case 'quoted':
            return 'Quoted';
        case 'priced':
            return 'Priced';
        case 'considering':
            return 'Thinking';
        case 'offer_locked':
            return 'Locked';
        case 'deposit_in':
            return 'Deposit';
        case 'ordered':
            return 'Ordered';
        case 'received':
            return 'In hand';
        case 'balance_paid':
            return 'Paid';
        case 'done':
            return 'Done';
        case 'rejected':
            return 'Rejected';
        default:
            return specialOrderWorkflowStatusLabel(status);
    }
}
