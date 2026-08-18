<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import type { PlamodRestockPreorderShipment } from '../lib/plamodRestock';

const props = defineProps<{
    committedQty: number;
    shipments: PlamodRestockPreorderShipment[];
}>();

const open = ref(false);
const root = ref<HTMLElement | null>(null);

function toggle(): void {
    if (props.shipments.length === 0) {
        return;
    }
    open.value = !open.value;
}

function onDocumentClick(event: MouseEvent): void {
    if (!root.value?.contains(event.target as Node)) {
        open.value = false;
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        open.value = false;
    }
}

function shipmentLabel(shipment: PlamodRestockPreorderShipment): string {
    const eta = shipment.eta_label ?? shipment.eta_date ?? 'ETA unknown';
    const offer = shipment.offer_id ? `Offer ${shipment.offer_id}` : 'Preorder';

    return `${offer} · ${eta} · ${shipment.quantity} pcs`;
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <span ref="root" class="relative inline-flex justify-end">
        <button
            v-if="props.shipments.length > 0"
            type="button"
            class="tabular-nums underline decoration-dotted underline-offset-2 hover:text-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200"
            :aria-expanded="open"
            :data-testid="`restock-preorder-qty-${props.committedQty}`"
            @click.stop="toggle"
            @mouseenter="open = true"
            @mouseleave="open = false"
        >
            {{ props.committedQty }}
        </button>
        <span v-else class="tabular-nums text-slate-400">—</span>
        <span
            v-if="open && props.shipments.length > 0"
            role="tooltip"
            class="absolute right-0 top-full z-30 mt-1 w-56 rounded-md border border-slate-200 bg-white p-2 text-left text-[11px] font-normal normal-case leading-snug tracking-normal text-slate-700 shadow-lg"
            data-testid="restock-preorder-breakdown"
            @mouseenter="open = true"
            @mouseleave="open = false"
        >
            <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                PLAMOD preorders
            </span>
            <ul class="space-y-1">
                <li v-for="(shipment, index) in props.shipments" :key="`${shipment.offer_id ?? 'offer'}-${index}`">
                    {{ shipmentLabel(shipment) }}
                </li>
            </ul>
        </span>
    </span>
</template>
