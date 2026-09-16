<script setup lang="ts">
import { computed } from 'vue';
import { formatTorontoDateTime } from '../../lib/datetime';
import type { SpecialOrder } from '../../types/specialOrders';

const props = defineProps<{
    order: SpecialOrder | null;
    mode: 'deposit' | 'balance';
    canCreateDeposit: boolean;
    canCreateBalance: boolean;
    isOfferLocked: boolean;
    isPriced: boolean;
    isProductReceived: boolean;
    isPaidInFull: boolean;
    isDepositReceived: boolean;
    clearing?: boolean;
}>();

const emit = defineEmits<{
    createDeposit: [];
    createBalance: [];
    clearDeposit: [];
    clearBalance: [];
}>();

const showCard = computed(() => {
    if (!props.isOfferLocked || !props.isPriced) {
        return false;
    }

    if (props.mode === 'deposit') {
        if (props.order?.shopify_invoices?.deposit != null) {
            return true;
        }

        if (props.isPaidInFull || props.isDepositReceived) {
            return false;
        }

        return props.canCreateDeposit;
    }

    if (props.order?.shopify_invoices?.balance != null) {
        return true;
    }

    if (props.isPaidInFull) {
        return false;
    }

    return (
        props.order?.shopify_invoices?.deposit != null ||
        props.order?.shopify_invoices?.balance != null ||
        props.isProductReceived
    );
});

const balanceBlockedHint = computed(() => {
    if (props.mode !== 'balance') {
        return null;
    }

    if (props.order?.shopify_invoices?.balance) {
        return null;
    }

    if (props.order?.shopify_invoices?.deposit == null) {
        return 'Create the deposit invoice first.';
    }

    if (!props.isProductReceived) {
        return 'Available after the product is marked in hand at OPV.';
    }

    return null;
});
</script>

<template>
    <article v-if="showCard" class="cao-detail__milestone-card">
        <template v-if="mode === 'deposit'">
            <div class="cao-detail__milestone-head">
                <span class="po-beta__label">Shopify deposit invoice</span>
                <span
                    v-if="order?.shopify_invoices?.deposit?.sent_at"
                    class="cao-detail__milestone-done"
                >
                    Sent {{ formatTorontoDateTime(order.shopify_invoices.deposit.sent_at) }}
                </span>
            </div>
            <p class="po-beta__hint">
                Custom line item on a Shopify draft order; customer pays the deposit amount.
            </p>
            <div class="cao-detail__shopify-invoice-actions">
                <template v-if="order?.shopify_invoices?.deposit">
                    <a
                        v-if="order.shopify_invoices.deposit.admin_url"
                        class="po-beta__btn po-beta__btn--compact po-beta__btn--ghost"
                        :href="order.shopify_invoices.deposit.admin_url"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        {{
                            order.shopify_invoices.deposit.draft_order_name ??
                            'Open deposit in Shopify'
                        }}
                    </a>
                    <button
                        type="button"
                        class="po-beta__btn po-beta__btn--compact po-beta__btn--ghost"
                        :disabled="clearing"
                        @click="emit('clearDeposit')"
                    >
                        {{ clearing ? 'Removing…' : 'Remove invoice record' }}
                    </button>
                </template>
                <button
                    v-else-if="canCreateDeposit"
                    type="button"
                    class="po-beta__btn po-beta__btn--compact"
                    @click="emit('createDeposit')"
                >
                    Create deposit invoice in Shopify
                </button>
            </div>
        </template>

        <template v-else>
            <div class="cao-detail__milestone-head">
                <span class="po-beta__label">Shopify balance invoice</span>
                <span
                    v-if="order?.shopify_invoices?.balance?.sent_at"
                    class="cao-detail__milestone-done"
                >
                    Sent {{ formatTorontoDateTime(order.shopify_invoices.balance.sent_at) }}
                </span>
            </div>
            <p class="po-beta__hint">
                Second draft order for the remaining balance — create after the kit is in hand.
            </p>
            <div class="cao-detail__shopify-invoice-actions">
                <template v-if="order?.shopify_invoices?.balance">
                    <a
                        v-if="order.shopify_invoices.balance.admin_url"
                        class="po-beta__btn po-beta__btn--compact po-beta__btn--ghost"
                        :href="order.shopify_invoices.balance.admin_url"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        {{
                            order.shopify_invoices.balance.draft_order_name ??
                            'Open balance in Shopify'
                        }}
                    </a>
                    <button
                        type="button"
                        class="po-beta__btn po-beta__btn--compact po-beta__btn--ghost"
                        :disabled="clearing"
                        @click="emit('clearBalance')"
                    >
                        {{ clearing ? 'Removing…' : 'Remove invoice record' }}
                    </button>
                </template>
                <button
                    v-else-if="canCreateBalance"
                    type="button"
                    class="po-beta__btn po-beta__btn--compact"
                    @click="emit('createBalance')"
                >
                    Create balance invoice in Shopify
                </button>
                <p v-else-if="balanceBlockedHint" class="po-beta__hint">
                    {{ balanceBlockedHint }}
                </p>
            </div>
        </template>
    </article>
</template>
