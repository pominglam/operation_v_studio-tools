<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api } from '../../lib/api';
import { shopifyInvoiceLineTitle } from '../../lib/specialOrderShopifyInvoice';
import type { SpecialOrder } from '../../types/specialOrders';
import type { ShopifyCustomerSuggestion } from '../../types/specialShopifyInvoices';

const props = defineProps<{
    open: boolean;
    kind: 'deposit' | 'balance';
    order: SpecialOrder;
    busy?: boolean;
}>();

const emit = defineEmits<{
    close: [];
    created: [order: SpecialOrder];
}>();

const lineTitle = ref('');
const customerMode = ref<'search' | 'create'>('search');
const customerQuery = ref('');
const customerSuggestions = ref<ShopifyCustomerSuggestion[]>([]);
const customerSuggestOpen = ref(false);
const customerSuggestLoading = ref(false);
const selectedCustomer = ref<ShopifyCustomerSuggestion | null>(null);
const customerEmail = ref('');
const customerFirstName = ref('');
const customerLastName = ref('');
const sendInvoice = ref(true);
const submitting = ref(false);
const error = ref<string | null>(null);

let suggestTimer: ReturnType<typeof setTimeout> | null = null;

const dialogTitle = computed(() =>
    props.kind === 'deposit' ? 'Create Shopify deposit invoice' : 'Create Shopify balance invoice',
);

const invoiceAmountCad = computed(() =>
    props.kind === 'deposit' ? props.order.deposit_amount_cad : props.order.balance_cad,
);

const needsCustomerPicker = computed(() => {
    if (props.kind === 'balance' && props.order.shopify_invoices?.customer_gid) {
        return false;
    }

    return true;
});
const summaryNote = computed(() => {
    if (props.kind === 'deposit') {
        return `Line item will be invoiced at the deposit amount ($${props.order.deposit_amount_cad ?? '—'} CAD). Total quoted $${props.order.customer_price_cad ?? '—'}; balance $${props.order.balance_cad ?? '—'} is a separate invoice.`;
    }

    return `Line item will be invoiced at the balance amount ($${props.order.balance_cad ?? '—'} CAD). Deposit invoice: ${props.order.shopify_invoices?.deposit?.draft_order_name ?? '—'}.`;
});

function resetForm(): void {
    lineTitle.value = shopifyInvoiceLineTitle(props.kind, props.order.product_name);
    customerMode.value = 'search';
    customerQuery.value = '';
    customerSuggestions.value = [];
    customerSuggestOpen.value = false;
    selectedCustomer.value = null;
    customerEmail.value = '';
    customerFirstName.value = '';
    customerLastName.value = '';
    sendInvoice.value = true;
    error.value = null;
    submitting.value = false;
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            resetForm();
        }
    },
);

watch(customerQuery, (value) => {
    selectedCustomer.value = null;
    if (suggestTimer !== null) {
        clearTimeout(suggestTimer);
    }
    const trimmed = value.trim();
    if (trimmed.length < 2) {
        customerSuggestions.value = [];
        customerSuggestOpen.value = false;
        return;
    }
    suggestTimer = setTimeout(() => {
        void loadCustomerSuggestions(trimmed);
    }, 250);
});

async function loadCustomerSuggestions(query: string): Promise<void> {
    customerSuggestLoading.value = true;
    try {
        const res = await api.get<{ data: ShopifyCustomerSuggestion[] }>(
            '/api/v1/shopify/customers/suggest',
            { params: { q: query, limit: 8 }, validateStatus: () => true },
        );
        if (res.status !== 200) {
            customerSuggestions.value = [];
            return;
        }
        customerSuggestions.value = res.data.data ?? [];
        customerSuggestOpen.value = customerSuggestions.value.length > 0;
    } finally {
        customerSuggestLoading.value = false;
    }
}

function pickCustomer(customer: ShopifyCustomerSuggestion): void {
    selectedCustomer.value = customer;
    customerQuery.value = customer.email ?? customer.display_name ?? customer.gid;
    customerSuggestOpen.value = false;
}

function buildPayload(): Record<string, unknown> {
    const payload: Record<string, unknown> = {
        line_title: lineTitle.value.trim(),
        send_invoice: sendInvoice.value,
    };

    if (!needsCustomerPicker.value) {
        return payload;
    }

    if (customerMode.value === 'search') {
        if (!selectedCustomer.value?.gid) {
            throw new Error('Select a Shopify customer.');
        }
        payload.shopify_customer_gid = selectedCustomer.value.gid;
    } else {
        payload.customer_email = customerEmail.value.trim();
        payload.customer_first_name = customerFirstName.value.trim();
        payload.customer_last_name = customerLastName.value.trim();
    }

    return payload;
}

async function submit(): Promise<void> {
    error.value = null;
    submitting.value = true;
    try {
        const payload = buildPayload();
        const path =
            props.kind === 'deposit'
                ? `/api/v1/special-orders/${props.order.id}/shopify-deposit-invoice`
                : `/api/v1/special-orders/${props.order.id}/shopify-balance-invoice`;
        const res = await api.post<{ data: SpecialOrder }>(path, payload, {
            validateStatus: () => true,
        });
        if (res.status !== 200) {
            const message =
                (res.data as { message?: string })?.message ?? 'Failed to create Shopify invoice.';
            error.value = message;
            return;
        }
        emit('created', res.data.data);
        emit('close');
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Failed to create Shopify invoice.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div
        v-if="open"
        class="cao-shopify-invoice-dialog__backdrop"
        role="presentation"
        @click="emit('close')"
    >
        <div
            class="cao-shopify-invoice-dialog"
            role="dialog"
            aria-modal="true"
            :aria-label="dialogTitle"
            @click.stop
        >
            <header class="cao-shopify-invoice-dialog__head">
                <h3 class="cao-shopify-invoice-dialog__title">{{ dialogTitle }}</h3>
                <button type="button" class="cao-shopify-invoice-dialog__close" @click="emit('close')">
                    ×
                </button>
            </header>

            <div class="cao-shopify-invoice-dialog__body">
                <p class="po-beta__hint">{{ summaryNote }}</p>

                <label class="cao-shopify-invoice-dialog__field">
                    <span class="po-beta__label">Line item title</span>
                    <input
                        v-model="lineTitle"
                        type="text"
                        class="po-beta__control"
                        maxlength="255"
                    />
                </label>

                <div class="cao-shopify-invoice-dialog__amounts">
                    <div>
                        <span class="po-beta__label">Total quoted</span>
                        <div>${{ order.customer_price_cad ?? '—' }} CAD</div>
                    </div>
                    <div>
                        <span class="po-beta__label">This invoice</span>
                        <div>${{ invoiceAmountCad ?? '—' }} CAD</div>
                    </div>
                </div>

                <div v-if="kind === 'balance' && order.shopify_invoices?.deposit" class="po-beta__hint">
                    Deposit draft:
                    <a
                        v-if="order.shopify_invoices.deposit.admin_url"
                        :href="order.shopify_invoices.deposit.admin_url"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        {{ order.shopify_invoices.deposit.draft_order_name ?? 'Open in Shopify' }}
                    </a>
                </div>

                <div v-if="needsCustomerPicker" class="cao-shopify-invoice-dialog__customer-mode">
                    <label class="cao-shopify-invoice-dialog__mode-option">
                        <input v-model="customerMode" type="radio" value="search" />
                        Search customer
                    </label>
                    <label class="cao-shopify-invoice-dialog__mode-option">
                        <input v-model="customerMode" type="radio" value="create" />
                        Create customer
                    </label>
                </div>

                <div v-if="needsCustomerPicker && customerMode === 'search'" class="cao-shopify-invoice-dialog__field">
                    <span class="po-beta__label">Shopify customer</span>
                    <div class="cao-shopify-invoice-dialog__suggest-wrap">
                        <input
                            v-model="customerQuery"
                            type="text"
                            class="po-beta__control"
                            placeholder="Email or name"
                            autocomplete="off"
                            @focus="customerSuggestOpen = customerSuggestions.length > 0"
                        />
                        <ul
                            v-if="customerSuggestOpen"
                            class="cao-shopify-invoice-dialog__suggest-list"
                        >
                            <li v-if="customerSuggestLoading" class="po-beta__hint">Searching…</li>
                            <li
                                v-for="customer in customerSuggestions"
                                :key="customer.gid"
                                class="cao-shopify-invoice-dialog__suggest-item"
                            >
                                <button type="button" @click="pickCustomer(customer)">
                                    <span>{{ customer.display_name ?? 'Customer' }}</span>
                                    <span v-if="customer.email" class="po-beta__hint">{{
                                        customer.email
                                    }}</span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>

                <template v-else-if="needsCustomerPicker">
                    <label class="cao-shopify-invoice-dialog__field">
                        <span class="po-beta__label">Email</span>
                        <input v-model="customerEmail" type="email" class="po-beta__control" />
                    </label>
                    <div class="cao-shopify-invoice-dialog__name-row">
                        <label class="cao-shopify-invoice-dialog__field">
                            <span class="po-beta__label">First name</span>
                            <input v-model="customerFirstName" type="text" class="po-beta__control" />
                        </label>
                        <label class="cao-shopify-invoice-dialog__field">
                            <span class="po-beta__label">Last name</span>
                            <input v-model="customerLastName" type="text" class="po-beta__control" />
                        </label>
                    </div>
                </template>

                <p v-else class="po-beta__hint">
                    Uses the same Shopify customer as the deposit invoice.
                </p>

                <label class="cao-shopify-invoice-dialog__checkbox">
                    <input v-model="sendInvoice" type="checkbox" />
                    Send invoice email to customer
                </label>

                <p v-if="error" class="cao-shopify-invoice-dialog__error">{{ error }}</p>
            </div>

            <footer class="cao-shopify-invoice-dialog__foot">
                <button
                    type="button"
                    class="po-beta__btn po-beta__btn--ghost"
                    :disabled="submitting"
                    @click="emit('close')"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="po-beta__btn"
                    :disabled="submitting || busy"
                    @click="submit"
                >
                    {{ submitting ? 'Creating…' : 'Create invoice' }}
                </button>
            </footer>
        </div>
    </div>
</template>

<style scoped>
.cao-shopify-invoice-dialog__backdrop {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgb(15 23 42 / 0.45);
    padding: 1rem;
}

.cao-shopify-invoice-dialog {
    width: min(100%, 32rem);
    max-height: min(90vh, 720px);
    overflow: auto;
    border-radius: 0.75rem;
    border: 1px solid rgb(226 232 240);
    background: white;
    box-shadow: 0 20px 40px rgb(15 23 42 / 0.15);
}

.cao-shopify-invoice-dialog__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 1rem 1rem 0.5rem;
}

.cao-shopify-invoice-dialog__title {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: rgb(15 23 42);
}

.cao-shopify-invoice-dialog__close {
    border: 0;
    background: transparent;
    font-size: 1.5rem;
    line-height: 1;
    color: rgb(100 116 139);
    cursor: pointer;
}

.cao-shopify-invoice-dialog__body {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    padding: 0.5rem 1rem 1rem;
}

.cao-shopify-invoice-dialog__field {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.cao-shopify-invoice-dialog__amounts {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
    font-size: 0.9375rem;
    color: rgb(15 23 42);
}

.cao-shopify-invoice-dialog__customer-mode {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem 1rem;
}

.cao-shopify-invoice-dialog__mode-option {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.8125rem;
    color: rgb(51 65 85);
}

.cao-shopify-invoice-dialog__name-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

.cao-shopify-invoice-dialog__suggest-wrap {
    position: relative;
}

.cao-shopify-invoice-dialog__suggest-list {
    position: absolute;
    top: calc(100% + 0.25rem);
    left: 0;
    right: 0;
    z-index: 2;
    margin: 0;
    padding: 0.25rem 0;
    list-style: none;
    border: 1px solid rgb(226 232 240);
    border-radius: 0.5rem;
    background: white;
    box-shadow: 0 8px 20px rgb(15 23 42 / 0.12);
    max-height: 12rem;
    overflow: auto;
}

.cao-shopify-invoice-dialog__suggest-item button {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    width: 100%;
    border: 0;
    background: transparent;
    padding: 0.5rem 0.75rem;
    text-align: left;
    cursor: pointer;
}

.cao-shopify-invoice-dialog__suggest-item button:hover {
    background: rgb(248 250 252);
}

.cao-shopify-invoice-dialog__checkbox {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
    color: rgb(51 65 85);
}

.cao-shopify-invoice-dialog__error {
    margin: 0;
    padding: 0.5rem 0.75rem;
    border-radius: 0.375rem;
    border: 1px solid rgb(254 202 202);
    background: rgb(254 242 242);
    color: rgb(153 27 27);
    font-size: 0.875rem;
}

.cao-shopify-invoice-dialog__foot {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    padding: 0.75rem 1rem 1rem;
    border-top: 1px solid rgb(241 245 249);
}
</style>
