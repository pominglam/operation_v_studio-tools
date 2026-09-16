<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import { formatMoney2OrEmpty } from '../../lib/money';
import { formatTorontoDateTime } from '../../lib/datetime';

import type { SpecialOrder } from '../../types/specialOrders';

const props = defineProps<{
    order: SpecialOrder | null;
    isOfferLocked: boolean;
    saving: boolean;
    saveError: string | null;
}>();

const emit = defineEmits<{
    save: [amountCad: string];
    edit: [];
}>();

const cashReceivedInput = ref('');
const isEditingCash = ref(false);

watch(
    () => props.order?.cash_received_cad,
    (value) => {
        if (isEditingCash.value) {
            return;
        }

        cashReceivedInput.value = value ?? '';
    },
    { immediate: true },
);

const showCard = computed(() => props.isOfferLocked);

const customerPriceCad = computed((): number | null => {
    const price = Number(String(props.order?.customer_price_cad ?? '').trim());
    return Number.isFinite(price) && price > 0 ? price : null;
});

const depositDueCad = computed((): number | null => {
    const deposit = Number(String(props.order?.deposit_amount_cad ?? '').trim());
    return Number.isFinite(deposit) && deposit >= 0 ? deposit : null;
});

const savedCashCad = computed((): number => {
    const cash = Number(String(props.order?.cash_received_cad ?? '').trim());
    return Number.isFinite(cash) && cash >= 0 ? cash : 0;
});

const inputCashCad = computed((): number | null => {
    const trimmed = cashReceivedInput.value.trim();
    if (trimmed === '') {
        return null;
    }

    const cash = Number(trimmed);
    return Number.isFinite(cash) && cash >= 0 ? cash : null;
});

const remainingCad = computed((): number | null => {
    if (customerPriceCad.value == null || inputCashCad.value == null) {
        return customerPriceCad.value;
    }

    return Math.max(0, customerPriceCad.value - inputCashCad.value);
});

const isPaidInFull = computed((): boolean => {
    if (props.order?.balance_received_at != null) {
        return true;
    }

    if (customerPriceCad.value == null || savedCashCad.value <= 0) {
        return false;
    }

    return savedCashCad.value >= customerPriceCad.value;
});

const paymentStatusLabel = computed((): string | null => {
    if (customerPriceCad.value == null || savedCashCad.value <= 0) {
        return null;
    }

    const received = savedCashCad.value;
    const total = customerPriceCad.value;
    const when = props.order?.cash_received_at
        ? formatTorontoDateTime(props.order.cash_received_at)
        : null;
    const whenSuffix = when != null ? ` ${when}` : '';

    if (received >= total) {
        return `Paid in full (cash)${whenSuffix}`;
    }

    if (depositDueCad.value != null && depositDueCad.value > 0 && received >= depositDueCad.value) {
        return `Deposit covered (cash)${whenSuffix}`;
    }

    return `Partial cash ${formatMoney2OrEmpty(String(received))}${when != null ? ` · ${when}` : ''}`;
});

function onCashFocus(): void {
    isEditingCash.value = true;
    emit('edit');
}

function saveCashReceived(): void {
    const trimmed = cashReceivedInput.value.trim();
    emit('save', trimmed === '' ? '0' : trimmed);
}

function onSaveClick(): void {
    saveCashReceived();
}

watch(
    () => props.saving,
    (saving, wasSaving) => {
        if (wasSaving && !saving && props.saveError == null) {
            isEditingCash.value = false;
            cashReceivedInput.value = props.order?.cash_received_cad ?? '';
        }
    },
);
</script>

<template>
    <article v-if="showCard" class="cao-detail__milestone-card cao-detail__milestone-card--cash">
        <div class="cao-detail__milestone-head">
            <span class="po-beta__label">Cash payment</span>
            <span v-if="paymentStatusLabel" class="cao-detail__milestone-done">
                {{ paymentStatusLabel }}
            </span>
        </div>
        <p class="po-beta__hint">
            <template v-if="isPaidInFull">
                Customer paid in full in store — no Shopify invoice.
            </template>
            <template v-else>
                No Shopify invoice — enter <strong>total cash collected so far</strong>, then Save. Update
                when the customer pays more.
                <template v-if="customerPriceCad != null">
                    Total due {{ formatMoney2OrEmpty(String(customerPriceCad)) }} CAD
                    <template v-if="depositDueCad != null && depositDueCad > 0">
                        · deposit {{ formatMoney2OrEmpty(String(depositDueCad)) }} CAD
                    </template>
                </template>
            </template>
        </p>
        <p v-if="isPaidInFull && customerPriceCad != null" class="po-beta__hint cao-detail__cash-payment-summary">
            Recorded {{ formatMoney2OrEmpty(String(savedCashCad)) }} of
            {{ formatMoney2OrEmpty(String(customerPriceCad)) }} CAD · paid in full
        </p>
        <div v-else class="cao-detail__cash-payment-row">
            <label class="cao-detail__cash-payment-field">
                <span class="po-beta__label">Cash received (CAD)</span>
                <input
                    v-model="cashReceivedInput"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    class="cao-detail__pricing-summary-input cao-detail__cash-payment-input"
                    :disabled="saving"
                    @focus="onCashFocus"
                    @keydown.enter.prevent="onSaveClick"
                />
            </label>
            <button
                type="button"
                class="po-beta__btn po-beta__btn--compact"
                :disabled="saving"
                @mousedown.prevent
                @click="onSaveClick"
            >
                {{ saving ? 'Saving…' : 'Save' }}
            </button>
        </div>
        <p v-if="!isPaidInFull && saveError" class="po-beta__notice po-beta__notice--error cao-detail__cash-payment-error">
            {{ saveError }}
        </p>
        <p
            v-if="!isPaidInFull && customerPriceCad != null"
            class="po-beta__hint cao-detail__cash-payment-summary"
        >
            <template v-if="inputCashCad != null">
                Received {{ formatMoney2OrEmpty(String(inputCashCad)) }} of
                {{ formatMoney2OrEmpty(String(customerPriceCad)) }} CAD
                <template v-if="remainingCad != null && remainingCad > 0">
                    · {{ formatMoney2OrEmpty(String(remainingCad)) }} remaining
                </template>
                <template v-else-if="inputCashCad >= customerPriceCad"> · paid in full</template>
            </template>
            <template v-else-if="savedCashCad > 0">
                Recorded {{ formatMoney2OrEmpty(String(savedCashCad)) }} of
                {{ formatMoney2OrEmpty(String(customerPriceCad)) }} CAD
            </template>
        </p>
    </article>
</template>
