<script setup lang="ts">
import { ref } from 'vue';
import { formatTorontoDate } from '../../lib/datetime';
import { formatMoney2OrEmpty } from '../../lib/money';
import type { TrackingResolution } from '../../composables/useShipmentTrackingResolution';
import {
    emptyToNull,
    moneyDraftEquals,
    parseMoneyDraft,
    savePurchaseOrderInlineField,
    type PurchaseOrderInlineField,
    type PurchaseOrderInlinePatch,
} from '../../composables/purchaseOrders/usePurchaseOrderInlineUpdate';
import { poStatusLabel, poTotal, type PurchaseOrderListRow } from '../../types/purchaseOrderList';

const props = defineProps<{
    po: PurchaseOrderListRow;
    selected: boolean;
    deleting: boolean;
    resolutionFor: (trackingNumber: string) => TrackingResolution | null;
    isTrackingPending: (trackingNumber: string) => boolean;
}>();

const emit = defineEmits<{
    toggleSelect: [];
    delete: [];
    updated: [row: PurchaseOrderListRow];
}>();

const savingField = ref<PurchaseOrderInlineField | null>(null);
const saveError = ref<string | null>(null);
const shippingDraft = ref<string | null>(null);
const surchargeDraft = ref<string | null>(null);

const inputClass =
    'rounded-md border border-slate-200 bg-white px-1.5 py-1 text-sm disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500';

function isSaving(): boolean {
    return savingField.value !== null;
}

async function commitPatch(changes: PurchaseOrderInlinePatch): Promise<void> {
    const field = Object.keys(changes)[0] as PurchaseOrderInlineField | undefined;
    if (!field) return;
    savingField.value = field;
    saveError.value = null;
    try {
        emit('updated', await savePurchaseOrderInlineField(props.po, changes));
    } catch (err: unknown) {
        saveError.value = err instanceof Error ? err.message : 'Failed to save purchase order.';
    } finally {
        savingField.value = null;
    }
}

function onShipmentChange(event: Event): void {
    const raw = (event.target as HTMLSelectElement).value;
    const next: PurchaseOrderListRow['shipment_method'] =
        raw === 'air' || raw === 'sea' ? raw : null;
    if (next === props.po.shipment_method) return;
    void commitPatch({ shipment_method: next });
}

function onDateChange(
    field: 'estimated_arrival_date' | 'received_date' | 'fully_on_shelves_date',
    event: Event,
): void {
    const next = emptyToNull((event.target as HTMLInputElement).value);
    if (next === (props.po[field] ?? null)) return;
    void commitPatch({ [field]: next });
}

function startMoneyDraft(field: 'shipping_total' | 'surcharge_total'): void {
    if (field === 'shipping_total') {
        shippingDraft.value = formatMoney2OrEmpty(props.po.shipping_total);
        return;
    }
    surchargeDraft.value = formatMoney2OrEmpty(props.po.surcharge_total);
}

function updateMoneyDraft(field: 'shipping_total' | 'surcharge_total', value: string): void {
    if (field === 'shipping_total') {
        shippingDraft.value = value;
        return;
    }
    surchargeDraft.value = value;
}

async function commitMoney(
    field: 'shipping_total' | 'surcharge_total',
    event?: Event,
): Promise<void> {
    const fromEvent = event?.target instanceof HTMLInputElement ? event.target.value : null;
    const draft =
        (field === 'shipping_total' ? shippingDraft.value : surchargeDraft.value) ?? fromEvent;
    if (draft === null) return;
    if (field === 'shipping_total') {
        shippingDraft.value = null;
    } else {
        surchargeDraft.value = null;
    }
    if (moneyDraftEquals(props.po[field], draft)) return;
    try {
        const next = parseMoneyDraft(draft);
        await commitPatch({ [field]: next });
    } catch (err: unknown) {
        saveError.value = err instanceof Error ? err.message : 'Enter a valid amount.';
    }
}
</script>

<template>
    <tr class="border-t border-slate-200 hover:bg-slate-50">
        <td class="px-2 py-2">
            <input
                type="checkbox"
                data-testid="po-history-select"
                :aria-label="`Select purchase order ${po.id}`"
                :checked="selected"
                @change="emit('toggleSelect')"
            />
        </td>
        <td class="px-2 py-2">
            <a class="underline underline-offset-2" :href="`/purchase-orders/${po.id}`">{{
                po.id
            }}</a>
            <div class="mt-0.5 text-[11px] text-slate-500">
                Supplier order ID: {{ po.supplier_order_id ?? '—' }}
            </div>
            <div
                v-if="po.notes && po.notes.trim() !== ''"
                class="mt-0.5 text-[11px] text-slate-500"
            >
                Note: {{ po.notes }}
            </div>
        </td>
        <td class="px-2 py-2" data-testid="po-history-status">{{ poStatusLabel(po.status) }}</td>
        <td class="px-2 py-2">
            <select
                :class="[inputClass, 'w-[4.75rem]']"
                :value="po.shipment_method ?? ''"
                :disabled="isSaving()"
                :data-testid="`po-history-shipment:${po.id}`"
                :aria-label="`Shipment method for ${po.id}`"
                @change="onShipmentChange"
            >
                <option value="">—</option>
                <option value="air">Air</option>
                <option value="sea">Sea</option>
            </select>
            <div
                v-for="(trackingNumber, index) in po.shipment_tracking_numbers ?? []"
                :key="trackingNumber"
                class="mt-0.5 flex max-w-36 items-center gap-1 text-xs"
                :data-testid="`po-history-tracking-${po.id}-${index}`"
            >
                <a
                    v-if="
                        resolutionFor(trackingNumber)?.status === 'resolved' &&
                        resolutionFor(trackingNumber)?.tracking_url
                    "
                    :href="resolutionFor(trackingNumber)?.tracking_url ?? undefined"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="truncate text-indigo-700 underline underline-offset-2"
                    :title="`Open in ${resolutionFor(trackingNumber)?.provider ?? 'tracking provider'}`"
                >
                    {{ trackingNumber }}
                </a>
                <span
                    v-else
                    class="truncate text-slate-600"
                    :title="
                        isTrackingPending(trackingNumber)
                            ? 'Finding a tracking provider…'
                            : 'No tracking provider found yet'
                    "
                >
                    {{ trackingNumber }}
                </span>
                <svg
                    v-if="isTrackingPending(trackingNumber)"
                    data-testid="tracking-resolution-spinner"
                    class="h-3 w-3 shrink-0 animate-spin text-slate-400"
                    viewBox="0 0 24 24"
                    fill="none"
                    aria-label="Finding tracking provider"
                >
                    <circle
                        class="opacity-25"
                        cx="12"
                        cy="12"
                        r="9"
                        stroke="currentColor"
                        stroke-width="3"
                    />
                    <path
                        class="opacity-75"
                        fill="currentColor"
                        d="M12 3a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6V3Z"
                    />
                </svg>
            </div>
        </td>
        <td class="px-2 py-2 text-slate-600">{{ formatTorontoDate(po.created_at) }}</td>
        <td class="px-2 py-2">{{ po.ordered_date ?? '—' }}</td>
        <td class="px-2 py-2">
            <input
                type="date"
                :class="[inputClass, 'w-[9.25rem]']"
                :value="po.estimated_arrival_date ?? ''"
                :disabled="isSaving()"
                :data-testid="`po-history-estimated-arrival:${po.id}`"
                :aria-label="`Estimated arrival for ${po.id}`"
                @change="onDateChange('estimated_arrival_date', $event)"
            />
        </td>
        <td class="px-2 py-2">
            <input
                type="date"
                :class="[inputClass, 'w-[9.25rem]']"
                :value="po.received_date ?? ''"
                :disabled="isSaving()"
                :data-testid="`po-history-received:${po.id}`"
                :aria-label="`Received date for ${po.id}`"
                @change="onDateChange('received_date', $event)"
            />
        </td>
        <td class="px-2 py-2">
            <input
                type="date"
                :class="[inputClass, 'w-[9.25rem]']"
                :value="po.fully_on_shelves_date ?? ''"
                :disabled="isSaving()"
                :data-testid="`po-history-on-shelves:${po.id}`"
                :aria-label="`On shelves date for ${po.id}`"
                @change="onDateChange('fully_on_shelves_date', $event)"
            />
        </td>
        <td class="px-2 py-2">{{ po.vendor }}</td>
        <td class="px-2 py-2 text-right">{{ po.counts.items }}</td>
        <td class="px-2 py-2 text-right tabular-nums">
            {{ formatMoney2OrEmpty(po.product_total) }}
        </td>
        <td class="px-2 py-2 text-right">
            <input
                type="text"
                inputmode="decimal"
                :class="[inputClass, 'w-24 text-right tabular-nums']"
                :disabled="isSaving()"
                :value="shippingDraft ?? formatMoney2OrEmpty(po.shipping_total)"
                :data-testid="`po-history-shipping-total:${po.id}`"
                :aria-label="`Shipping total for ${po.id}`"
                placeholder="—"
                @focus="startMoneyDraft('shipping_total')"
                @input="
                    updateMoneyDraft('shipping_total', ($event.target as HTMLInputElement).value)
                "
                @keydown.enter.prevent="commitMoney('shipping_total', $event)"
                @blur="commitMoney('shipping_total', $event)"
            />
        </td>
        <td class="px-2 py-2 text-right">
            <input
                type="text"
                inputmode="decimal"
                :class="[inputClass, 'w-24 text-right tabular-nums']"
                :disabled="isSaving()"
                :value="surchargeDraft ?? formatMoney2OrEmpty(po.surcharge_total)"
                :data-testid="`po-history-surcharge-total:${po.id}`"
                :aria-label="`Surcharge total for ${po.id}`"
                placeholder="—"
                @focus="startMoneyDraft('surcharge_total')"
                @input="
                    updateMoneyDraft('surcharge_total', ($event.target as HTMLInputElement).value)
                "
                @keydown.enter.prevent="commitMoney('surcharge_total', $event)"
                @blur="commitMoney('surcharge_total', $event)"
            />
        </td>
        <td class="px-2 py-2 text-right tabular-nums" data-testid="po-history-total">
            {{ formatMoney2OrEmpty(poTotal(po)) }}
        </td>
        <td class="px-2 py-2 text-right">
            <button
                type="button"
                class="rounded px-2 py-1 text-xs font-medium text-slate-600 hover:bg-rose-50 hover:text-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                data-testid="po-history-delete"
                :aria-label="`Delete purchase order ${po.id}`"
                :disabled="deleting"
                @click="emit('delete')"
            >
                {{ deleting ? 'Deleting…' : 'Delete' }}
            </button>
        </td>
    </tr>
    <tr v-if="saveError" class="border-t-0">
        <td
            colspan="17"
            class="px-2 pb-2 text-xs text-red-700"
            data-testid="po-history-inline-error"
        >
            {{ saveError }}
        </td>
    </tr>
</template>
