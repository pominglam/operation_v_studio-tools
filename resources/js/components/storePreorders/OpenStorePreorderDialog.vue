<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { formatTorontoDate } from '../../lib/datetime';
import { formatMoney2 } from '../../lib/money';
import {
    estimatedLandedFromCost,
    formatShippingPercentLabel,
    multiplierFromSellAndLanded,
} from '../../lib/storePreorderPricing';

export type StorePreorderOpenItem = {
    sku: string;
    product_name: string;
    unit_selling_price: string | null;
    price_preorder: string | null;
    price_stock: string | null;
    po_due_date: string | null;
};

const props = defineProps<{
    open: boolean;
    count: number;
    items: StorePreorderOpenItem[];
    priceBySku: Record<string, string>;
    depositBySku: Record<string, string>;
    capBySku: Record<string, string>;
    closingBySku: Record<string, string>;
    defaultDepositPercent: string;
    shippingPercent: number;
    busy?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:price', sku: string, value: string): void;
    (e: 'update:deposit', sku: string, value: string): void;
    (e: 'update:cap', sku: string, value: string): void;
    (e: 'update:closing', sku: string, value: string): void;
    (e: 'confirm'): void;
    (e: 'cancel'): void;
}>();

const title = computed(() =>
    props.count === 1
        ? 'Open 1 store preorder and push to Shopify?'
        : `Open ${props.count} store preorders and push to Shopify?`,
);

function priceFor(item: StorePreorderOpenItem): string {
    return props.priceBySku[item.sku] ?? item.unit_selling_price ?? '';
}

function depositFor(item: StorePreorderOpenItem): string {
    return props.depositBySku[item.sku] ?? props.defaultDepositPercent;
}

function capFor(item: StorePreorderOpenItem): string {
    return props.capBySku[item.sku] ?? '';
}

function closingFor(item: StorePreorderOpenItem): string {
    return props.closingBySku[item.sku] ?? '';
}

function poCost(item: StorePreorderOpenItem): string | null {
    return item.price_preorder ?? item.price_stock;
}

function landedFor(item: StorePreorderOpenItem): string | null {
    return estimatedLandedFromCost(poCost(item), props.shippingPercent);
}

function multiplierFor(item: StorePreorderOpenItem): string | null {
    return multiplierFromSellAndLanded(priceFor(item), landedFor(item));
}

function depositAmount(item: StorePreorderOpenItem): string {
    const price = Number(priceFor(item));
    const percent = Number(depositFor(item));
    if (!Number.isFinite(price) || price <= 0 || !Number.isFinite(percent) || percent <= 0) {
        return '';
    }

    return (Math.round(price * (percent / 100) * 100) / 100).toFixed(2);
}

function onKeyDown(e: KeyboardEvent): void {
    if (!props.open) return;
    if (e.key === 'Escape') emit('cancel');
}

onMounted(() => {
    window.addEventListener('keydown', onKeyDown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeyDown);
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
            role="dialog"
            aria-modal="true"
            data-testid="open-store-preorder-dialog"
            @click.self="emit('cancel')"
        >
            <div class="w-full max-w-3xl rounded-lg bg-white p-4 shadow-xl">
                <div class="text-sm font-semibold text-slate-900">{{ title }}</div>
                <p class="mt-1 text-sm text-slate-600">
                    Adds these kits to Store preorders, creates an ERP product if the SKU is new,
                    and queues a Shopify publish to /collections/pre-orders (tag + deposit price).
                    Photos follow when the Plamod crawl finishes. Sell $, deposit, cap, and closing
                    are per kit. Closing defaults to one day before Plamod due so you have 24h to
                    place the distributor order.
                </p>
                <ul
                    class="mt-3 max-h-[28rem] space-y-3 overflow-y-auto rounded-md border border-slate-200 bg-slate-50 p-2"
                    data-testid="open-store-preorder-items"
                >
                    <li
                        v-for="item in items"
                        :key="item.sku"
                        class="rounded bg-white px-3 py-2 text-sm"
                    >
                        <div class="font-medium text-slate-900">{{ item.product_name }}</div>
                        <div class="text-xs text-slate-600">
                            {{ item.sku }}
                            <span v-if="depositAmount(item)">
                                · Deposit ${{ depositAmount(item) }}
                            </span>
                            <span v-if="item.po_due_date">
                                · Plamod due {{ formatTorontoDate(item.po_due_date) }}
                            </span>
                        </div>
                        <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <label class="block text-xs text-slate-600">
                                Sell $
                                <input
                                    :value="priceFor(item)"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    class="mt-0.5 h-8 w-full rounded-md border border-slate-300 px-2 text-sm text-slate-900"
                                    :data-testid="`open-store-preorder-price-${item.sku}`"
                                    @input="
                                        emit(
                                            'update:price',
                                            item.sku,
                                            ($event.target as HTMLInputElement).value,
                                        )
                                    "
                                />
                            </label>
                            <label class="block text-xs text-slate-600">
                                Deposit %
                                <input
                                    :value="depositFor(item)"
                                    type="number"
                                    min="1"
                                    max="100"
                                    step="1"
                                    class="mt-0.5 h-8 w-full rounded-md border border-slate-300 px-2 text-sm text-slate-900"
                                    :data-testid="`open-store-preorder-deposit-${item.sku}`"
                                    @input="
                                        emit(
                                            'update:deposit',
                                            item.sku,
                                            ($event.target as HTMLInputElement).value,
                                        )
                                    "
                                />
                            </label>
                            <label class="block text-xs text-slate-600">
                                Cap
                                <input
                                    :value="capFor(item)"
                                    type="number"
                                    min="1"
                                    step="1"
                                    placeholder="No cap"
                                    class="mt-0.5 h-8 w-full rounded-md border border-slate-300 px-2 text-sm text-slate-900"
                                    :data-testid="`open-store-preorder-cap-${item.sku}`"
                                    @input="
                                        emit(
                                            'update:cap',
                                            item.sku,
                                            ($event.target as HTMLInputElement).value,
                                        )
                                    "
                                />
                            </label>
                            <label class="block text-xs text-slate-600">
                                Closing date
                                <input
                                    :value="closingFor(item)"
                                    type="date"
                                    class="mt-0.5 h-8 w-full rounded-md border border-slate-300 px-2 text-sm text-slate-900"
                                    :data-testid="`open-store-preorder-closing-${item.sku}`"
                                    @input="
                                        emit(
                                            'update:closing',
                                            item.sku,
                                            ($event.target as HTMLInputElement).value,
                                        )
                                    "
                                />
                            </label>
                        </div>
                        <div
                            class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600"
                            :data-testid="`open-store-preorder-costing-${item.sku}`"
                        >
                            <span>
                                Landed
                                <span class="font-medium text-slate-900">{{
                                    landedFor(item) ? `$${formatMoney2(landedFor(item))}` : '—'
                                }}</span>
                                <span class="text-slate-500">
                                    ({{ formatShippingPercentLabel(shippingPercent) }} ship)
                                </span>
                            </span>
                            <span :data-testid="`open-store-preorder-mult-${item.sku}`">
                                Mult.
                                <span class="font-medium text-slate-900">{{
                                    multiplierFor(item) ? `${multiplierFor(item)}×` : '—'
                                }}</span>
                            </span>
                        </div>
                    </li>
                </ul>

                <div class="mt-4 flex items-center justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                        :disabled="busy"
                        @click="emit('cancel')"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="busy || count < 1"
                        data-testid="open-store-preorder-confirm"
                        @click="emit('confirm')"
                    >
                        {{ busy ? 'Opening and pushing…' : 'Open & push to Shopify' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
