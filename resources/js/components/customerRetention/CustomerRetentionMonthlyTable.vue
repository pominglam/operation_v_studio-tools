<script setup lang="ts">
import { formatRepeatRate, formatRetentionMoney } from '../../lib/customerRetention';
import ColumnHeaderHelp from '../ColumnHeaderHelp.vue';
import type { CustomerRetentionMonthRow } from '../../types/customerRetention';

defineProps<{
    months: CustomerRetentionMonthRow[];
    currency: string;
}>();

function monthLabel(month: string): string {
    const [yearText, monthText] = month.split('-');
    const date = new Date(Number(yearText), Number(monthText) - 1, 1);
    return date.toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
}

function barWidth(part: number, whole: number): string {
    if (whole <= 0) return '0%';
    return `${Math.round((part / whole) * 100)}%`;
}
</script>

<template>
    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-100 px-3 py-2">
            <h2 class="text-sm font-semibold text-slate-900">Month by month</h2>
            <p class="mt-0.5 text-xs text-slate-500">
                New = first identified order that month (acquisition). Returning = bought again
                after an earlier month. New spend / Returning spend split that month’s identified
                revenue the same way. Months use Eastern Time (Montreal). Recent “now 2+” rates stay
                low until people have had time to come back.
            </p>
        </div>
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-3 py-2 font-medium">Month</th>
                    <th class="px-3 py-2 text-right font-medium">New</th>
                    <th class="px-3 py-2 text-right font-medium">Returning</th>
                    <th class="min-w-[8rem] px-3 py-2 font-medium">Mix</th>
                    <th class="px-3 py-2 text-right font-medium">
                        <span class="inline-flex items-center gap-1">
                            Return rate
                            <ColumnHeaderHelp
                                label="Share of identified buyers that month who already had an earlier order. A busy new-customer month can look low even if regulars still came."
                            />
                        </span>
                    </th>
                    <th class="px-3 py-2 text-right font-medium">
                        <span class="inline-flex items-center gap-1">
                            Those new, now 2+
                            <ColumnHeaderHelp
                                label="Of people whose first order was that month, how many have a second order by today. Newer months will look weaker until more time passes."
                            />
                        </span>
                    </th>
                    <th class="px-3 py-2 text-right font-medium">
                        <span class="inline-flex items-center justify-end gap-1">
                            Orders
                            <ColumnHeaderHelp
                                label="Count of identified eligible Shopify orders whose order time falls in this calendar month (Eastern Time, Montreal). Eligible = not cancelled and not voided, same as Orders. One person can add more than one order in the same month. Formula: orders = count of identified eligible orders in the month."
                            />
                        </span>
                    </th>
                    <th class="px-3 py-2 text-right font-medium">
                        <span class="inline-flex items-center justify-end gap-1">
                            Spend
                            <ColumnHeaderHelp
                                label="Sum of those orders’ subtotal before tax and shipping (subtotal_shop_amount). Unidentified Quick Sale tickets are not included. Formula: spend = Σ order.subtotal for identified eligible orders in the month."
                            />
                        </span>
                    </th>
                    <th class="px-3 py-2 text-right font-medium">
                        <span class="inline-flex items-center justify-end gap-1">
                            New spend
                            <ColumnHeaderHelp
                                label="Identified spend that month from people whose first eligible order is that month. A second ticket in the same first month still counts as New spend. New spend + Returning spend = Spend."
                            />
                        </span>
                    </th>
                    <th class="px-3 py-2 text-right font-medium">
                        <span class="inline-flex items-center justify-end gap-1">
                            Returning spend
                            <ColumnHeaderHelp
                                label="Identified spend that month from people who already had an eligible order in an earlier month. Regulars carrying the month show up here."
                            />
                        </span>
                    </th>
                    <th class="min-w-[8rem] px-3 py-2 font-medium">Spend mix</th>
                    <th class="px-3 py-2 text-right font-medium">
                        <span class="inline-flex items-center justify-end gap-1">
                            AOV
                            <ColumnHeaderHelp
                                label="Average order value for that month. Formula: AOV = spend ÷ orders. Same identified eligible rows as the Orders and Spend columns."
                            />
                        </span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in months" :key="row.month" class="border-t border-slate-100">
                    <td class="px-3 py-2 font-medium text-slate-900">
                        {{ monthLabel(row.month) }}
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ row.acquired }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ row.returning_buyers }}</td>
                    <td class="px-3 py-2">
                        <div class="flex h-2 overflow-hidden rounded bg-slate-100">
                            <div
                                class="bg-sky-400"
                                :style="{ width: barWidth(row.acquired, row.buyers) }"
                                title="New"
                            />
                            <div
                                class="bg-emerald-500"
                                :style="{ width: barWidth(row.returning_buyers, row.buyers) }"
                                title="Returning"
                            />
                        </div>
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">
                        {{ formatRepeatRate(row.buyer_return_rate) }}
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums text-slate-600">
                        {{ row.acquired > 0 ? `${row.acquired_now_repeat}/${row.acquired}` : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ row.orders }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">
                        {{ formatRetentionMoney(row.spend, currency) }}
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">
                        {{ formatRetentionMoney(row.acquired_spend, currency) }}
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">
                        {{ formatRetentionMoney(row.returning_spend, currency) }}
                    </td>
                    <td class="px-3 py-2">
                        <div class="flex h-2 overflow-hidden rounded bg-slate-100">
                            <div
                                class="bg-sky-400"
                                :style="{
                                    width: barWidth(Number(row.acquired_spend), Number(row.spend)),
                                }"
                                title="New spend"
                            />
                            <div
                                class="bg-emerald-500"
                                :style="{
                                    width: barWidth(Number(row.returning_spend), Number(row.spend)),
                                }"
                                title="Returning spend"
                            />
                        </div>
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">
                        {{ row.orders > 0 ? formatRetentionMoney(row.aov, currency) : '—' }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
