<script setup lang="ts">
import { formatTorontoDateTime } from '../../lib/datetime';
import { formatShopifyOrderMoney } from '../../lib/shopifyOrders';
import { formatStoreEventDayLabel, formatStoreEventLineGlance } from '../../lib/storeEvents';
import type { StoreEvent, StoreEventDay, StoreEventDayOrder } from '../../types/storeEvent';

const props = defineProps<{
    events: StoreEvent[];
    loading: boolean;
    currency: string;
    expandedEventIds: string[];
    expandedDayKeys: string[];
    expandedOrderIds: number[];
}>();

const emit = defineEmits<{
    (e: 'toggle-event', id: string): void;
    (e: 'toggle-day', key: string): void;
    (e: 'toggle-order-lines', orderId: number): void;
    (e: 'edit', event: StoreEvent): void;
    (e: 'delete', event: StoreEvent): void;
    (
        e: 'set-included',
        payload: { event: StoreEvent; order: StoreEventDayOrder; included: boolean },
    ): void;
}>();

function dayKey(eventId: string, date: string): string {
    return `${eventId}:${date}`;
}

function isEventOpen(id: string): boolean {
    return props.expandedEventIds.includes(id);
}

function isDayOpen(eventId: string, date: string): boolean {
    return props.expandedDayKeys.includes(dayKey(eventId, date));
}

function dateLabel(event: StoreEvent): string {
    if (event.starts_on === event.ends_on) {
        return event.starts_on;
    }

    return `${event.starts_on} → ${event.ends_on}`;
}

function dayOrdersLabel(day: StoreEventDay): string {
    return `${day.event_order_count} in · ${day.order_count} total`;
}
</script>

<template>
    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-3 py-2 font-medium">Event / order</th>
                    <th class="px-3 py-2 font-medium">When</th>
                    <th class="px-3 py-2 font-medium">Orders</th>
                    <th class="px-3 py-2 font-medium">Event $ (before tax)</th>
                    <th class="px-3 py-2 font-medium">Processed by</th>
                    <th class="px-3 py-2 font-medium">Channel</th>
                    <th class="px-3 py-2 font-medium">Lines</th>
                    <th class="px-3 py-2 font-medium" />
                </tr>
            </thead>
            <tbody>
                <tr v-if="loading">
                    <td colspan="8" class="px-3 py-6 text-slate-500">Loading…</td>
                </tr>
                <tr v-else-if="events.length === 0">
                    <td colspan="8" class="px-3 py-6 text-center text-slate-500">
                        No store events yet.
                    </td>
                </tr>
                <template v-for="event in events" :key="event.id">
                    <tr
                        class="border-t border-slate-200 bg-slate-50/80"
                        :class="event.cancelled ? 'text-slate-500' : 'text-slate-900'"
                    >
                        <td class="px-3 py-2">
                            <button
                                type="button"
                                class="font-semibold underline decoration-slate-300 hover:decoration-slate-600"
                                @click="emit('toggle-event', event.id)"
                            >
                                {{ isEventOpen(event.id) ? '▾' : '▸' }}
                                {{ event.name }}
                            </button>
                            <span
                                class="ml-2 text-xs"
                                :class="event.cancelled ? 'line-through' : 'text-slate-600'"
                            >
                                {{ event.cancelled ? 'Cancelled' : 'Scheduled' }}
                            </span>
                            <div
                                v-if="event.notes"
                                class="mt-0.5 text-xs font-normal text-slate-500"
                            >
                                {{ event.notes }}
                            </div>
                        </td>
                        <td class="px-3 py-2 tabular-nums text-slate-700">
                            {{ dateLabel(event) }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">{{ event.event_order_count }}</td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ formatShopifyOrderMoney(event.event_subtotal, currency) }}
                        </td>
                        <td colspan="2" class="px-3 py-2 text-slate-400">—</td>
                        <td class="px-3 py-2 text-slate-400">—</td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <button
                                type="button"
                                class="text-sm text-slate-700 underline hover:text-slate-900"
                                @click="emit('edit', event)"
                            >
                                Edit
                            </button>
                            <button
                                type="button"
                                class="ml-3 text-sm text-red-700 underline hover:text-red-900"
                                @click="emit('delete', event)"
                            >
                                Delete
                            </button>
                        </td>
                    </tr>
                    <template v-if="isEventOpen(event.id)">
                        <template v-for="day in event.days" :key="dayKey(event.id, day.date)">
                            <tr class="border-t border-slate-100 bg-white">
                                <td class="px-3 py-2 pl-8">
                                    <button
                                        type="button"
                                        class="text-slate-800 underline decoration-slate-200 hover:decoration-slate-500"
                                        @click="emit('toggle-day', dayKey(event.id, day.date))"
                                    >
                                        {{ isDayOpen(event.id, day.date) ? '▾' : '▸' }}
                                        {{ formatStoreEventDayLabel(day.date) }}
                                    </button>
                                </td>
                                <td class="px-3 py-2 tabular-nums text-slate-600">
                                    {{ day.date }}
                                </td>
                                <td class="px-3 py-2 tabular-nums text-slate-700">
                                    {{ dayOrdersLabel(day) }}
                                </td>
                                <td class="px-3 py-2 tabular-nums">
                                    {{ formatShopifyOrderMoney(day.event_subtotal, currency) }}
                                    <span class="block text-xs text-slate-500">
                                        other
                                        {{ formatShopifyOrderMoney(day.other_subtotal, currency) }}
                                    </span>
                                </td>
                                <td colspan="4" class="px-3 py-2 text-slate-400">—</td>
                            </tr>
                            <template v-if="isDayOpen(event.id, day.date)">
                                <tr v-if="day.orders.length === 0">
                                    <td colspan="8" class="px-3 py-2 pl-14 text-slate-500">
                                        No eligible orders this day.
                                    </td>
                                </tr>
                                <template v-for="order in day.orders" :key="order.id">
                                    <tr
                                        class="border-t border-slate-50"
                                        :class="
                                            order.in_event ? 'text-slate-900' : 'text-slate-400'
                                        "
                                    >
                                        <td class="px-3 py-2 pl-14">
                                            <label class="flex items-start gap-2">
                                                <input
                                                    type="checkbox"
                                                    class="mt-1"
                                                    :checked="order.in_event"
                                                    @change="
                                                        emit('set-included', {
                                                            event,
                                                            order,
                                                            included: (
                                                                $event.target as HTMLInputElement
                                                            ).checked,
                                                        })
                                                    "
                                                />
                                                <span>
                                                    <a
                                                        v-if="order.shopify_admin_url"
                                                        :href="order.shopify_admin_url"
                                                        class="underline"
                                                        :class="
                                                            order.in_event
                                                                ? 'text-slate-900'
                                                                : 'text-slate-400'
                                                        "
                                                        target="_blank"
                                                        rel="noreferrer"
                                                    >
                                                        {{ order.name ?? '—' }}
                                                    </a>
                                                    <span v-else>{{ order.name ?? '—' }}</span>
                                                    <span
                                                        v-if="
                                                            !order.in_event &&
                                                            order.store_event_name
                                                        "
                                                        class="ml-2 text-xs"
                                                    >
                                                        ({{ order.store_event_name }})
                                                    </span>
                                                </span>
                                            </label>
                                        </td>
                                        <td class="px-3 py-2 whitespace-nowrap">
                                            {{ formatTorontoDateTime(order.ordered_at) }}
                                        </td>
                                        <td class="px-3 py-2 text-slate-400">—</td>
                                        <td class="px-3 py-2 tabular-nums">
                                            {{ formatShopifyOrderMoney(order.subtotal, currency) }}
                                        </td>
                                        <td class="px-3 py-2">
                                            {{ order.processed_by_label ?? '—' }}
                                        </td>
                                        <td class="px-3 py-2">{{ order.sales_channel_label }}</td>
                                        <td class="px-3 py-2">
                                            <button
                                                type="button"
                                                class="text-left underline decoration-slate-200 hover:decoration-slate-500"
                                                @click="emit('toggle-order-lines', order.id)"
                                            >
                                                {{
                                                    formatStoreEventLineGlance(
                                                        order.lines,
                                                        order.line_item_count,
                                                    )
                                                }}
                                            </button>
                                        </td>
                                        <td />
                                    </tr>
                                    <tr v-if="expandedOrderIds.includes(order.id)">
                                        <td colspan="8" class="bg-slate-50 px-14 py-2">
                                            <table
                                                v-if="(order.lines ?? []).length > 0"
                                                class="w-full text-sm"
                                            >
                                                <thead class="text-left text-slate-500">
                                                    <tr>
                                                        <th class="py-1 pr-3">SKU</th>
                                                        <th class="py-1 pr-3">Qty</th>
                                                        <th class="py-1">Description</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr
                                                        v-for="line in order.lines ?? []"
                                                        :key="line.id"
                                                    >
                                                        <td class="py-1 pr-3 font-mono text-xs">
                                                            {{ line.sku ?? '—' }}
                                                        </td>
                                                        <td class="py-1 pr-3">
                                                            {{ line.quantity }}
                                                        </td>
                                                        <td class="py-1">
                                                            {{ line.description ?? '—' }}
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <div v-else class="text-slate-500">
                                                No line items in the mirror.
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </template>
                        </template>
                    </template>
                </template>
            </tbody>
        </table>
    </div>
</template>
