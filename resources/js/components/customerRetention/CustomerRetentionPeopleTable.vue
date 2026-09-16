<script setup lang="ts">
import { formatRetentionMoney, frequencyLabelText } from '../../lib/customerRetention';
import { formatTorontoDateTimeCompact } from '../../lib/datetime';
import type {
    CustomerFrequencyLabel,
    CustomerRetentionOrder,
    CustomerRetentionPerson,
    CustomerRetentionRfmCatalogRow,
    CustomerRetentionSortKey,
    CustomerStoreCadence,
} from '../../types/customerRetention';
import ColumnHeaderHelp from '../ColumnHeaderHelp.vue';
import RfmGroupNameButton from './RfmGroupNameButton.vue';
import RfmScoresHelp from './RfmScoresHelp.vue';

const props = defineProps<{
    rows: CustomerRetentionPerson[];
    loading: boolean;
    currency: string;
    catalog: CustomerRetentionRfmCatalogRow[];
    storeCadence: CustomerStoreCadence | null;
    sortBy: CustomerRetentionSortKey;
    sortDir: 'asc' | 'desc';
    expandedId: string | null;
    expandedOrders: CustomerRetentionOrder[];
    ordersLoading: boolean;
}>();

const emit = defineEmits<{
    sort: [column: CustomerRetentionSortKey];
    'toggle-person': [person: CustomerRetentionPerson];
}>();

function sortIndicator(column: CustomerRetentionSortKey): string {
    if (props.sortBy !== column) return '';
    return props.sortDir === 'asc' ? ' ↑' : ' ↓';
}

function frequencyChipClass(label: CustomerFrequencyLabel): string {
    if (label === 'loyal') return 'bg-emerald-50 text-emerald-800';
    if (label === 'repeat') return 'bg-sky-50 text-sky-800';
    return 'bg-slate-100 text-slate-700';
}

function cadenceChipClass(status: string): string {
    if (status === 'lapsed') return 'bg-rose-50 text-rose-800';
    if (status === 'due') return 'bg-amber-50 text-amber-800';
    return 'bg-emerald-50 text-emerald-800';
}

function cadenceLabel(person: CustomerRetentionPerson): string {
    if (person.cadence === null) return '—';
    const word =
        person.cadence.status === 'lapsed'
            ? 'Lapsed'
            : person.cadence.status === 'due'
              ? 'Due'
              : 'On cadence';
    return `${word} · ${person.cadence.days_since_last}d`;
}

function cadenceTitle(person: CustomerRetentionPerson): string {
    if (person.cadence === null) {
        return 'Needs a dated order to score against store rhythm.';
    }
    return `Days since last ${person.cadence.days_since_last}. Due after ${person.cadence.due_after_days}d. Lapsed after ${person.cadence.lapsed_after_days}d.`;
}

function cadenceHelp(): string {
    const rhythm = props.storeCadence;
    if (rhythm === null) {
        return 'Store rhythm: median of interpurchase gaps of 14+ days. Due at 1× that median. Lapsed at max(60, 2× median). One-order people are scored from their only order.';
    }
    return `Store rhythm from ${rhythm.typical_gap_count} gaps of ${rhythm.gap_floor_days}+ days. Median ${rhythm.median_gap_days}d. Due at ${rhythm.due_after_days}d. Lapsed at max(60, 2 × ${rhythm.median_gap_days}) = ${rhythm.lapsed_after_days}d. One-order people use the same store line.`;
}

function churnChipClass(status: string): string {
    return status === 'churned' ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800';
}

function churnLabel(person: CustomerRetentionPerson): string {
    if (person.churn === null) return '—';
    const word = person.churn.status === 'churned' ? 'Churned' : 'Active';
    return `${word} · ${person.churn.days_since_last}d`;
}

function churnTitle(person: CustomerRetentionPerson): string {
    if (person.churn === null) {
        return 'Needs 2+ dated orders to compute this person’s own average gap.';
    }
    return `Days since last ${person.churn.days_since_last} vs 2 × avg gap ${person.churn.avg_gap_days} = ${person.churn.threshold_days}.`;
}
</script>

<template>
    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-3 py-2">
                        <button
                            type="button"
                            class="font-medium"
                            @click="emit('sort', 'display_name')"
                        >
                            Name{{ sortIndicator('display_name') }}
                        </button>
                    </th>
                    <th class="px-3 py-2">
                        <button
                            type="button"
                            class="font-medium"
                            @click="emit('sort', 'frequency_label')"
                        >
                            Label{{ sortIndicator('frequency_label') }}
                        </button>
                    </th>
                    <th class="px-3 py-2">
                        <button
                            type="button"
                            class="font-medium"
                            @click="emit('sort', 'rfm_group')"
                        >
                            RFM{{ sortIndicator('rfm_group') }}
                        </button>
                    </th>
                    <th class="px-3 py-2 text-right">
                        <button
                            type="button"
                            class="font-medium"
                            @click="emit('sort', 'order_count')"
                        >
                            Orders{{ sortIndicator('order_count') }}
                        </button>
                    </th>
                    <th class="px-3 py-2 text-right">
                        <button type="button" class="font-medium" @click="emit('sort', 'spend')">
                            Spend{{ sortIndicator('spend') }}
                        </button>
                    </th>
                    <th class="px-3 py-2 text-right">
                        <span class="inline-flex items-center justify-end gap-1">
                            <button type="button" class="font-medium" @click="emit('sort', 'aov')">
                                AOV{{ sortIndicator('aov') }}
                            </button>
                            <ColumnHeaderHelp
                                label="Average order value for this person. Formula: AOV = spend ÷ orders. Spend is the sum of eligible subtotals before tax and shipping."
                            />
                        </span>
                    </th>
                    <th class="px-3 py-2">
                        <button
                            type="button"
                            class="font-medium"
                            @click="emit('sort', 'last_order_at')"
                        >
                            Last order{{ sortIndicator('last_order_at') }}
                        </button>
                    </th>
                    <th class="px-3 py-2">
                        <span class="inline-flex items-center gap-1">
                            <button
                                type="button"
                                class="font-medium"
                                @click="emit('sort', 'cadence_status')"
                            >
                                Cadence{{ sortIndicator('cadence_status') }}
                            </button>
                            <ColumnHeaderHelp :label="cadenceHelp()" />
                        </span>
                    </th>
                    <th class="px-3 py-2">
                        <span class="inline-flex items-center gap-1">
                            <button
                                type="button"
                                class="font-medium"
                                @click="emit('sort', 'churn_status')"
                            >
                                Own pace{{ sortIndicator('churn_status') }}
                            </button>
                            <ColumnHeaderHelp
                                label="Personal 2×-gap flag, kept for comparison. For 2+ dated orders: churned when days since last > 2 × that person’s own average gap. Starter hauls make this fire too early."
                            />
                        </span>
                    </th>
                    <th class="px-3 py-2 text-right">
                        <span class="inline-flex items-center justify-end gap-1">
                            <button
                                type="button"
                                class="font-medium"
                                @click="emit('sort', 'recency_score')"
                            >
                                R/F/M{{ sortIndicator('recency_score') }}
                            </button>
                            <RfmScoresHelp />
                        </span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="loading">
                    <td colspan="10" class="px-3 py-6 text-center text-slate-500">Loading…</td>
                </tr>
                <tr v-else-if="rows.length === 0">
                    <td colspan="10" class="px-3 py-6 text-center text-slate-500">
                        No identified people.
                    </td>
                </tr>
                <template v-for="person in rows" :key="person.id">
                    <tr class="border-t border-slate-100 hover:bg-slate-50">
                        <td class="px-3 py-2">
                            <button
                                type="button"
                                class="text-left font-medium text-slate-900"
                                @click="emit('toggle-person', person)"
                            >
                                {{ person.display_name }}
                            </button>
                            <a
                                v-if="person.shopify_admin_url"
                                :href="person.shopify_admin_url"
                                class="ml-2 text-xs text-slate-500 underline"
                                target="_blank"
                                rel="noreferrer"
                                >Shopify</a
                            >
                        </td>
                        <td class="px-3 py-2">
                            <span
                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="frequencyChipClass(person.frequency_label)"
                            >
                                {{ frequencyLabelText(person.frequency_label) }}
                            </span>
                        </td>
                        <td class="px-3 py-2">
                            <RfmGroupNameButton
                                :group-key="person.rfm_group"
                                :group-name="person.rfm_group_name"
                                :catalog="catalog"
                            />
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ person.order_count }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ formatRetentionMoney(person.spend, currency) }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ formatRetentionMoney(person.aov, currency) }}
                        </td>
                        <td class="px-3 py-2 text-slate-600">
                            {{ formatTorontoDateTimeCompact(person.last_order_at) }}
                        </td>
                        <td class="px-3 py-2">
                            <span
                                v-if="person.cadence"
                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="cadenceChipClass(person.cadence.status)"
                                :title="cadenceTitle(person)"
                            >
                                {{ cadenceLabel(person) }}
                            </span>
                            <span v-else class="text-slate-400" :title="cadenceTitle(person)"
                                >—</span
                            >
                        </td>
                        <td class="px-3 py-2">
                            <span
                                v-if="person.churn"
                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="churnChipClass(person.churn.status)"
                                :title="churnTitle(person)"
                            >
                                {{ churnLabel(person) }}
                            </span>
                            <span v-else class="text-slate-400" :title="churnTitle(person)">—</span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-slate-600">
                            {{ person.recency_score }}/{{ person.frequency_score }}/{{
                                person.monetary_score
                            }}
                        </td>
                    </tr>
                    <tr
                        v-if="expandedId === person.id"
                        class="border-t border-slate-100 bg-slate-50"
                    >
                        <td colspan="10" class="px-3 py-3">
                            <div v-if="ordersLoading" class="text-xs text-slate-500">
                                Loading orders…
                            </div>
                            <table v-else class="w-full text-xs">
                                <thead>
                                    <tr class="text-slate-500">
                                        <th class="py-1 text-left font-medium">Order</th>
                                        <th class="py-1 text-left font-medium">When</th>
                                        <th class="py-1 text-left font-medium">Channel</th>
                                        <th class="py-1 text-right font-medium">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="order in expandedOrders" :key="order.id">
                                        <td class="py-1">
                                            <a
                                                v-if="order.shopify_admin_url"
                                                :href="order.shopify_admin_url"
                                                class="underline"
                                                target="_blank"
                                                rel="noreferrer"
                                                >{{ order.name ?? `#${order.id}` }}</a
                                            >
                                            <span v-else>{{ order.name ?? `#${order.id}` }}</span>
                                        </td>
                                        <td class="py-1">
                                            {{ formatTorontoDateTimeCompact(order.ordered_at) }}
                                        </td>
                                        <td class="py-1">{{ order.channel_label ?? '—' }}</td>
                                        <td class="py-1 text-right">
                                            {{ formatRetentionMoney(order.subtotal, currency) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
