import type { CustomAsiaOrder } from '../types/customAsiaOrders';

export type CustomAsiaOrderWorkflowStatus =
    | 'pending_quote'
    | 'quoted'
    | 'priced'
    | 'offer_locked'
    | 'deposit_in'
    | 'ordered'
    | 'received'
    | 'rejected';

type WorkflowOrder = Pick<
    CustomAsiaOrder,
    | 'quote_status'
    | 'pricing_status'
    | 'offer_locked_at'
    | 'deposit_received_at'
    | 'merchandiser_ordered_at'
    | 'product_received_at'
    | 'rejected_at'
>;

export function resolveCustomAsiaOrderWorkflowStatus(
    row: WorkflowOrder,
): CustomAsiaOrderWorkflowStatus {
    if (row.rejected_at) return 'rejected';
    if (row.product_received_at) return 'received';
    if (row.merchandiser_ordered_at) return 'ordered';
    if (row.deposit_received_at) return 'deposit_in';
    if (row.offer_locked_at) return 'offer_locked';
    if (row.pricing_status === 'priced') return 'priced';
    if (row.quote_status === 'quoted') return 'quoted';

    return 'pending_quote';
}

export function customAsiaOrderWorkflowStatusLabel(status: CustomAsiaOrderWorkflowStatus): string {
    switch (status) {
        case 'rejected':
            return 'Rejected';
        case 'received':
            return 'Received';
        case 'ordered':
            return 'Ordered';
        case 'deposit_in':
            return 'Deposit in';
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

export function customAsiaOrderWorkflowStatusTailwindClass(status: CustomAsiaOrderWorkflowStatus): string {
    switch (status) {
        case 'rejected':
            return 'bg-rose-100 text-rose-800';
        case 'received':
            return 'bg-teal-100 text-teal-900';
        case 'ordered':
            return 'bg-indigo-100 text-indigo-800';
        case 'deposit_in':
            return 'bg-violet-100 text-violet-800';
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

export function customAsiaOrderWorkflowStatusIsPending(status: CustomAsiaOrderWorkflowStatus): boolean {
    return status === 'pending_quote' || status === 'quoted' || status === 'priced';
}
