<script setup lang="ts">
import { formatTorontoDateTime } from '../../lib/datetime';
import {
    formatShopifyOrderContact,
    formatShopifyOrderMoney,
    shopifyOrderPaymentClass,
    shopifyOrderStatusLabel,
} from '../../lib/shopifyOrders';
import type {
    ShopifyOrder,
    ShopifyOrderLine,
    ShopifyOrderSortKey,
} from '../../types/shopifyOrders';

const props = defineProps<{
    rows: ShopifyOrder[];
    loading: boolean;
    page: number;
    lastPage: number;
    sortBy: ShopifyOrderSortKey;
    sortDir: 'asc' | 'desc';
    currency: string;
    expandedId: number | null;
    expandedLines: ShopifyOrderLine[];
    linesLoading: boolean;
    selectedIds: number[];
}>();

const emit = defineEmits<{
    (e: 'toggle-sort', key: ShopifyOrderSortKey): void;
    (e: 'toggle-lines', order: ShopifyOrder): void;
    (e: 'update:page', page: number): void;
    (e: 'toggle-select', id: number): void;
    (e: 'toggle-select-page', selected: boolean): void;
}>();

function allPageSelected(): boolean {
    return props.rows.length > 0 && props.rows.every((row) => props.selectedIds.includes(row.id));
}

function sortIndicator(key: ShopifyOrderSortKey): string {
    if (props.sortBy !== key) return '';
    return props.sortDir === 'asc' ? ' ▲' : ' ▼';
}

function contactParts(order: ShopifyOrder): { email: string | null; phone: string | null } {
    return formatShopifyOrderContact(order.customer_email, order.customer_phone);
}

function hasContact(order: ShopifyOrder): boolean {
    const parts = contactParts(order);
    return parts.email !== null || parts.phone !== null;
}
</script>

<template>
    <div>
        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-left text-slate-700">
                    <tr>
                        <th class="px-3 py-2">
                            <input
                                type="checkbox"
                                :checked="allPageSelected()"
                                :disabled="loading || rows.length === 0"
                                @change="
                                    emit(
                                        'toggle-select-page',
                                        ($event.target as HTMLInputElement).checked,
                                    )
                                "
                            />
                        </th>
                        <th class="px-3 py-2">
                            <button type="button" @click="emit('toggle-sort', 'name')">
                                Order{{ sortIndicator('name') }}
                            </button>
                        </th>
                        <th class="px-3 py-2">
                            <button type="button" @click="emit('toggle-sort', 'contact')">
                                Contact{{ sortIndicator('contact') }}
                            </button>
                        </th>
                        <th class="px-3 py-2">
                            <button type="button" @click="emit('toggle-sort', 'ordered_at')">
                                Ordered{{ sortIndicator('ordered_at') }}
                            </button>
                        </th>
                        <th class="px-3 py-2">Processed by</th>
                        <th class="px-3 py-2">
                            <button type="button" @click="emit('toggle-sort', 'channel')">
                                Channel{{ sortIndicator('channel') }}
                            </button>
                        </th>
                        <th class="px-3 py-2">Event</th>
                        <th class="px-3 py-2">
                            <button type="button" @click="emit('toggle-sort', 'financial_status')">
                                Payment{{ sortIndicator('financial_status') }}
                            </button>
                        </th>
                        <th class="px-3 py-2">
                            <button
                                type="button"
                                @click="emit('toggle-sort', 'fulfillment_status')"
                            >
                                Fulfillment{{ sortIndicator('fulfillment_status') }}
                            </button>
                        </th>
                        <th class="px-3 py-2 text-right">
                            <button type="button" @click="emit('toggle-sort', 'subtotal')">
                                Before tax{{ sortIndicator('subtotal') }}
                            </button>
                        </th>
                        <th class="px-3 py-2 text-right">
                            <button type="button" @click="emit('toggle-sort', 'line_count')">
                                Lines{{ sortIndicator('line_count') }}
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-if="loading">
                        <td colspan="12" class="px-3 py-4 text-slate-600">Loading…</td>
                    </tr>
                    <tr v-else-if="rows.length === 0">
                        <td colspan="12" class="px-3 py-4 text-slate-600">
                            No mirrored orders in this range.
                        </td>
                    </tr>
                    <template v-for="order in rows" :key="order.id">
                        <tr>
                            <td class="px-3 py-2">
                                <input
                                    type="checkbox"
                                    :checked="selectedIds.includes(order.id)"
                                    @change="emit('toggle-select', order.id)"
                                />
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <a
                                    v-if="order.shopify_admin_url"
                                    :href="order.shopify_admin_url"
                                    class="font-medium text-slate-900 underline"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    {{ order.name ?? '—' }}
                                </a>
                                <span v-else class="font-medium">{{ order.name ?? '—' }}</span>
                                <span
                                    v-if="order.has_store_preorder"
                                    class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900"
                                >
                                    Pre-order
                                </span>
                                <span
                                    v-if="order.cancelled_at"
                                    class="ml-2 rounded bg-rose-100 px-1.5 py-0.5 text-xs text-rose-800"
                                >
                                    Cancelled
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <template v-if="hasContact(order)">
                                    <div v-if="contactParts(order).email">
                                        {{ contactParts(order).email }}
                                    </div>
                                    <div v-if="contactParts(order).phone">
                                        {{ contactParts(order).phone }}
                                    </div>
                                </template>
                                <span v-else class="text-slate-500">—</span>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ formatTorontoDateTime(order.ordered_at) }}
                            </td>
                            <td class="px-3 py-2">{{ order.processed_by_label ?? '—' }}</td>
                            <td class="px-3 py-2">{{ order.sales_channel_label }}</td>
                            <td class="px-3 py-2">{{ order.store_event_name ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <span
                                    class="rounded px-2 py-0.5 text-xs"
                                    :class="
                                        shopifyOrderPaymentClass(
                                            order.financial_status,
                                            order.cancelled_at !== null,
                                        )
                                    "
                                >
                                    {{ shopifyOrderStatusLabel(order.financial_status) }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                {{ shopifyOrderStatusLabel(order.fulfillment_status) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ formatShopifyOrderMoney(order.subtotal, currency) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <button
                                    type="button"
                                    class="text-slate-700 underline"
                                    @click="emit('toggle-lines', order)"
                                >
                                    {{ order.line_item_count ?? 0 }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="expandedId === order.id">
                            <td colspan="12" class="bg-slate-50 px-4 py-3">
                                <div v-if="linesLoading" class="text-slate-600">Loading lines…</div>
                                <table v-else-if="expandedLines.length > 0" class="w-full text-sm">
                                    <thead class="text-left text-slate-600">
                                        <tr>
                                            <th class="py-1 pr-3">SKU</th>
                                            <th class="py-1 pr-3">Qty</th>
                                            <th class="py-1">Description</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="line in expandedLines" :key="line.id">
                                            <td class="py-1 pr-3 font-mono text-xs">
                                                {{ line.sku ?? '—' }}
                                            </td>
                                            <td class="py-1 pr-3">{{ line.quantity }}</td>
                                            <td class="py-1">
                                                {{ line.description ?? '—' }}
                                                <span
                                                    v-if="line.is_store_preorder"
                                                    class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900"
                                                >
                                                    Pre-order
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div v-else class="text-slate-600">
                                    No line items in the mirror.
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex items-center justify-between text-sm">
            <button
                type="button"
                class="rounded-md border border-slate-200 px-3 py-1 disabled:opacity-50"
                :disabled="page <= 1 || loading"
                @click="emit('update:page', Math.max(1, page - 1))"
            >
                Previous
            </button>
            <span>Page {{ page }} / {{ lastPage }}</span>
            <button
                type="button"
                class="rounded-md border border-slate-200 px-3 py-1 disabled:opacity-50"
                :disabled="page >= lastPage || loading"
                @click="emit('update:page', page + 1)"
            >
                Next
            </button>
        </div>
    </div>
</template>
