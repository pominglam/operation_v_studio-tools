<script setup lang="ts">
import { computed } from 'vue';
import { formatTorontoDate, formatTorontoDateTime } from '../../lib/datetime';
import { formatMoney2OrEmpty } from '../../lib/money';
import type { StorePreorderRow } from '../../types/storePreorder';

const props = defineProps<{
    rows: StorePreorderRow[];
    loading: boolean;
    selectedIds: string[];
    capDraftById: Record<string, string>;
    savingCapId: string | null;
}>();

const emit = defineEmits<{
    (e: 'toggle-page', checked: boolean): void;
    (e: 'toggle-row', id: string, checked: boolean): void;
    (e: 'update-cap-draft', row: StorePreorderRow, value: string): void;
    (e: 'save-cap', row: StorePreorderRow): void;
    (e: 'close-row', row: StorePreorderRow): void;
    (e: 'delete-row', row: StorePreorderRow): void;
}>();

const allPageSelected = computed(
    () => props.rows.length > 0 && props.rows.every((row) => props.selectedIds.includes(row.id)),
);
const somePageSelected = computed(
    () => props.rows.some((row) => props.selectedIds.includes(row.id)) && !allPageSelected.value,
);

function remainingLabel(row: StorePreorderRow): string {
    if (row.cap_qty === null) {
        return row.status === 'open' ? 'No cap' : '—';
    }

    return `${row.remaining_qty ?? 0} / ${row.cap_qty}`;
}

function capDraft(row: StorePreorderRow): string {
    return props.capDraftById[row.id] ?? (row.cap_qty === null ? '' : String(row.cap_qty));
}

function isSelected(id: string): boolean {
    return props.selectedIds.includes(id);
}
</script>

<template>
    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table class="min-w-full text-left text-sm">
            <thead
                class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-600"
            >
                <tr>
                    <th class="w-10 px-3 py-2">
                        <input
                            type="checkbox"
                            class="rounded border-slate-300"
                            :checked="allPageSelected"
                            :indeterminate="somePageSelected"
                            :disabled="rows.length === 0"
                            aria-label="Select all on this page"
                            data-testid="store-preorders-select-page"
                            @change="
                                emit('toggle-page', ($event.target as HTMLInputElement).checked)
                            "
                        />
                    </th>
                    <th class="px-3 py-2">Image</th>
                    <th class="px-3 py-2">Product</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Units</th>
                    <th class="px-3 py-2">Cap</th>
                    <th class="px-3 py-2">Deposit</th>
                    <th class="px-3 py-2">Sell $</th>
                    <th class="px-3 py-2">Closing</th>
                    <th class="px-3 py-2">ETA</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in rows"
                    :key="row.id"
                    class="border-b border-slate-100 hover:bg-slate-50"
                >
                    <td class="px-3 py-2 align-middle">
                        <input
                            type="checkbox"
                            class="rounded border-slate-300"
                            :checked="isSelected(row.id)"
                            :aria-label="`Select ${row.sku}`"
                            :data-testid="`store-preorder-select-${row.plamod_sku}`"
                            @change="
                                emit(
                                    'toggle-row',
                                    row.id,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                    </td>
                    <td class="px-3 py-2 align-middle">
                        <img
                            v-if="row.image_url"
                            :src="row.image_url"
                            :alt="row.product_name ?? row.sku"
                            class="h-16 w-16 rounded border border-slate-200 bg-white object-contain"
                            loading="lazy"
                        />
                    </td>
                    <td class="max-w-[28rem] px-3 py-2 align-middle">
                        <div class="font-medium text-slate-900">
                            {{ row.product_name ?? '—' }}
                        </div>
                        <div class="mt-0.5 font-mono text-xs text-slate-600">{{ row.sku }}</div>
                    </td>
                    <td class="px-3 py-2 align-middle">
                        <span
                            class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase"
                            :class="
                                row.status === 'open'
                                    ? 'bg-emerald-100 text-emerald-800'
                                    : 'bg-slate-200 text-slate-700'
                            "
                        >
                            {{ row.status }}
                        </span>
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 align-middle">
                        <div class="font-medium text-slate-900">
                            {{ row.unit_qty }} {{ row.unit_qty === 1 ? 'unit' : 'units' }}
                        </div>
                        <div class="text-xs text-slate-500">
                            {{ row.order_count }}
                            {{ row.order_count === 1 ? 'order' : 'orders' }}
                        </div>
                    </td>
                    <td class="px-3 py-2 align-middle">
                        <label v-if="row.status === 'open'" class="block">
                            <span class="sr-only">Cap for {{ row.sku }}</span>
                            <input
                                :value="capDraft(row)"
                                type="number"
                                min="1"
                                max="9999"
                                step="1"
                                :aria-label="`Cap for ${row.sku}`"
                                placeholder="No cap"
                                class="h-8 w-20 rounded-md border border-slate-300 bg-white px-2 text-sm text-slate-900 disabled:opacity-60"
                                :disabled="savingCapId === row.id"
                                :data-testid="`store-preorder-cap-${row.plamod_sku}`"
                                @input="
                                    emit(
                                        'update-cap-draft',
                                        row,
                                        ($event.target as HTMLInputElement).value,
                                    )
                                "
                                @keydown.enter.prevent="emit('save-cap', row)"
                                @blur="emit('save-cap', row)"
                            />
                        </label>
                        <span v-else>{{ remainingLabel(row) }}</span>
                    </td>
                    <td class="px-3 py-2 align-middle">
                        {{ row.deposit_percent }}%
                        <span v-if="row.deposit_amount_cad" class="text-slate-500">
                            ({{ formatMoney2OrEmpty(row.deposit_amount_cad) }})
                        </span>
                    </td>
                    <td class="px-3 py-2 align-middle">
                        {{ formatMoney2OrEmpty(row.selling_price_cad) }}
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap align-middle">
                        {{ row.window_ends_on ? formatTorontoDate(row.window_ends_on) : '—' }}
                        <div v-if="row.opened_at" class="text-xs text-slate-500">
                            Opened {{ formatTorontoDateTime(row.opened_at) }}
                        </div>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap align-middle">
                        {{ row.eta_date ? formatTorontoDate(row.eta_date) : '—' }}
                    </td>
                    <td class="px-3 py-2 align-middle">
                        <div class="flex flex-wrap gap-1">
                            <button
                                v-if="row.status === 'open'"
                                type="button"
                                class="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-medium text-rose-800 hover:bg-rose-100"
                                :data-testid="`store-preorder-close-${row.plamod_sku}`"
                                @click="emit('close-row', row)"
                            >
                                Close
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-800 hover:bg-slate-100"
                                :data-testid="`store-preorder-delete-${row.plamod_sku}`"
                                @click="emit('delete-row', row)"
                            >
                                Delete
                            </button>
                        </div>
                    </td>
                </tr>
                <tr v-if="!loading && rows.length === 0">
                    <td colspan="11" class="px-3 py-8 text-center text-slate-500">
                        No store preorders yet.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
