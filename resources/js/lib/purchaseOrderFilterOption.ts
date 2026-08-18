import type { MultiSelectOption } from '../components/ui/MultiSelectFilter.vue';
import { formatLocalDate, formatLocalDateTime } from './datetime';

export type PurchaseOrderFilterSource = {
    id: string;
    vendor: string;
    created_at: string | null;
    ordered_date?: string | null;
    estimated_arrival_date?: string | null;
    received_date: string | null;
    counts: { items: number };
};

export function purchaseOrderPrimaryLabel(po: PurchaseOrderFilterSource): string {
    const short = po.id.slice(0, 8);

    return `${po.vendor} · ${po.counts.items} items · ${short}`;
}

export function purchaseOrderSecondaryLabel(po: PurchaseOrderFilterSource): string {
    const received = po.received_date?.trim();
    if (received) {
        return `Received ${formatLocalDate(received)}`;
    }

    const parts: string[] = ['Not arrived'];
    const eta = po.estimated_arrival_date?.trim();
    if (eta) {
        parts.push(`ETA ${formatLocalDate(eta)}`);
    }
    const ordered = po.ordered_date?.trim();
    if (ordered) {
        parts.push(`ordered ${formatLocalDate(ordered)}`);
    }
    if (!eta && !ordered) {
        const created = po.created_at ? formatLocalDateTime(po.created_at) : '—';
        parts.push(`created ${created}`);
    }

    return parts.join(' · ');
}

export function purchaseOrderFilterMultiSelectOption(po: PurchaseOrderFilterSource): MultiSelectOption {
    return {
        value: po.id,
        label: purchaseOrderPrimaryLabel(po),
        subLabel: purchaseOrderSecondaryLabel(po),
        muted: !po.received_date?.trim(),
    };
}
