<script setup lang="ts">
import { computed } from 'vue';
import { useClientTableSort } from '../../composables/useClientTableSort';
import { formatTorontoDateTime } from '../../lib/datetime';
import { formatMoney2, parseMoney } from '../../lib/money';

export type PoSellingPriceHistoryEntry = {
    id: number;
    product_uuid: string;
    sku: string;
    description: string | null;
    previous_price: string | null;
    new_price: string | null;
    currency: string;
    source: string;
    created_at: string;
};

const props = defineProps<{
    entries: PoSellingPriceHistoryEntry[];
    loading: boolean;
    error: string | null;
}>();

type SortColumn = 'when' | 'sku' | 'product' | 'change' | 'source';

const sort = useClientTableSort<SortColumn>();

const hasEntries = computed<boolean>(() => props.entries.length > 0);

const sortedEntries = computed<PoSellingPriceHistoryEntry[]>(() =>
    sort.sortedRows(props.entries, compareEntries),
);

function formatPrice(value: string | null): string {
    if (value === null || value.trim() === '') {
        return '—';
    }

    return formatMoney2(value);
}

function formatChange(entry: PoSellingPriceHistoryEntry): string {
    const previous = formatPrice(entry.previous_price);
    const next = formatPrice(entry.new_price);
    if (previous === '—') {
        return next;
    }

    return `${previous} → ${next}`;
}

function moneySortValue(value: string | null): number | null {
    if (value === null || value.trim() === '') {
        return null;
    }

    const parsed = parseMoney(value);
    return parsed === null ? null : parsed;
}

function compareNullableNumbers(left: number | null, right: number | null): number {
    if (left === null && right === null) {
        return 0;
    }
    if (left === null) {
        return 1;
    }
    if (right === null) {
        return -1;
    }

    return left - right;
}

function compareSku(left: string, right: string): number {
    const leftNumeric = Number.parseInt(left, 10);
    const rightNumeric = Number.parseInt(right, 10);
    if (!Number.isNaN(leftNumeric) && !Number.isNaN(rightNumeric)) {
        return leftNumeric - rightNumeric;
    }

    return left.localeCompare(right, undefined, { numeric: true, sensitivity: 'base' });
}

function compareEntries(
    left: PoSellingPriceHistoryEntry,
    right: PoSellingPriceHistoryEntry,
    column: SortColumn,
): number {
    switch (column) {
        case 'when':
            return left.created_at.localeCompare(right.created_at);
        case 'sku':
            return compareSku(left.sku, right.sku);
        case 'product':
            return (left.description ?? '').localeCompare(right.description ?? '', undefined, {
                sensitivity: 'base',
            });
        case 'change': {
            const newCmp = compareNullableNumbers(
                moneySortValue(left.new_price),
                moneySortValue(right.new_price),
            );
            if (newCmp !== 0) {
                return newCmp;
            }

            return compareNullableNumbers(
                moneySortValue(left.previous_price),
                moneySortValue(right.previous_price),
            );
        }
        case 'source':
            return left.source.localeCompare(right.source);
        default:
            return 0;
    }
}
</script>

<template>
    <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
        <div class="text-xs font-semibold text-slate-800">
            Selling price changes
            <span v-if="hasEntries" class="font-normal text-slate-600">
                ({{ entries.length }})
            </span>
        </div>
        <div class="mt-1 text-xs text-slate-600">
            Recorded when Set/review selling prices is applied on this PO.
        </div>

        <p v-if="error" class="mt-2 text-sm text-rose-700">{{ error }}</p>
        <p v-else-if="loading" class="mt-2 text-sm text-slate-600">Loading price history…</p>
        <p v-else-if="!hasEntries" class="mt-2 text-sm text-slate-600">
            No selling price changes recorded for this PO yet.
        </p>

        <div
            v-else
            data-testid="po-selling-price-history-scroll"
            class="mt-3 overflow-x-auto rounded-lg border border-slate-200 bg-white"
        >
            <table class="min-w-[48rem] w-full text-left text-xs">
                <thead
                    class="bg-slate-50 text-[11px] font-semibold uppercase tracking-wide text-slate-600"
                >
                    <tr>
                        <th class="px-3 py-2">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sort.headerClass('when')"
                                data-testid="po-selling-price-history-sort-when"
                                @click="sort.toggleSort('when')"
                            >
                                When{{ sort.sortIndicator('when') }}
                            </button>
                        </th>
                        <th class="px-3 py-2">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sort.headerClass('sku')"
                                data-testid="po-selling-price-history-sort-sku"
                                @click="sort.toggleSort('sku')"
                            >
                                SKU{{ sort.sortIndicator('sku') }}
                            </button>
                        </th>
                        <th class="px-3 py-2">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sort.headerClass('product')"
                                data-testid="po-selling-price-history-sort-product"
                                @click="sort.toggleSort('product')"
                            >
                                Product{{ sort.sortIndicator('product') }}
                            </button>
                        </th>
                        <th class="px-3 py-2">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sort.headerClass('change')"
                                data-testid="po-selling-price-history-sort-change"
                                @click="sort.toggleSort('change')"
                            >
                                Change{{ sort.sortIndicator('change') }}
                            </button>
                        </th>
                        <th class="px-3 py-2">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sort.headerClass('source')"
                                data-testid="po-selling-price-history-sort-source"
                                @click="sort.toggleSort('source')"
                            >
                                Source{{ sort.sortIndicator('source') }}
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="entry in sortedEntries"
                        :key="entry.id"
                        class="border-t border-slate-100 text-slate-800"
                    >
                        <td class="px-3 py-2 whitespace-nowrap tabular-nums">
                            {{ formatTorontoDateTime(entry.created_at) }}
                        </td>
                        <td class="px-3 py-2 font-medium">{{ entry.sku }}</td>
                        <td
                            class="max-w-[16rem] truncate px-3 py-2"
                            :title="entry.description ?? ''"
                        >
                            {{ entry.description || '—' }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">{{ formatChange(entry) }}</td>
                        <td class="px-3 py-2 capitalize text-slate-600">
                            {{ entry.source.replace(/_/g, ' ') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
