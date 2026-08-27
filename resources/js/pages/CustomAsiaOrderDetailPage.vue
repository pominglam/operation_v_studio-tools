<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import '../../css/po-beta.css';
import '../../css/custom-asia-order-detail.css';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import { api } from '../lib/api';
import { formatTorontoDate, formatTorontoDateTime } from '../lib/datetime';
import { formatFxCadToForeignLabel, formatMoney2OrEmpty } from '../lib/money';
import {
    customAsiaOrderWorkflowStatusIsPending,
    customAsiaOrderWorkflowStatusLabel,
    resolveCustomAsiaOrderWorkflowStatus,
} from '../lib/customAsiaOrderWorkflow';
import {
    buildCustomAsiaPricingSummary,
    formatPricingSummaryCell,
    pricingSummaryHasValues,
    pricingSummaryShowsOriginalColumn,
} from '../lib/customAsiaOrderPricingSummary';
import {
    buildCustomAsiaReconciliationSummary,
    formatReconciliationSummaryCell,
    reconciliationSummaryShowsOriginalColumn,
} from '../lib/customAsiaOrderReconciliationSummary';
import { buildCustomAsiaOrderCustomerMessage } from '../lib/customAsiaOrderCustomerMessage';
import type {
    CustomAsiaOrderProductNameSuggestion,
    CustomAsiaOrderProductNameSuggestionsResponse,
} from '../lib/customAsiaOrderProductNameSuggest';
import {
    defaultCustomAsiaOrderPricingCaps,
    type CustomAsiaOrderPricingCaps,
} from '../lib/customAsiaOrderPricingCaps';
import {
    merchandiserCommissionFromMultiplier,
    merchandiserMultiplierFromCommission,
    opvMarginFromOurMultiplier,
    ourMultiplierFromOpvMargin,
    resolveMerchandiserCommissionCad,
    resolveOpvMarginCad,
    resolveOpvSpreadCad,
    resolveSellingPriceCad,
    syncPricesAfterMerchandiserCommissionChange,
    syncPricesAfterOpvMarginChange,
    syncPricesAfterSellingPriceChange,
    type CustomAsiaPricingMathInput,
} from '../lib/customAsiaOrderPricingMath';
import type {
    CustomAsiaOrder,
    CustomAsiaOrderContactMedia,
    CustomAsiaOrderCurrency,
    CustomAsiaOrderFilterOptions,
    CustomAsiaOrderReceiveDelayUnit,
} from '../types/customAsiaOrders';
import {
    DEFAULT_DEPOSIT_PERCENT,
    DEFAULT_MERCHANDISER_PRICE_MULTIPLIER,
    DEFAULT_OUR_PRICE_MULTIPLIER,
    DEFAULT_CUSTOM_ASIA_RECEIVE_DELAY_AMOUNT,
    DEFAULT_CUSTOM_ASIA_RECEIVE_DELAY_UNIT,
} from '../types/customAsiaOrders';

type SaveState = 'idle' | 'saving' | 'saved' | 'error';

type RequestSnapshot = {
    customer_contact_media: CustomAsiaOrderContactMedia;
    customer_contact_value: string;
    product_name: string;
    notes: string | null;
};

type MerchandiserSnapshot = {
    product_cost_amount: string | null;
    product_cost_currency: CustomAsiaOrderCurrency;
    shipping_cost_amount: string | null;
    shipping_cost_currency: CustomAsiaOrderCurrency;
    receive_delay_amount: number | null;
    receive_delay_unit: CustomAsiaOrderReceiveDelayUnit | null;
    actual_product_cost_amount: string | null;
    actual_product_cost_currency: CustomAsiaOrderCurrency;
    actual_shipping_cost_amount: string | null;
    actual_shipping_cost_currency: CustomAsiaOrderCurrency;
    actual_receive_delay_amount: number | null;
    actual_receive_delay_unit: CustomAsiaOrderReceiveDelayUnit | null;
    actual_arrival_at: string | null;
};

type ReconciliationSnapshot = Pick<
    MerchandiserSnapshot,
    | 'actual_product_cost_amount'
    | 'actual_product_cost_currency'
    | 'actual_shipping_cost_amount'
    | 'actual_shipping_cost_currency'
    | 'actual_arrival_at'
>;

type PricingSnapshot = {
    merchandiser_price_multiplier: string | null;
    merchandiser_price_cad: string | null;
    merchandiser_commission_override_cad: string | null;
    our_price_multiplier: string | null;
    customer_price_cad: string | null;
    our_commission_override_cad: string | null;
    deposit_percent: string | null;
    deposit_amount_override_cad: string | null;
};

const route = useRoute();
const router = useRouter();

const isNew = computed(() => route.name === 'custom-asia-order-new');
const orderId = computed(() => (isNew.value ? null : String(route.params.id ?? '')));

const filterOptions = ref<CustomAsiaOrderFilterOptions['data'] | null>(null);
const order = ref<CustomAsiaOrder | null>(null);
const loading = ref(false);
const saving = ref(false);
const uploadingCustomer = ref(false);
const uploadingProduct = ref(false);
const uploadingOrderProof = ref(false);
const deletingVisual = ref(false);
const errorMessage = ref<string | null>(null);
const showDeleteConfirm = ref(false);
const showRejectConfirm = ref(false);
const showUnlockOfferConfirm = ref(false);
const rejectingOrder = ref(false);
const revivingOrder = ref(false);

const customerContactMedia = ref<CustomAsiaOrderContactMedia>('ig');
const customerContactValue = ref('');
const productName = ref('');
const notes = ref('');

const productCostAmount = ref('');
const productCostCurrency = ref<CustomAsiaOrderCurrency>('CNY');
const shippingCostAmount = ref('');
const shippingCostCurrency = ref<CustomAsiaOrderCurrency>('CNY');
const receiveDelayAmount = ref<number | ''>(DEFAULT_CUSTOM_ASIA_RECEIVE_DELAY_AMOUNT);
const receiveDelayUnit = ref<CustomAsiaOrderReceiveDelayUnit>(DEFAULT_CUSTOM_ASIA_RECEIVE_DELAY_UNIT);

const actualProductCostAmount = ref('');
const actualProductCostCurrency = ref<CustomAsiaOrderCurrency>('CNY');
const actualShippingCostAmount = ref('');
const actualShippingCostCurrency = ref<CustomAsiaOrderCurrency>('CNY');
const actualReceiveDelayAmount = ref<number | ''>('');
const actualReceiveDelayUnit = ref<CustomAsiaOrderReceiveDelayUnit>(DEFAULT_CUSTOM_ASIA_RECEIVE_DELAY_UNIT);
const actualArrivalAt = ref('');

const merchandiserPriceMultiplier = ref(DEFAULT_MERCHANDISER_PRICE_MULTIPLIER);
const merchandiserPriceCad = ref('');
const merchandiserCommissionOverrideCad = ref('');
const ourPriceMultiplier = ref(DEFAULT_OUR_PRICE_MULTIPLIER);
const customerPriceCad = ref('');
const ourCommissionOverrideCad = ref('');
const depositPercent = ref(DEFAULT_DEPOSIT_PERCENT);
const depositAmountOverrideCad = ref('');

const savedRequest = ref<RequestSnapshot | null>(null);
const savedMerchandiser = ref<MerchandiserSnapshot | null>(null);
const savedReconciliation = ref<ReconciliationSnapshot | null>(null);
const savedPricing = ref<PricingSnapshot | null>(null);
const requestSaveState = ref<SaveState>('idle');
const merchandiserSaveState = ref<SaveState>('idle');
const reconciliationSaveState = ref<SaveState>('idle');
const pricingSaveState = ref<SaveState>('idle');
const lockingOffer = ref(false);
const unlockingOffer = ref(false);
const markingDepositReceived = ref(false);
const markingMerchandiserOrdered = ref(false);
const markingProductReceived = ref(false);
const customerMessageCopied = ref(false);
const customerMessageTemplateBody = ref<string | null>(null);
const pricingCaps = ref<CustomAsiaOrderPricingCaps>(defaultCustomAsiaOrderPricingCaps());

const productNameSuggestions = ref<CustomAsiaOrderProductNameSuggestion[]>([]);
const productNameSuggestionsLoading = ref(false);
const productNameSuggestionsOpen = ref(false);
const productNameSuggestError = ref<string | null>(null);
let productNameSuggestTimer: ReturnType<typeof setTimeout> | null = null;
let productNameSuggestRequestId = 0;
let productNameSuggestHideTimer: ReturnType<typeof setTimeout> | null = null;
const competitorPricesError = ref<string | null>(null);
const lastAutoCompetitorSearchName = ref<string | null>(null);
const competitorPricesPanelRef = ref<HTMLElement | null>(null);
let competitorPricesPollTimer: ReturnType<typeof setInterval> | null = null;

const customerFileInput = ref<HTMLInputElement | null>(null);
const productFileInput = ref<HTMLInputElement | null>(null);
const orderProofFileInput = ref<HTMLInputElement | null>(null);

let hydrating = false;
let requestSavedTimer: ReturnType<typeof setTimeout> | null = null;
let merchandiserSavedTimer: ReturnType<typeof setTimeout> | null = null;
let pricingSavedTimer: ReturnType<typeof setTimeout> | null = null;

/** Pricing inputs currently being edited — skip server re-apply while focused. */
const editingPricingFields = ref(new Set<string>());

function markPricingFieldEditing(field: string): void {
    editingPricingFields.value = new Set([...editingPricingFields.value, field]);
}

function clearPricingFieldEditing(field: string): void {
    const next = new Set(editingPricingFields.value);
    next.delete(field);
    editingPricingFields.value = next;
}

function preventEnterSubmit(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
        event.preventDefault();
        (event.target as HTMLInputElement).blur();
    }
}

const orderTitlePrefix = computed(() => (isNew.value ? 'New custom order — ' : 'Custom order — '));

const workflowStatus = computed(() =>
    order.value ? resolveCustomAsiaOrderWorkflowStatus(order.value) : 'pending_quote',
);

const workflowStatusLabel = computed(() =>
    customAsiaOrderWorkflowStatusLabel(workflowStatus.value),
);

const isQuoted = computed(() => order.value?.quote_status === 'quoted');

const isOfferLocked = computed(() => order.value?.offer_locked_at != null);

const isRejected = computed(() => order.value?.rejected_at != null);

const competitorPriceQuotes = computed(() => order.value?.competitor_price_quotes ?? []);

const competitorPricesRefreshing = computed((): boolean => {
    const status = order.value?.competitor_prices_refresh_status;
    return status === 'queued' || status === 'running';
});

const competitorPricesLoading = computed(() => competitorPricesRefreshing.value);

const competitorPriceDisplayRows = computed(() => {
    const quotes = competitorPriceQuotes.value;
    if (quotes.length > 0) {
        return quotes;
    }

    const targets = order.value?.competitor_prices_target_sites ?? [];
    if (competitorPricesRefreshing.value && targets.length > 0) {
        return targets.map((site) => ({
            site_key: site.site_key,
            site_name: site.site_name,
            site_url: site.site_url,
            status: 'pending' as const,
            availability: null,
            currency: 'CAD',
            price: null,
            original_price: null,
            product_url: null,
            error_message: null,
        }));
    }

    return [];
});

const competitorPricesSearchSummary = computed((): string | null => {
    if (!competitorPricesRefreshing.value) {
        return null;
    }

    const sites = order.value?.competitor_prices_target_sites ?? [];
    if (sites.length === 0) {
        return 'Searching Canadian retailers in parallel…';
    }

    const names = sites.map((site) => site.site_name).join(', ');

    return `Searching ${sites.length} retailers in parallel: ${names}`;
});

const competitorPricesStale = computed((): boolean => {
    const searched = order.value?.competitor_prices_product_name?.trim() ?? '';
    const current = productName.value.trim();

    return searched !== '' && current !== '' && searched !== current;
});

const showCompetitorPricesPanel = computed(
    () => !isNew.value && productName.value.trim().length >= 3 && !isRejected.value,
);

const isDepositReceived = computed(() => order.value?.deposit_received_at != null);

const isMerchandiserOrdered = computed(() => order.value?.merchandiser_ordered_at != null);

const isProductReceived = computed(() => order.value?.product_received_at != null);

const actualLandedCostCad = computed(() => order.value?.actual_landed_cost_cad ?? null);

const actualPricingMathInput = computed(
    (): CustomAsiaPricingMathInput => ({
        ...pricingMathInput.value,
        landedCostCad: actualLandedCostCad.value,
    }),
);

const actualMerchandiserCommissionCad = computed(() =>
    resolveMerchandiserCommissionCad(actualPricingMathInput.value),
);

const actualPayMerchandiserCad = computed((): string | null => {
    const landed = Number(String(actualLandedCostCad.value ?? '').trim());
    const commission = Number(actualMerchandiserCommissionCad.value ?? '');
    if (!Number.isFinite(landed) || landed <= 0 || !Number.isFinite(commission) || commission < 0) {
        return null;
    }

    return (landed + commission).toFixed(2);
});

const actualOpvMarginCad = computed((): string | null => {
    if (actualPayMerchandiserCad.value === null) {
        return null;
    }

    const customer = Number(String(effectiveCustomerPriceCad.value ?? '').trim());
    const payMerchandiser = Number(actualPayMerchandiserCad.value ?? '');
    if (!Number.isFinite(customer) || customer <= 0 || !Number.isFinite(payMerchandiser)) {
        return null;
    }

    return Math.max(0, customer - payMerchandiser).toFixed(2);
});

const showSummaryBar = computed(
    () => !isNew.value && order.value != null && (isQuoted.value || order.value.landed_cost_cad != null),
);

const canLockOffer = computed(() => {
    if (isNew.value || !isQuoted.value || isOfferLocked.value || lockingOffer.value || isRejected.value) {
        return false;
    }

    const customerPrice = effectiveCustomerPriceCad.value;
    const deposit = String(depositPercent.value).trim();
    const depositAmount = String(depositAmountOverrideCad.value).trim();

    return customerPrice != null && customerPrice !== '' && (deposit !== '' || depositAmount !== '');
});

const canUnlockOffer = computed(() => {
    if (
        isNew.value ||
        !isOfferLocked.value ||
        unlockingOffer.value ||
        isRejected.value ||
        isDepositReceived.value ||
        isMerchandiserOrdered.value ||
        isProductReceived.value
    ) {
        return false;
    }

    return true;
});

const offerUnlockBlockedHint = computed((): string | null => {
    if (!isOfferLocked.value || canUnlockOffer.value) {
        return null;
    }

    if (isProductReceived.value || isDepositReceived.value || isMerchandiserOrdered.value) {
        return 'Cannot unlock after deposit, merchandiser order, or product receipt.';
    }

    if (isRejected.value) {
        return 'Revive the order before unlocking the offer.';
    }

    return null;
});

const pricingBalanceCad = computed(() => {
    const price = effectiveCustomerPriceCad.value;
    if (price == null || price === '') return null;

    const priceValue = Number(price);
    if (Number.isNaN(priceValue)) return null;

    const depositAmountRaw = String(depositAmountOverrideCad.value).trim();
    if (depositAmountRaw !== '') {
        const depositValue = Number(depositAmountRaw);
        if (Number.isNaN(depositValue)) return null;

        return Math.max(0, priceValue - depositValue).toFixed(2);
    }

    const percentRaw = String(depositPercent.value).trim();
    const percentValue = percentRaw === '' ? 0 : Number(percentRaw);
    if (Number.isNaN(percentValue)) return null;

    const depositValue = (priceValue * percentValue) / 100;

    return Math.max(0, priceValue - depositValue).toFixed(2);
});

const pricingDepositAmountCad = computed(() => {
    const price = effectiveCustomerPriceCad.value;
    if (price == null || price === '') return null;

    const depositAmountRaw = String(depositAmountOverrideCad.value).trim();
    if (depositAmountRaw !== '') {
        const depositValue = Number(depositAmountRaw);
        if (Number.isNaN(depositValue)) return null;

        return depositValue.toFixed(2);
    }

    const priceValue = Number(price);
    if (Number.isNaN(priceValue)) return null;

    const percentRaw = String(depositPercent.value).trim();
    const percentValue = percentRaw === '' ? 0 : Number(percentRaw);
    if (Number.isNaN(percentValue)) return null;

    return ((priceValue * percentValue) / 100).toFixed(2);
});

const pricingDepositFromPercentCad = computed((): string | null => {
    const price = effectiveCustomerPriceCad.value;
    if (price == null || price === '') return null;

    const priceValue = Number(price);
    if (Number.isNaN(priceValue) || priceValue <= 0) return null;

    const percentRaw = String(depositPercent.value).trim();
    const percentValue = percentRaw === '' ? 0 : Number(percentRaw);
    if (Number.isNaN(percentValue)) return null;

    return ((priceValue * percentValue) / 100).toFixed(2);
});

function effectiveMultiplierFrom(landed: string | null | undefined, price: string): string | null {
    const landedRaw = landed?.trim() ?? '';
    const priceRaw = price.trim();
    if (landedRaw === '' || priceRaw === '') return null;

    const landedValue = Number(landedRaw);
    const priceValue = Number(priceRaw);
    if (Number.isNaN(landedValue) || Number.isNaN(priceValue) || landedValue <= 0 || priceValue <= 0) {
        return null;
    }

    return (priceValue / landedValue).toFixed(2);
}

function commissionAbove(landed: string | null | undefined, price: string): string | null {
    const landedRaw = landed?.trim() ?? '';
    const priceRaw = price.trim();
    if (landedRaw === '' || priceRaw === '') return null;

    const landedValue = Number(landedRaw);
    const priceValue = Number(priceRaw);
    if (Number.isNaN(landedValue) || Number.isNaN(priceValue) || landedValue <= 0 || priceValue <= 0) {
        return null;
    }

    return Math.max(0, priceValue - landedValue).toFixed(2);
}

const landedCostCad = computed(() => order.value?.landed_cost_cad ?? null);

const pricingMathInput = computed(
    (): CustomAsiaPricingMathInput => ({
        landedCostCad: landedCostCad.value,
        merchandiserMultiplier: String(merchandiserPriceMultiplier.value),
        ourPriceMultiplier: String(ourPriceMultiplier.value),
        merchandiserPriceCad: String(merchandiserPriceCad.value),
        customerPriceCad: String(customerPriceCad.value),
        formulaMerchandiserPriceCad: order.value?.formula_merchandiser_price_cad,
        formulaOurPriceCad: order.value?.formula_our_price_cad,
        merchandiserCommissionOverrideCad: String(merchandiserCommissionOverrideCad.value),
        opvMarginOverrideCad: String(ourCommissionOverrideCad.value),
        merchandiserCommissionCapCad: pricingCaps.value.merchandiserCommissionCapCad,
        opvMarginCapCad: pricingCaps.value.opvMarginCapCad,
    }),
);

const merchandiserEffectiveMultiplier = computed(() =>
    effectiveMultiplierFrom(landedCostCad.value, String(merchandiserPriceCad.value)),
);

const merchandiserCommissionCad = computed(() =>
    resolveMerchandiserCommissionCad(pricingMathInput.value),
);

const ourEffectiveMultiplier = computed(() =>
    effectiveMultiplierFrom(landedCostCad.value, String(customerPriceCad.value)),
);

const ourCommissionCad = computed(() => resolveOpvMarginCad(pricingMathInput.value));

const merchandiserPayPriceCad = computed((): string | null => {
    const landed = Number(String(landedCostCad.value ?? '').trim());
    const commission = Number(merchandiserCommissionCad.value ?? '');
    if (!Number.isFinite(landed) || landed <= 0 || !Number.isFinite(commission) || commission < 0) {
        return null;
    }

    return (landed + commission).toFixed(2);
});

const opvSpreadCad = computed(() => resolveOpvSpreadCad(pricingMathInput.value));

const componentSellingPriceCad = computed((): string | null =>
    resolveSellingPriceCad({
        ...pricingMathInput.value,
        customerPriceCad: '',
    }),
);

const effectiveCustomerPriceCad = computed((): string | null => {
    const explicit = String(customerPriceCad.value).trim();
    if (explicit !== '') {
        return formatMoney2OrEmpty(explicit) || explicit;
    }

    return componentSellingPriceCad.value;
});

const productFxCadToForeignLabel = computed(() =>
    formatFxCadToForeignLabel(
        order.value?.product_fx_rate_to_cad,
        order.value?.product_cost_currency_label,
    ),
);

const shippingFxCadToForeignLabel = computed(() =>
    formatFxCadToForeignLabel(
        order.value?.shipping_fx_rate_to_cad,
        order.value?.shipping_cost_currency_label,
    ),
);

const actualProductFxCadToForeignLabel = computed(() =>
    formatFxCadToForeignLabel(
        order.value?.actual_product_fx_rate_to_cad,
        order.value?.actual_product_cost_currency_label,
    ),
);

const actualShippingFxCadToForeignLabel = computed(() =>
    formatFxCadToForeignLabel(
        order.value?.actual_shipping_fx_rate_to_cad,
        order.value?.actual_shipping_cost_currency_label,
    ),
);

function currencyLabelFor(code: CustomAsiaOrderCurrency): string {
    return (
        filterOptions.value?.currencies.find((option) => option.value === code)?.label ??
        (code === 'CNY' ? 'RMB' : code)
    );
}

const pricingSummaryRows = computed(() =>
    buildCustomAsiaPricingSummary({
        productCostAmount: String(productCostAmount.value).trim(),
        productCostCurrency: productCostCurrency.value,
        productCostCurrencyLabel: currencyLabelFor(productCostCurrency.value),
        productFxRateToCad: order.value?.product_fx_rate_to_cad ?? null,
        shippingCostAmount: String(shippingCostAmount.value).trim(),
        shippingCostCurrency: shippingCostCurrency.value,
        shippingCostCurrencyLabel: currencyLabelFor(shippingCostCurrency.value),
        shippingFxRateToCad: order.value?.shipping_fx_rate_to_cad ?? null,
        landedCostCad: landedCostCad.value,
        merchandiserCommissionCad: merchandiserCommissionCad.value,
        opvMarkupCad: ourCommissionCad.value,
        sellingPriceCad: effectiveCustomerPriceCad.value,
        depositCad: pricingDepositAmountCad.value,
        remainingCad: pricingBalanceCad.value,
    }),
);

const showPricingSummary = computed(
    () =>
        !isNew.value &&
        order.value != null &&
        isQuoted.value &&
        pricingSummaryHasValues(pricingSummaryRows.value),
);

const reconciliationSummaryRows = computed(() =>
    buildCustomAsiaReconciliationSummary({
        productCostAmount: String(actualProductCostAmount.value).trim(),
        productCostCurrency: actualProductCostCurrency.value,
        productCostCurrencyLabel: currencyLabelFor(actualProductCostCurrency.value),
        productFxRateToCad: order.value?.actual_product_fx_rate_to_cad ?? null,
        shippingCostAmount: String(actualShippingCostAmount.value).trim(),
        shippingCostCurrency: actualShippingCostCurrency.value,
        shippingCostCurrencyLabel: currencyLabelFor(actualShippingCostCurrency.value),
        shippingFxRateToCad: order.value?.actual_shipping_fx_rate_to_cad ?? null,
        landedCostCad: actualLandedCostCad.value,
        merchandiserMultiplier: String(merchandiserPriceMultiplier.value),
        merchandiserCommissionCad: actualMerchandiserCommissionCad.value,
        payMerchandiserCad: actualPayMerchandiserCad.value,
        customerPriceCad: effectiveCustomerPriceCad.value,
        opvMarginCad: actualOpvMarginCad.value,
    }),
);

const showReconciliationSection = computed(
    () => !isNew.value && order.value != null && isQuoted.value && isOfferLocked.value,
);

const reconciliationSummaryShowsOriginal = computed(() =>
    reconciliationSummaryShowsOriginalColumn(reconciliationSummaryRows.value),
);

const showCustomerOfferContent = computed(() => isNew.value || order.value != null);

const showOfferLayoutPlaceholder = computed(
    () => loading.value && !isNew.value && order.value == null,
);

const pricingSummaryShowsOriginal = computed(() =>
    pricingSummaryShowsOriginalColumn(pricingSummaryRows.value),
);

const pageMeta = computed(() => {
    if (isNew.value) return 'New Asia custom order';
    const media =
        filterOptions.value?.contact_media.find((o) => o.value === customerContactMedia.value)?.label ??
        customerContactMedia.value;
    const contact = customerContactValue.value.trim() || '—';
    return `${media} · ${contact}`;
});

const requestSaveHint = computed(() => {
    if (isNew.value) return '';
    if (requestSaveState.value === 'saving') return 'Saving…';
    if (requestSaveState.value === 'saved') return 'Saved';
    if (requestSaveState.value === 'error') return 'Save failed';
    return '';
});

const merchandiserSaveHint = computed(() => {
    if (isNew.value) return '';
    if (merchandiserSaveState.value === 'saving') return 'Saving…';
    if (merchandiserSaveState.value === 'saved') return 'Saved';
    if (merchandiserSaveState.value === 'error') return 'Save failed';
    return '';
});

const reconciliationSaveHint = computed(() => {
    if (isNew.value || !showReconciliationSection.value) return '';
    if (reconciliationSaveState.value === 'saving') return 'Saving…';
    if (reconciliationSaveState.value === 'saved') return 'Saved';
    if (reconciliationSaveState.value === 'error') return 'Save failed';
    return '';
});

const pricingSaveHint = computed(() => {
    if (isNew.value || !isQuoted.value) return '';
    if (pricingSaveState.value === 'saving') return 'Saving…';
    if (pricingSaveState.value === 'saved') return 'Saved';
    if (pricingSaveState.value === 'error') return 'Save failed';
    return '';
});

const customerOfferMessage = computed(() =>
    buildCustomAsiaOrderCustomerMessage({
        template: customerMessageTemplateBody.value,
        productName: productName.value,
        customerPriceCad: effectiveCustomerPriceCad.value,
        depositPercent: depositPercent.value,
        depositAmountOverrideCad: depositAmountOverrideCad.value,
    }),
);

async function loadCustomerMessageTemplate(): Promise<void> {
    try {
        const res = await api.get<{ data: { body: string } }>(
            '/api/v1/maintenance/custom-asia-order-customer-message-template',
        );
        customerMessageTemplateBody.value = res.data.data.body;
    } catch {
        customerMessageTemplateBody.value = null;
    }
}

async function loadPricingCaps(): Promise<void> {
    try {
        const res = await api.get<{
            data: {
                merchandiser_commission_cap_cad: string;
                opv_margin_cap_cad: string;
            };
        }>('/api/v1/maintenance/custom-asia-order-pricing-caps');
        pricingCaps.value = {
            merchandiserCommissionCapCad: res.data.data.merchandiser_commission_cap_cad,
            opvMarginCapCad: res.data.data.opv_margin_cap_cad,
        };
    } catch {
        pricingCaps.value = defaultCustomAsiaOrderPricingCaps();
    }
}

async function copyCustomerOfferMessage(): Promise<void> {
    const text = customerOfferMessage.value;
    if (!text) return;

    try {
        await navigator.clipboard.writeText(text);
        customerMessageCopied.value = true;
        window.setTimeout(() => {
            customerMessageCopied.value = false;
        }, 2000);
    } catch {
        errorMessage.value = 'Could not copy message to clipboard.';
    }
}

function pickCustomerFile(): void {
    customerFileInput.value?.click();
}

function pickProductFile(): void {
    productFileInput.value?.click();
}

function pickOrderProofFile(): void {
    orderProofFileInput.value?.click();
}

function currentRequestSnapshot(): RequestSnapshot {
    return {
        customer_contact_media: customerContactMedia.value,
        customer_contact_value: customerContactValue.value.trim(),
        product_name: productName.value.trim(),
        notes: notes.value.trim() || null,
    };
}

function normalizedReceiveDelay(data: CustomAsiaOrder): {
    amount: number;
    unit: CustomAsiaOrderReceiveDelayUnit;
} {
    if (data.receive_delay_amount != null && data.receive_delay_amount > 0) {
        return {
            amount: data.receive_delay_amount,
            unit: data.receive_delay_unit ?? DEFAULT_CUSTOM_ASIA_RECEIVE_DELAY_UNIT,
        };
    }

    return {
        amount: DEFAULT_CUSTOM_ASIA_RECEIVE_DELAY_AMOUNT,
        unit: DEFAULT_CUSTOM_ASIA_RECEIVE_DELAY_UNIT,
    };
}

function normalizedActualReceiveDelay(data: CustomAsiaOrder): {
    amount: number | '';
    unit: CustomAsiaOrderReceiveDelayUnit;
} {
    if (data.actual_receive_delay_amount != null && data.actual_receive_delay_amount > 0) {
        return {
            amount: data.actual_receive_delay_amount,
            unit: data.actual_receive_delay_unit ?? DEFAULT_CUSTOM_ASIA_RECEIVE_DELAY_UNIT,
        };
    }

    return {
        amount: '',
        unit: DEFAULT_CUSTOM_ASIA_RECEIVE_DELAY_UNIT,
    };
}

function normalizedDepositPercent(data: CustomAsiaOrder): string {
    if (data.deposit_percent != null && data.deposit_percent !== '') {
        return data.deposit_percent;
    }

    return DEFAULT_DEPOSIT_PERCENT;
}

function pricingFromOrder(data: CustomAsiaOrder): PricingSnapshot {
    return {
        merchandiser_price_multiplier:
            data.merchandiser_price_multiplier ?? DEFAULT_MERCHANDISER_PRICE_MULTIPLIER,
        merchandiser_price_cad: data.merchandiser_price_cad ?? null,
        merchandiser_commission_override_cad: data.merchandiser_commission_override_cad ?? null,
        our_price_multiplier: data.our_price_multiplier ?? DEFAULT_OUR_PRICE_MULTIPLIER,
        customer_price_cad: data.customer_price_cad ?? null,
        our_commission_override_cad: data.our_commission_override_cad ?? null,
        deposit_percent: normalizedDepositPercent(data),
        deposit_amount_override_cad: data.deposit_amount_override_cad ?? null,
    };
}

function currentPricingSnapshot(): PricingSnapshot {
    const merchandiserMultiplier = String(merchandiserPriceMultiplier.value).trim();
    const merchandiserPrice = String(merchandiserPriceCad.value).trim();
    const merchandiserCommission = String(merchandiserCommissionOverrideCad.value).trim();
    const ourMultiplier = String(ourPriceMultiplier.value).trim();
    const customerPrice = String(customerPriceCad.value).trim();
    const ourCommission = String(ourCommissionOverrideCad.value).trim();
    const deposit = String(depositPercent.value).trim();
    const depositAmount = String(depositAmountOverrideCad.value).trim();

    return {
        merchandiser_price_multiplier: merchandiserMultiplier === '' ? null : merchandiserMultiplier,
        merchandiser_price_cad: merchandiserPrice === '' ? null : merchandiserPrice,
        merchandiser_commission_override_cad: merchandiserCommission === '' ? null : merchandiserCommission,
        our_price_multiplier: ourMultiplier === '' ? null : ourMultiplier,
        customer_price_cad: customerPrice === '' ? null : customerPrice,
        our_commission_override_cad: ourCommission === '' ? null : ourCommission,
        deposit_percent: deposit === '' ? null : deposit,
        deposit_amount_override_cad: depositAmount === '' ? null : depositAmount,
    };
}

function currentMerchandiserPricingSnapshot(): Pick<
    PricingSnapshot,
    'merchandiser_price_multiplier' | 'merchandiser_price_cad' | 'merchandiser_commission_override_cad'
> {
    const snapshot = currentPricingSnapshot();

    return {
        merchandiser_price_multiplier: snapshot.merchandiser_price_multiplier,
        merchandiser_price_cad: snapshot.merchandiser_price_cad,
        merchandiser_commission_override_cad: snapshot.merchandiser_commission_override_cad,
    };
}

function currentCustomerOfferSnapshot(): Pick<
    PricingSnapshot,
    | 'our_price_multiplier'
    | 'customer_price_cad'
    | 'our_commission_override_cad'
    | 'deposit_percent'
    | 'deposit_amount_override_cad'
> {
    const snapshot = currentPricingSnapshot();
    const resolvedCustomerPrice =
        snapshot.customer_price_cad ?? effectiveCustomerPriceCad.value ?? null;

    return {
        our_price_multiplier: snapshot.our_price_multiplier,
        customer_price_cad: resolvedCustomerPrice,
        our_commission_override_cad: snapshot.our_commission_override_cad,
        deposit_percent: snapshot.deposit_percent,
        deposit_amount_override_cad: snapshot.deposit_amount_override_cad,
    };
}

function reconciliationFromMerchandiser(snap: MerchandiserSnapshot): ReconciliationSnapshot {
    return {
        actual_product_cost_amount: snap.actual_product_cost_amount,
        actual_product_cost_currency: snap.actual_product_cost_currency,
        actual_shipping_cost_amount: snap.actual_shipping_cost_amount,
        actual_shipping_cost_currency: snap.actual_shipping_cost_currency,
        actual_arrival_at: snap.actual_arrival_at,
    };
}

function currentMerchandiserQuoteSnapshot(): Pick<
    MerchandiserSnapshot,
    | 'product_cost_amount'
    | 'product_cost_currency'
    | 'shipping_cost_amount'
    | 'shipping_cost_currency'
    | 'receive_delay_amount'
    | 'receive_delay_unit'
> {
    const delayAmount =
        receiveDelayAmount.value === '' || receiveDelayAmount.value === null
            ? null
            : Number(receiveDelayAmount.value);

    return {
        product_cost_amount:
            String(productCostAmount.value).trim() === '' ? null : String(productCostAmount.value).trim(),
        product_cost_currency: productCostCurrency.value,
        shipping_cost_amount:
            String(shippingCostAmount.value).trim() === '' ? null : String(shippingCostAmount.value).trim(),
        shipping_cost_currency: shippingCostCurrency.value,
        receive_delay_amount: delayAmount,
        receive_delay_unit: delayAmount === null ? null : receiveDelayUnit.value,
    };
}

function currentReconciliationSnapshot(): ReconciliationSnapshot {
    return {
        actual_product_cost_amount:
            String(actualProductCostAmount.value).trim() === ''
                ? null
                : String(actualProductCostAmount.value).trim(),
        actual_product_cost_currency: actualProductCostCurrency.value,
        actual_shipping_cost_amount:
            String(actualShippingCostAmount.value).trim() === ''
                ? null
                : String(actualShippingCostAmount.value).trim(),
        actual_shipping_cost_currency: actualShippingCostCurrency.value,
        actual_arrival_at: actualArrivalAt.value.trim() === '' ? null : actualArrivalAt.value.trim(),
    };
}

function currentMerchandiserSnapshot(): MerchandiserSnapshot {
    return {
        ...currentMerchandiserQuoteSnapshot(),
        ...currentReconciliationSnapshot(),
        actual_receive_delay_amount: null,
        actual_receive_delay_unit: null,
    };
}

function snapshotsFromOrder(data: CustomAsiaOrder): {
    request: RequestSnapshot;
    merchandiser: MerchandiserSnapshot;
    pricing: PricingSnapshot;
} {
    return {
        request: {
            customer_contact_media: data.customer_contact_media,
            customer_contact_value: data.customer_contact_value,
            product_name: data.product_name ?? '',
            notes: data.notes?.trim() || null,
        },
        merchandiser: (() => {
            const receiveDelay = normalizedReceiveDelay(data);
            const actualReceiveDelay = normalizedActualReceiveDelay(data);

            return {
                product_cost_amount: data.product_cost_amount ?? null,
                product_cost_currency: data.product_cost_currency ?? 'CNY',
                shipping_cost_amount: data.shipping_cost_amount ?? null,
                shipping_cost_currency: data.shipping_cost_currency ?? 'CNY',
                receive_delay_amount: receiveDelay.amount,
                receive_delay_unit: receiveDelay.unit,
                actual_product_cost_amount: data.actual_product_cost_amount ?? null,
                actual_product_cost_currency: data.actual_product_cost_currency ?? 'CNY',
                actual_shipping_cost_amount: data.actual_shipping_cost_amount ?? null,
                actual_shipping_cost_currency: data.actual_shipping_cost_currency ?? 'CNY',
                actual_receive_delay_amount:
                    actualReceiveDelay.amount === '' ? null : actualReceiveDelay.amount,
                actual_receive_delay_unit:
                    actualReceiveDelay.amount === '' ? null : actualReceiveDelay.unit,
                actual_arrival_at: data.actual_arrival_at ?? null,
            };
        })(),
        pricing: pricingFromOrder(data),
    };
}

function requestIsDirty(): boolean {
    if (!savedRequest.value) return false;
    return JSON.stringify(currentRequestSnapshot()) !== JSON.stringify(savedRequest.value);
}

function merchandiserQuoteIsDirty(): boolean {
    if (!savedMerchandiser.value) return false;

    const savedQuote = {
        product_cost_amount: savedMerchandiser.value.product_cost_amount,
        product_cost_currency: savedMerchandiser.value.product_cost_currency,
        shipping_cost_amount: savedMerchandiser.value.shipping_cost_amount,
        shipping_cost_currency: savedMerchandiser.value.shipping_cost_currency,
        receive_delay_amount: savedMerchandiser.value.receive_delay_amount,
        receive_delay_unit: savedMerchandiser.value.receive_delay_unit,
    };

    return JSON.stringify(currentMerchandiserQuoteSnapshot()) !== JSON.stringify(savedQuote);
}

function reconciliationIsDirty(): boolean {
    if (!savedReconciliation.value) return false;

    return JSON.stringify(currentReconciliationSnapshot()) !== JSON.stringify(savedReconciliation.value);
}

function merchandiserIsDirty(): boolean {
    return merchandiserQuoteIsDirty();
}

function merchandiserPricingIsDirty(): boolean {
    if (!savedPricing.value) return false;

    return (
        JSON.stringify(currentMerchandiserPricingSnapshot()) !==
        JSON.stringify({
            merchandiser_price_multiplier: savedPricing.value.merchandiser_price_multiplier,
            merchandiser_price_cad: savedPricing.value.merchandiser_price_cad,
            merchandiser_commission_override_cad: savedPricing.value.merchandiser_commission_override_cad,
        })
    );
}

function customerOfferIsDirty(): boolean {
    if (!savedPricing.value) return false;

    return (
        JSON.stringify(currentCustomerOfferSnapshot()) !==
        JSON.stringify({
            our_price_multiplier: savedPricing.value.our_price_multiplier,
            customer_price_cad: savedPricing.value.customer_price_cad,
            our_commission_override_cad: savedPricing.value.our_commission_override_cad,
            deposit_percent: savedPricing.value.deposit_percent,
            deposit_amount_override_cad: savedPricing.value.deposit_amount_override_cad,
        })
    );
}

function pricingIsDirty(): boolean {
    return merchandiserPricingIsDirty() || customerOfferIsDirty();
}

function flashSaveState(target: { value: SaveState }, timerRef: 'request' | 'merchandiser' | 'pricing'): void {
    target.value = 'saved';
    const existing =
        timerRef === 'request'
            ? requestSavedTimer
            : timerRef === 'merchandiser'
              ? merchandiserSavedTimer
              : pricingSavedTimer;
    if (existing) clearTimeout(existing);
    const timer = setTimeout(() => {
        if (target.value === 'saved') target.value = 'idle';
    }, 1800);
    if (timerRef === 'request') requestSavedTimer = timer;
    else if (timerRef === 'merchandiser') merchandiserSavedTimer = timer;
    else pricingSavedTimer = timer;
}

function applyOrderToForm(data: CustomAsiaOrder): void {
    hydrating = true;
    customerContactMedia.value = data.customer_contact_media;
    customerContactValue.value = data.customer_contact_value;
    productName.value = data.product_name ?? '';
    notes.value = data.notes ?? '';
    productCostAmount.value = data.product_cost_amount ?? '';
    productCostCurrency.value = data.product_cost_currency ?? 'CNY';
    shippingCostAmount.value = data.shipping_cost_amount ?? '';
    shippingCostCurrency.value = data.shipping_cost_currency ?? 'CNY';
    const receiveDelay = normalizedReceiveDelay(data);
    receiveDelayAmount.value = receiveDelay.amount;
    receiveDelayUnit.value = receiveDelay.unit;
    actualProductCostAmount.value = data.actual_product_cost_amount ?? '';
    actualProductCostCurrency.value = data.actual_product_cost_currency ?? 'CNY';
    actualShippingCostAmount.value = data.actual_shipping_cost_amount ?? '';
    actualShippingCostCurrency.value = data.actual_shipping_cost_currency ?? 'CNY';
    const actualReceiveDelay = normalizedActualReceiveDelay(data);
    actualReceiveDelayAmount.value = actualReceiveDelay.amount;
    actualReceiveDelayUnit.value = actualReceiveDelay.unit;
    actualArrivalAt.value = data.actual_arrival_at ?? '';
    const pricing = pricingFromOrder(data);
    if (!editingPricingFields.value.has('merchandiserMultiplier')) {
        merchandiserPriceMultiplier.value =
            pricing.merchandiser_price_multiplier ?? DEFAULT_MERCHANDISER_PRICE_MULTIPLIER;
    }
    if (!editingPricingFields.value.has('merchandiserPrice')) {
        merchandiserPriceCad.value = pricing.merchandiser_price_cad ?? '';
    }
    if (!editingPricingFields.value.has('merchandiserCommission')) {
        merchandiserCommissionOverrideCad.value = pricing.merchandiser_commission_override_cad ?? '';
    }
    if (!editingPricingFields.value.has('ourMultiplier')) {
        ourPriceMultiplier.value = pricing.our_price_multiplier ?? DEFAULT_OUR_PRICE_MULTIPLIER;
    }
    if (!editingPricingFields.value.has('customerPrice')) {
        customerPriceCad.value = pricing.customer_price_cad ?? '';
    }
    if (!editingPricingFields.value.has('ourCommission')) {
        ourCommissionOverrideCad.value = pricing.our_commission_override_cad ?? '';
    }
    if (!editingPricingFields.value.has('depositPercent')) {
        depositPercent.value = pricing.deposit_percent ?? DEFAULT_DEPOSIT_PERCENT;
    }
    if (!editingPricingFields.value.has('depositAmount')) {
        depositAmountOverrideCad.value = pricing.deposit_amount_override_cad ?? '';
    }
    const snaps = snapshotsFromOrder(data);
    savedRequest.value = snaps.request;
    savedMerchandiser.value = snaps.merchandiser;
    savedReconciliation.value = reconciliationFromMerchandiser(snaps.merchandiser);
    savedPricing.value = snaps.pricing;
    hydrating = false;
}

async function loadFilterOptions(): Promise<void> {
    const res = await api.get<CustomAsiaOrderFilterOptions>('/api/v1/custom-asia-orders/filter-options');
    filterOptions.value = res.data.data;
}

async function loadOrder(): Promise<void> {
    if (!orderId.value) return;
    loading.value = true;
    errorMessage.value = null;
    try {
        const res = await api.get<{ data: CustomAsiaOrder }>(`/api/v1/custom-asia-orders/${orderId.value}`);
        order.value = res.data.data;
        if (order.value) {
            applyOrderToForm(order.value);
            lastAutoCompetitorSearchName.value = order.value.competitor_prices_product_name?.trim() || null;
            if (competitorPricesRefreshing.value) {
                startCompetitorPricesPolling();
            } else if (
                !isRejected.value &&
                (order.value.competitor_price_quotes?.length ?? 0) === 0 &&
                order.value.competitor_prices_refresh_status !== 'failed' &&
                (order.value.product_name?.trim().length ?? 0) >= 3
            ) {
                void maybeAutoRefreshCompetitorPrices(true);
            }
        }
    } catch (err) {
        errorMessage.value = err instanceof Error ? err.message : String(err);
    } finally {
        loading.value = false;
    }
}

async function createOrder(): Promise<void> {
    if (!isNew.value) return;
    saving.value = true;
    errorMessage.value = null;
    try {
        const payload = currentRequestSnapshot();
        const res = await api.post<{ data: CustomAsiaOrder }>('/api/v1/custom-asia-orders', payload);
        const created = res.data.data;
        await router.replace({ name: 'custom-asia-order-detail', params: { id: created.id } });
        order.value = created;
        applyOrderToForm(created);
    } catch (err) {
        errorMessage.value = err instanceof Error ? err.message : String(err);
    } finally {
        saving.value = false;
    }
}

async function commitRequest(): Promise<boolean> {
    if (isNew.value || !orderId.value || loading.value || hydrating || saving.value) return false;
    if (!requestIsDirty()) return true;

    const snapshot = currentRequestSnapshot();
    if (!snapshot.customer_contact_value || !snapshot.product_name) {
        requestSaveState.value = 'error';
        errorMessage.value = 'Contact and product name are required.';
        return false;
    }

    requestSaveState.value = 'saving';
    errorMessage.value = null;
    try {
        const res = await api.patch<{ data: CustomAsiaOrder }>(
            `/api/v1/custom-asia-orders/${orderId.value}`,
            snapshot,
        );
        order.value = res.data.data;
        applyOrderToForm(res.data.data);
        flashSaveState(requestSaveState, 'request');
        return true;
    } catch (err) {
        requestSaveState.value = 'error';
        errorMessage.value = err instanceof Error ? err.message : String(err);
        return false;
    }
}

async function commitMerchandiser(): Promise<void> {
    if (isNew.value || !orderId.value || loading.value || hydrating || saving.value) return;
    if (!merchandiserQuoteIsDirty()) return;

    merchandiserSaveState.value = 'saving';
    errorMessage.value = null;
    try {
        const res = await api.patch<{ data: CustomAsiaOrder }>(
            `/api/v1/custom-asia-orders/${orderId.value}`,
            currentMerchandiserQuoteSnapshot(),
        );
        order.value = res.data.data;
        applyOrderToForm(res.data.data);
        flashSaveState(merchandiserSaveState, 'merchandiser');
    } catch (err) {
        merchandiserSaveState.value = 'error';
        errorMessage.value = err instanceof Error ? err.message : String(err);
    }
}

function onReconciliationBlur(): void {
    void commitReconciliation();
}

async function commitReconciliation(): Promise<void> {
    if (isNew.value || !orderId.value || loading.value || hydrating || saving.value || !isOfferLocked.value) {
        return;
    }
    if (!reconciliationIsDirty()) return;

    reconciliationSaveState.value = 'saving';
    errorMessage.value = null;
    try {
        const res = await api.patch<{ data: CustomAsiaOrder }>(
            `/api/v1/custom-asia-orders/${orderId.value}`,
            {
                ...currentReconciliationSnapshot(),
                actual_receive_delay_amount: null,
                actual_receive_delay_unit: null,
            },
        );
        order.value = res.data.data;
        applyOrderToForm(res.data.data);
        reconciliationSaveState.value = 'saved';
        window.setTimeout(() => {
            if (reconciliationSaveState.value === 'saved') {
                reconciliationSaveState.value = 'idle';
            }
        }, 2000);
    } catch (err) {
        reconciliationSaveState.value = 'error';
        errorMessage.value = err instanceof Error ? err.message : String(err);
    }
}

function stopCompetitorPricesPolling(): void {
    if (competitorPricesPollTimer) {
        clearInterval(competitorPricesPollTimer);
        competitorPricesPollTimer = null;
    }
}

function applyOrderCompetitorFields(data: CustomAsiaOrder): void {
    if (!order.value) {
        order.value = data;
        return;
    }

    order.value = {
        ...order.value,
        competitor_prices_product_name: data.competitor_prices_product_name,
        competitor_price_quotes: data.competitor_price_quotes,
        competitor_prices_fetched_at: data.competitor_prices_fetched_at,
        competitor_prices_refresh_status: data.competitor_prices_refresh_status,
        competitor_prices_refresh_scope: data.competitor_prices_refresh_scope,
        competitor_prices_refresh_error: data.competitor_prices_refresh_error,
        competitor_prices_target_sites: data.competitor_prices_target_sites,
    };
}

function startCompetitorPricesPolling(): void {
    stopCompetitorPricesPolling();
    if (!orderId.value) return;

    competitorPricesPollTimer = setInterval(() => {
        void pollCompetitorPrices();
    }, 2000);
}

async function pollCompetitorPrices(): Promise<void> {
    if (!orderId.value) return;

    try {
        const res = await api.get<{ data: CustomAsiaOrder }>(
            `/api/v1/custom-asia-orders/${orderId.value}`,
        );
        applyOrderCompetitorFields(res.data.data);

        const status = res.data.data.competitor_prices_refresh_status;
        if (status !== 'queued' && status !== 'running') {
            stopCompetitorPricesPolling();
            if (status === 'failed') {
                competitorPricesError.value =
                    res.data.data.competitor_prices_refresh_error ??
                    'Competitor price search failed.';
            }
        }
    } catch {
        // Keep polling — transient network errors should not stop the search UI.
    }
}

async function scrollCompetitorPricesIntoView(): Promise<void> {
    await nextTick();
    competitorPricesPanelRef.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function competitorQuotePriceLabel(quote: CustomAsiaOrder['competitor_price_quotes'][number]): string {
    if (quote.status === 'pending') {
        return 'Searching…';
    }

    if (quote.status !== 'found' || quote.price == null || quote.price === '') {
        if (quote.status === 'error') {
            return 'Error';
        }

        return 'Not found';
    }

    if (quote.original_price != null && quote.original_price !== quote.price) {
        return `$${quote.original_price} → $${quote.price}`;
    }

    return `$${quote.price}`;
}

function competitorQuoteAvailabilityLabel(
    quote: CustomAsiaOrder['competitor_price_quotes'][number],
): string {
    if (quote.availability === 'in_stock') return 'In stock';
    if (quote.availability === 'sold_out') return 'Sold out';

    return '';
}

function competitorSiteHomeUrl(
    quote: CustomAsiaOrder['competitor_price_quotes'][number],
): string | null {
    const url = quote.site_url?.trim();
    return url ? url : null;
}

function competitorQuoteHasProductLink(
    quote: CustomAsiaOrder['competitor_price_quotes'][number],
): boolean {
    return quote.status === 'found' && (quote.product_url?.trim().length ?? 0) > 0;
}

async function refreshCompetitorPrices(scope: 'fast' | 'full' = 'full'): Promise<void> {
    if (!orderId.value || isNew.value || isRejected.value) return;

    const name = productName.value.trim();
    if (name.length < 3) return;

    competitorPricesError.value = null;

    try {
        const res = await api.post<{ data: CustomAsiaOrder }>(
            `/api/v1/custom-asia-orders/${orderId.value}/competitor-prices/refresh`,
            { scope },
            { validateStatus: () => true, timeout: 30000 },
        );
        if (res.status !== 202 && res.status !== 200) {
            const anyData = res.data as { message?: string };
            competitorPricesError.value = anyData?.message ?? 'Competitor price search failed.';
            return;
        }

        applyOrderCompetitorFields(res.data.data);
        lastAutoCompetitorSearchName.value = name;
        startCompetitorPricesPolling();
        await scrollCompetitorPricesIntoView();
    } catch {
        competitorPricesError.value = 'Competitor price search failed.';
    }
}

async function maybeAutoRefreshCompetitorPrices(force = false): Promise<void> {
    const name = productName.value.trim();
    if (name.length < 3 || isNew.value || isRejected.value) return;
    if (!force && name === lastAutoCompetitorSearchName.value) return;
    if (competitorPricesRefreshing.value) return;

    await refreshCompetitorPrices('full');
}

function onRequestBlur(): void {
    void commitRequest();
}

function onProductNameKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
        productNameSuggestionsOpen.value = false;
        (event.target as HTMLInputElement).blur();
    } else if (event.key === 'Escape') {
        productNameSuggestionsOpen.value = false;
    }
}

function scheduleProductNameSuggestions(query: string): void {
    if (productNameSuggestTimer) {
        clearTimeout(productNameSuggestTimer);
    }

    const trimmed = query.trim();
    if (trimmed.length < 2) {
        productNameSuggestions.value = [];
        productNameSuggestionsOpen.value = false;
        productNameSuggestError.value = null;
        return;
    }

    productNameSuggestTimer = setTimeout(() => {
        void fetchProductNameSuggestions(trimmed);
    }, 300);
}

async function fetchProductNameSuggestions(query: string): Promise<void> {
    const requestId = ++productNameSuggestRequestId;
    productNameSuggestionsLoading.value = true;
    productNameSuggestError.value = null;

    try {
        const res = await api.get<CustomAsiaOrderProductNameSuggestionsResponse>(
            '/api/v1/custom-asia-orders/product-name-suggestions',
            {
                params: { q: query, limit: 8 },
                validateStatus: () => true,
            },
        );
        if (requestId !== productNameSuggestRequestId) {
            return;
        }
        if (res.status !== 200) {
            productNameSuggestions.value = [];
            productNameSuggestError.value = 'Could not load name suggestions.';
            return;
        }

        productNameSuggestions.value = res.data.data ?? [];
        productNameSuggestionsOpen.value = productNameSuggestions.value.length > 0;
    } catch {
        if (requestId !== productNameSuggestRequestId) {
            return;
        }
        productNameSuggestions.value = [];
        productNameSuggestError.value = 'Could not load name suggestions.';
    } finally {
        if (requestId === productNameSuggestRequestId) {
            productNameSuggestionsLoading.value = false;
        }
    }
}

function onProductNameInput(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    productNameSuggestionsOpen.value = true;
    scheduleProductNameSuggestions(value);
}

function onProductNameFocus(): void {
    if (productNameSuggestions.value.length > 0) {
        productNameSuggestionsOpen.value = true;
    } else if (productName.value.trim().length >= 2) {
        scheduleProductNameSuggestions(productName.value);
    }
}

function onProductNameBlur(): void {
    void (async (): Promise<void> => {
        if (productNameSuggestHideTimer) {
            clearTimeout(productNameSuggestHideTimer);
        }
        productNameSuggestHideTimer = setTimeout(() => {
            productNameSuggestionsOpen.value = false;
        }, 150);
        const saved = await commitRequest();
        if (saved) {
            await maybeAutoRefreshCompetitorPrices();
        }
    })();
}

function selectProductNameSuggestion(suggestion: CustomAsiaOrderProductNameSuggestion): void {
    void (async (): Promise<void> => {
        if (productNameSuggestHideTimer) {
            clearTimeout(productNameSuggestHideTimer);
        }
        productName.value = suggestion.title;
        productNameSuggestionsOpen.value = false;
        productNameSuggestions.value = [];
        const saved = await commitRequest();
        if (saved) {
            await maybeAutoRefreshCompetitorPrices(true);
        }
    })();
}

function suggestionPriceLabel(suggestion: CustomAsiaOrderProductNameSuggestion): string {
    if (suggestion.price_cad == null || suggestion.price_cad === '') {
        return '';
    }

    return `$${formatMoney2OrEmpty(suggestion.price_cad) ?? suggestion.price_cad} CAD`;
}

function onMerchandiserBlur(): void {
    void commitMerchandiser();
}

async function commitMerchandiserPricing(force = false): Promise<void> {
    if (isNew.value || !orderId.value || loading.value || hydrating || saving.value || !isQuoted.value) return;
    if (!force && !merchandiserPricingIsDirty()) return;

    pricingSaveState.value = 'saving';
    errorMessage.value = null;
    try {
        const res = await api.patch<{ data: CustomAsiaOrder }>(
            `/api/v1/custom-asia-orders/${orderId.value}`,
            currentMerchandiserPricingSnapshot(),
        );
        order.value = res.data.data;
        applyOrderToForm(res.data.data);
        flashSaveState(pricingSaveState, 'pricing');
    } catch (err) {
        pricingSaveState.value = 'error';
        errorMessage.value = err instanceof Error ? err.message : String(err);
    }
}

async function commitCustomerOffer(force = false): Promise<void> {
    if (isNew.value || !orderId.value || loading.value || hydrating || saving.value || !isQuoted.value) return;
    if (isOfferLocked.value) return;
    if (!force && !customerOfferIsDirty()) return;

    pricingSaveState.value = 'saving';
    errorMessage.value = null;
    try {
        const res = await api.patch<{ data: CustomAsiaOrder }>(
            `/api/v1/custom-asia-orders/${orderId.value}`,
            currentCustomerOfferSnapshot(),
        );
        order.value = res.data.data;
        applyOrderToForm(res.data.data);
        flashSaveState(pricingSaveState, 'pricing');
    } catch (err) {
        pricingSaveState.value = 'error';
        errorMessage.value = err instanceof Error ? err.message : String(err);
    }
}

async function commitMerchandiserAndCustomerPricing(force = false): Promise<void> {
    if (isNew.value || !orderId.value || loading.value || hydrating || saving.value || !isQuoted.value) return;
    if (!force && !merchandiserPricingIsDirty() && (isOfferLocked.value || !customerOfferIsDirty())) return;

    pricingSaveState.value = 'saving';
    errorMessage.value = null;
    try {
        const payload = {
            ...currentMerchandiserPricingSnapshot(),
            ...(isOfferLocked.value ? {} : currentCustomerOfferSnapshot()),
        };
        const res = await api.patch<{ data: CustomAsiaOrder }>(
            `/api/v1/custom-asia-orders/${orderId.value}`,
            payload,
        );
        order.value = res.data.data;
        applyOrderToForm(res.data.data);
        flashSaveState(pricingSaveState, 'pricing');
    } catch (err) {
        pricingSaveState.value = 'error';
        errorMessage.value = err instanceof Error ? err.message : String(err);
    }
}

async function commitPricing(): Promise<void> {
    await commitMerchandiserAndCustomerPricing();
}

function onMerchandiserPricingBlur(): void {
    clearPricingFieldEditing('merchandiserPrice');
    merchandiserCommissionOverrideCad.value = '';
    void commitMerchandiserPricing();
}

function onMerchandiserCommissionBlur(): void {
    void (async (): Promise<void> => {
        clearPricingFieldEditing('merchandiserCommission');
        const commissionRaw = String(merchandiserCommissionOverrideCad.value).trim();
        if (commissionRaw === '') {
            await commitMerchandiserPricing();
            return;
        }

        const commission = Number(commissionRaw);
        const landed = Number(String(landedCostCad.value ?? '').trim());
        if (!Number.isFinite(commission) || commission < 0 || !Number.isFinite(landed) || landed <= 0) {
            await commitMerchandiserAndCustomerPricing(true);
            return;
        }

        merchandiserPriceMultiplier.value = merchandiserMultiplierFromCommission(landed, commission).toFixed(2);
        await nextTick();

        const synced = syncPricesAfterMerchandiserCommissionChange(
            {
                ...pricingMathInput.value,
                merchandiserMultiplier: String(merchandiserPriceMultiplier.value),
                merchandiserCommissionOverrideCad: commission.toFixed(2),
            },
            commission,
        );
        merchandiserPriceCad.value = synced.merchandiserPriceCad;
        if (!isOfferLocked.value) {
            customerPriceCad.value = synced.customerPriceCad;
        }

        await commitMerchandiserAndCustomerPricing(true);
    })();
}

function onCustomerPriceBlur(): void {
    void (async (): Promise<void> => {
        clearPricingFieldEditing('customerPrice');
        const priceRaw = String(customerPriceCad.value).trim();
        if (priceRaw === '') {
            ourCommissionOverrideCad.value = '';
            await commitCustomerOffer();
            return;
        }

        const sellingPrice = Number(priceRaw);
        const spread = Number(resolveOpvSpreadCad(pricingMathInput.value) ?? '');
        const merchandiserMultiplier = Number(String(merchandiserPriceMultiplier.value).trim());
        if (
            !Number.isFinite(sellingPrice) ||
            sellingPrice <= 0 ||
            !Number.isFinite(spread) ||
            spread <= 0 ||
            !Number.isFinite(merchandiserMultiplier) ||
            merchandiserMultiplier <= 0
        ) {
            await commitCustomerOffer();
            return;
        }

        const synced = syncPricesAfterSellingPriceChange(pricingMathInput.value, sellingPrice);
        ourCommissionOverrideCad.value = synced.opvMarginOverrideCad;
        ourPriceMultiplier.value = synced.ourPriceMultiplier;
        customerPriceCad.value = synced.customerPriceCad;
        await nextTick();
        await commitCustomerOffer(true);
    })();
}

function onOurCommissionBlur(): void {
    void (async (): Promise<void> => {
        clearPricingFieldEditing('ourCommission');
        const marginRaw = String(ourCommissionOverrideCad.value).trim();
        if (marginRaw === '') {
            await commitCustomerOffer();
            return;
        }

        const margin = Number(marginRaw);
        const spread = Number(resolveOpvSpreadCad(pricingMathInput.value) ?? '');
        const merchandiserMultiplier = Number(String(merchandiserPriceMultiplier.value).trim());
        if (
            !Number.isFinite(margin) ||
            margin < 0 ||
            !Number.isFinite(spread) ||
            spread <= 0 ||
            !Number.isFinite(merchandiserMultiplier) ||
            merchandiserMultiplier <= 0
        ) {
            await commitCustomerOffer(true);
            return;
        }

        ourPriceMultiplier.value = ourMultiplierFromOpvMargin(spread, merchandiserMultiplier, margin).toFixed(2);
        await nextTick();

        const synced = syncPricesAfterOpvMarginChange(
            {
                ...pricingMathInput.value,
                ourPriceMultiplier: String(ourPriceMultiplier.value),
                opvMarginOverrideCad: margin.toFixed(2),
            },
            margin,
        );
        customerPriceCad.value = synced.customerPriceCad;
        await commitMerchandiserAndCustomerPricing(true);
    })();
}

function onDepositPercentBlur(): void {
    clearPricingFieldEditing('depositPercent');
    if (!editingPricingFields.value.has('depositAmount')) {
        depositAmountOverrideCad.value = '';
    }
    void commitCustomerOffer();
}

function onDepositAmountBlur(): void {
    clearPricingFieldEditing('depositAmount');
    const amountRaw = String(depositAmountOverrideCad.value).trim();
    if (amountRaw === '') {
        void commitCustomerOffer();
        return;
    }

    const amount = Number(amountRaw);
    const price = Number(String(customerPriceCad.value).trim());
    if (!Number.isNaN(amount) && !Number.isNaN(price) && price > 0) {
        depositPercent.value = ((amount / price) * 100).toFixed(2);
    }

    void commitCustomerOffer();
}

function onCustomerOfferBlur(): void {
    void commitCustomerOffer();
}

function onPricingBlur(): void {
    void commitPricing();
}

async function onMerchandiserMultiplierBlur(): Promise<void> {
    clearPricingFieldEditing('merchandiserMultiplier');
    const landed = Number(String(landedCostCad.value ?? '').trim());
    const multiplier = Number(String(merchandiserPriceMultiplier.value).trim());
    if (!Number.isFinite(landed) || landed <= 0 || !Number.isFinite(multiplier) || multiplier < 1) {
        await commitMerchandiserAndCustomerPricing(true);
        return;
    }

    const commission = merchandiserCommissionFromMultiplier(
        landed,
        multiplier,
        pricingCaps.value.merchandiserCommissionCapCad,
    );
    merchandiserCommissionOverrideCad.value = '';
    await nextTick();

    const synced = syncPricesAfterMerchandiserCommissionChange(
        {
            ...pricingMathInput.value,
            merchandiserMultiplier: String(multiplier),
            merchandiserCommissionOverrideCad: '',
        },
        commission,
    );
    merchandiserPriceCad.value = synced.merchandiserPriceCad;
    if (!isOfferLocked.value) {
        customerPriceCad.value = synced.customerPriceCad;
    }

    await commitMerchandiserAndCustomerPricing(true);
}

async function onOurMultiplierBlur(): Promise<void> {
    clearPricingFieldEditing('ourMultiplier');
    const spread = Number(resolveOpvSpreadCad(pricingMathInput.value) ?? '');
    const ourMultiplier = Number(String(ourPriceMultiplier.value).trim());
    const merchandiserMultiplier = Number(String(merchandiserPriceMultiplier.value).trim());
    if (
        !Number.isFinite(spread) ||
        spread <= 0 ||
        !Number.isFinite(ourMultiplier) ||
        !Number.isFinite(merchandiserMultiplier) ||
        ourMultiplier < merchandiserMultiplier
    ) {
        await commitMerchandiserAndCustomerPricing(true);
        return;
    }

    const margin = opvMarginFromOurMultiplier(
        spread,
        ourMultiplier,
        merchandiserMultiplier,
        pricingCaps.value.opvMarginCapCad,
    );
    ourCommissionOverrideCad.value = '';
    await nextTick();

    const synced = syncPricesAfterOpvMarginChange(
        {
            ...pricingMathInput.value,
            ourPriceMultiplier: String(ourMultiplier),
            opvMarginOverrideCad: '',
        },
        margin,
    );
    customerPriceCad.value = synced.customerPriceCad;
    await commitMerchandiserAndCustomerPricing(true);
}

type VisualKind = 'customer' | 'product' | 'merchandiser-order-proof';

async function uploadVisual(kind: VisualKind, file: File): Promise<void> {
    if (!orderId.value) return;

    const uploading =
        kind === 'customer'
            ? uploadingCustomer
            : kind === 'product'
              ? uploadingProduct
              : uploadingOrderProof;
    uploading.value = true;
    errorMessage.value = null;

    try {
        const form = new FormData();
        form.append('file', file);
        const endpoint =
            kind === 'customer'
                ? `/api/v1/custom-asia-orders/${orderId.value}/customer-visual`
                : kind === 'product'
                  ? `/api/v1/custom-asia-orders/${orderId.value}/product-visual`
                  : `/api/v1/custom-asia-orders/${orderId.value}/merchandiser-order-proof`;
        const res = await api.post<{ data: CustomAsiaOrder }>(endpoint, form, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        order.value = res.data.data;
        applyOrderToForm(res.data.data);
    } catch (err) {
        errorMessage.value = err instanceof Error ? err.message : String(err);
    } finally {
        uploading.value = false;
    }
}

function onCustomerFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (file) void uploadVisual('customer', file);
    input.value = '';
}

function onProductFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (file) void uploadVisual('product', file);
    input.value = '';
}

function onOrderProofFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (file) void uploadVisual('merchandiser-order-proof', file);
    input.value = '';
}

async function deleteVisual(kind: VisualKind): Promise<void> {
    if (!orderId.value || deletingVisual.value) return;

    deletingVisual.value = true;
    errorMessage.value = null;

    try {
        const res = await api.delete<{ data: CustomAsiaOrder }>(
            `/api/v1/custom-asia-orders/${orderId.value}/visuals/${kind}`,
        );
        order.value = res.data.data;
        applyOrderToForm(res.data.data);
    } catch (err) {
        errorMessage.value = err instanceof Error ? err.message : String(err);
    } finally {
        deletingVisual.value = false;
    }
}

async function confirmDelete(): Promise<void> {
    if (!orderId.value) return;
    try {
        await api.delete(`/api/v1/custom-asia-orders/${orderId.value}`);
        await router.push({ name: 'custom-asia-orders' });
    } catch (err) {
        errorMessage.value = err instanceof Error ? err.message : String(err);
        showDeleteConfirm.value = false;
    }
}

async function confirmReject(): Promise<void> {
    if (!orderId.value || rejectingOrder.value) return;

    rejectingOrder.value = true;
    errorMessage.value = null;
    try {
        const res = await api.post<{ data: CustomAsiaOrder }>(
            `/api/v1/custom-asia-orders/${orderId.value}/reject`,
        );
        order.value = res.data.data;
        applyOrderToForm(res.data.data);
        showRejectConfirm.value = false;
    } catch (err) {
        errorMessage.value = err instanceof Error ? err.message : String(err);
    } finally {
        rejectingOrder.value = false;
    }
}

async function reviveOrder(): Promise<void> {
    if (!orderId.value || revivingOrder.value || !isRejected.value) return;

    revivingOrder.value = true;
    errorMessage.value = null;
    try {
        const res = await api.post<{ data: CustomAsiaOrder }>(
            `/api/v1/custom-asia-orders/${orderId.value}/revive`,
        );
        order.value = res.data.data;
        applyOrderToForm(res.data.data);
    } catch (err) {
        errorMessage.value = err instanceof Error ? err.message : String(err);
    } finally {
        revivingOrder.value = false;
    }
}

async function lockOffer(): Promise<void> {
    if (!orderId.value || lockingOffer.value || isOfferLocked.value || !canLockOffer.value) return;

    lockingOffer.value = true;
    errorMessage.value = null;
    try {
        const res = await api.post<{ data: CustomAsiaOrder }>(
            `/api/v1/custom-asia-orders/${orderId.value}/lock-offer`,
            currentCustomerOfferSnapshot(),
        );
        order.value = res.data.data;
        applyOrderToForm(res.data.data);
    } catch (err) {
        errorMessage.value = err instanceof Error ? err.message : String(err);
    } finally {
        lockingOffer.value = false;
    }
}

async function confirmUnlockOffer(): Promise<void> {
    showUnlockOfferConfirm.value = false;
    if (!orderId.value || unlockingOffer.value || !canUnlockOffer.value) return;

    unlockingOffer.value = true;
    errorMessage.value = null;
    try {
        const res = await api.post<{ data: CustomAsiaOrder }>(
            `/api/v1/custom-asia-orders/${orderId.value}/unlock-offer`,
        );
        order.value = res.data.data;
        applyOrderToForm(res.data.data);
    } catch (err) {
        errorMessage.value = err instanceof Error ? err.message : String(err);
    } finally {
        unlockingOffer.value = false;
    }
}

async function markDepositReceived(): Promise<void> {
    if (!orderId.value || markingDepositReceived.value || isDepositReceived.value) return;

    markingDepositReceived.value = true;
    errorMessage.value = null;
    try {
        const res = await api.post<{ data: CustomAsiaOrder }>(
            `/api/v1/custom-asia-orders/${orderId.value}/deposit-received`,
        );
        order.value = res.data.data;
        applyOrderToForm(res.data.data);
    } catch (err) {
        errorMessage.value = err instanceof Error ? err.message : String(err);
    } finally {
        markingDepositReceived.value = false;
    }
}

async function markMerchandiserOrdered(): Promise<void> {
    if (!orderId.value || markingMerchandiserOrdered.value || isMerchandiserOrdered.value) return;

    markingMerchandiserOrdered.value = true;
    errorMessage.value = null;
    try {
        const res = await api.post<{ data: CustomAsiaOrder }>(
            `/api/v1/custom-asia-orders/${orderId.value}/merchandiser-ordered`,
        );
        order.value = res.data.data;
        applyOrderToForm(res.data.data);
    } catch (err) {
        errorMessage.value = err instanceof Error ? err.message : String(err);
    } finally {
        markingMerchandiserOrdered.value = false;
    }
}

async function markProductReceived(): Promise<void> {
    if (!orderId.value || markingProductReceived.value || isProductReceived.value) return;

    markingProductReceived.value = true;
    errorMessage.value = null;
    try {
        const res = await api.post<{ data: CustomAsiaOrder }>(
            `/api/v1/custom-asia-orders/${orderId.value}/product-received`,
        );
        order.value = res.data.data;
        applyOrderToForm(res.data.data);
    } catch (err) {
        errorMessage.value = err instanceof Error ? err.message : String(err);
    } finally {
        markingProductReceived.value = false;
    }
}

onMounted(async () => {
    const loads: Promise<void>[] = [
        loadFilterOptions(),
        loadCustomerMessageTemplate(),
        loadPricingCaps(),
    ];
    if (!isNew.value) loads.push(loadOrder());
    await Promise.all(loads);
});

onBeforeUnmount(() => {
    if (productNameSuggestTimer) clearTimeout(productNameSuggestTimer);
    if (productNameSuggestHideTimer) clearTimeout(productNameSuggestHideTimer);
    stopCompetitorPricesPolling();
});
</script>

<template>
    <div class="po-beta po-beta--embedded">
        <p class="po-beta__crumbs">
            <RouterLink to="/custom-orders/asia">Custom orders</RouterLink>
            / {{ isNew ? 'New' : 'Detail' }}
        </p>

        <div class="po-beta__title-row">
            <div>
                <div class="po-beta__heading">
                    <h1 class="po-beta__title po-beta__title--editable">
                        <span class="po-beta__title-prefix">{{ orderTitlePrefix }}</span>
                        <span class="cao-detail__product-name-wrap">
                            <input
                                v-model="productName"
                                type="text"
                                class="po-beta__title-input"
                                placeholder="Product name"
                                aria-label="Custom order product name"
                                autocomplete="off"
                                role="combobox"
                                :aria-expanded="productNameSuggestionsOpen"
                                aria-controls="cao-product-name-suggestions"
                                @input="onProductNameInput"
                                @focus="onProductNameFocus"
                                @blur="onProductNameBlur"
                                @keydown="onProductNameKeydown"
                            />
                            <div
                                v-if="productNameSuggestionsOpen || productNameSuggestionsLoading"
                                id="cao-product-name-suggestions"
                                class="cao-detail__product-name-suggestions"
                                role="listbox"
                            >
                                <p
                                    v-if="productNameSuggestionsLoading"
                                    class="cao-detail__product-name-suggestions-status"
                                >
                                    Searching Gundam Hangar, Hobby Sense, Argama…
                                </p>
                                <button
                                    v-for="(suggestion, index) in productNameSuggestions"
                                    :key="`${suggestion.source_key}-${index}-${suggestion.title}`"
                                    type="button"
                                    class="cao-detail__product-name-suggestion"
                                    role="option"
                                    @mousedown.prevent="selectProductNameSuggestion(suggestion)"
                                >
                                    <span class="cao-detail__product-name-suggestion-title">{{
                                        suggestion.title
                                    }}</span>
                                    <span class="cao-detail__product-name-suggestion-meta">
                                        <span>{{ suggestion.source_name }}</span>
                                        <span v-if="suggestionPriceLabel(suggestion)">{{
                                            suggestionPriceLabel(suggestion)
                                        }}</span>
                                    </span>
                                </button>
                            </div>
                        </span>
                    </h1>
                    <span
                        v-if="!isNew && order"
                        class="po-beta__status"
                        :class="{ 'is-pending': customAsiaOrderWorkflowStatusIsPending(workflowStatus) }"
                    >
                        {{ workflowStatusLabel }}
                    </span>
                    <span
                        v-if="!isNew && requestSaveHint"
                        class="cao-detail__save-hint"
                        :class="{ 'is-error': requestSaveState === 'error' }"
                    >
                        {{ requestSaveHint }}
                    </span>
                </div>
                <p class="po-beta__meta">{{ pageMeta }}</p>
            </div>
            <div v-if="!isNew && orderId" class="po-beta__actions">
                <RouterLink class="po-beta__btn po-beta__btn--ghost" to="/custom-orders/asia">
                    ← Back
                </RouterLink>
                <button
                    v-if="isRejected"
                    type="button"
                    class="po-beta__btn po-beta__btn--primary"
                    :disabled="revivingOrder"
                    @click="reviveOrder"
                >
                    {{ revivingOrder ? 'Reviving…' : 'Revive order' }}
                </button>
                <button
                    v-else
                    type="button"
                    class="po-beta__btn po-beta__btn--ghost"
                    @click="showRejectConfirm = true"
                >
                    Reject order
                </button>
                <button
                    type="button"
                    class="po-beta__btn po-beta__btn--danger"
                    @click="showDeleteConfirm = true"
                >
                    Delete order
                </button>
            </div>
        </div>

        <div
            v-if="isRejected && order?.rejected_at"
            class="cao-detail__rejected-banner"
        >
            Rejected {{ formatTorontoDateTime(order.rejected_at) }}. Revive this order if the customer changes
            their mind — pricing and notes are kept.
        </div>

        <p
            v-if="productNameSuggestError"
            class="cao-detail__product-name-suggest-error po-beta__hint"
        >
            {{ productNameSuggestError }}
        </p>

        <p
            v-if="showCompetitorPricesPanel && competitorPricesSearchSummary"
            class="cao-detail__competitor-prices-inline-status po-beta__hint"
            role="status"
            aria-live="polite"
        >
            {{ competitorPricesSearchSummary }}
        </p>

        <div v-if="showSummaryBar" class="po-beta__summary po-beta__summary--cao">
            <div>
                <strong>{{ formatMoney2OrEmpty(order?.landed_cost_cad) || '—' }}</strong>
                <span>Landed (CAD)</span>
            </div>
            <div>
                <strong>{{ order?.receive_delay_label ?? '—' }}</strong>
                <span>Receive in</span>
            </div>
            <div>
                <strong>{{ formatMoney2OrEmpty(order?.merchandiser_price_cad) || '—' }}</strong>
                <span>Merch price</span>
            </div>
            <div>
                <strong>{{ formatMoney2OrEmpty(order?.customer_price_cad) || '—' }}</strong>
                <span>Our price</span>
            </div>
            <div>
                <strong>{{ order?.deposit_percent ? `${order.deposit_percent}%` : '—' }}</strong>
                <span>Deposit</span>
            </div>
            <div>
                <strong>
                    {{
                        order?.estimated_arrival_at
                            ? formatTorontoDate(order.estimated_arrival_at)
                            : '—'
                    }}
                </strong>
                <span>ETA</span>
            </div>
        </div>

        <p v-if="loading" class="po-beta__quiet">Loading…</p>
        <p v-if="errorMessage" class="po-beta__notice po-beta__notice--error">{{ errorMessage }}</p>

        <div class="cao-detail__workspace">
            <div
                class="cao-detail__main-row"
                :class="{ 'cao-detail__main-row--with-competitor': showCompetitorPricesPanel }"
            >
                <div class="cao-detail__workspace-col cao-detail__workspace-col--left">
            <section class="cao-detail__panel">
                <header class="cao-detail__panel-head">
                    <div class="cao-detail__panel-head-row">
                        <h2 class="cao-detail__panel-title">Customer request</h2>
                        <span
                            v-if="requestSaveHint"
                            class="cao-detail__save-hint"
                            :class="{ 'is-error': requestSaveState === 'error' }"
                        >
                            {{ requestSaveHint }}
                        </span>
                    </div>
                    <p class="cao-detail__panel-desc">What the customer asked for when they reached out.</p>
                </header>

                <div class="cao-detail__panel-body">
                    <div class="cao-detail__stack">
                        <div class="cao-detail__fields-2">
                            <label class="cao-detail__field">
                                <span class="po-beta__label">Contact media</span>
                                <select
                                    v-model="customerContactMedia"
                                    class="po-beta__control"
                                    @change="onRequestBlur"
                                >
                                    <option
                                        v-for="opt in filterOptions?.contact_media ?? []"
                                        :key="opt.value"
                                        :value="opt.value"
                                    >
                                        {{ opt.label }}
                                    </option>
                                </select>
                            </label>

                            <label class="cao-detail__field">
                                <span class="po-beta__label">Customer contact</span>
                                <input
                                    v-model="customerContactValue"
                                    type="text"
                                    placeholder="@handle or profile link"
                                    class="po-beta__control"
                                    @blur="onRequestBlur"
                                />
                            </label>
                        </div>

                        <label class="cao-detail__field">
                            <span class="po-beta__label">Notes</span>
                            <textarea
                                v-model="notes"
                                rows="2"
                                class="po-beta__control po-beta__control--textarea"
                                placeholder="Optional details…"
                                @blur="onRequestBlur"
                            />
                        </label>
                    </div>

                    <div class="cao-detail__block--visual">
                        <span class="po-beta__label">Customer visual</span>
                        <div class="cao-detail__visual-block">
                            <div v-if="order?.customer_visual" class="po-beta__visual">
                                <img
                                    :src="order.customer_visual.url"
                                    :alt="order.customer_visual.filename ?? 'Customer visual'"
                                />
                                <div class="po-beta__visual-bar">
                                    <span class="po-beta__visual-name">{{
                                        order.customer_visual.filename ?? 'Uploaded image'
                                    }}</span>
                                    <button
                                        type="button"
                                        class="po-beta__visual-remove"
                                        :disabled="uploadingCustomer || deletingVisual"
                                        @click="deleteVisual('customer')"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>
                            <div class="cao-detail__upload-row">
                                <input
                                    ref="customerFileInput"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp,image/gif"
                                    class="po-beta__upload-input"
                                    :disabled="isNew || uploadingCustomer"
                                    @change="onCustomerFileChange"
                                />
                                <button
                                    type="button"
                                    class="po-beta__btn po-beta__btn--compact"
                                    :disabled="isNew || uploadingCustomer"
                                    @click="pickCustomerFile"
                                >
                                    {{ order?.customer_visual ? 'Replace' : 'Upload' }}
                                </button>
                                <span v-if="isNew" class="po-beta__hint">Create the order first.</span>
                                <span v-else-if="uploadingCustomer" class="po-beta__hint">Uploading…</span>
                                <span v-else class="po-beta__hint">JPEG, PNG, WebP, GIF</span>
                            </div>
                        </div>
                    </div>
                </div>

                <footer v-if="isNew" class="cao-detail__panel-foot">
                    <button
                        type="button"
                        class="po-beta__btn po-beta__btn--primary"
                        :disabled="saving || !customerContactValue.trim() || !productName.trim()"
                        @click="createOrder"
                    >
                        Create order
                    </button>
                </footer>
            </section>

            <section class="cao-detail__panel" :class="{ 'is-muted': isNew }">
                <header class="cao-detail__panel-head">
                    <div class="cao-detail__panel-head-row">
                        <h2 class="cao-detail__panel-title">Merchandiser</h2>
                        <span
                            v-if="merchandiserSaveHint"
                            class="cao-detail__save-hint"
                            :class="{ 'is-error': merchandiserSaveState === 'error' }"
                        >
                            {{ merchandiserSaveHint }}
                        </span>
                    </div>
                    <p class="cao-detail__panel-desc">
                        Quote costs for customer pricing. Landed cost uses today&apos;s FX rate.
                    </p>
                </header>

                <div class="cao-detail__panel-body cao-detail__panel-body--merch">
                    <div class="cao-detail__stack">
                        <section class="cao-detail__merch-block">
                            <h3 class="cao-detail__merch-tier">Quote</h3>
                            <div class="cao-detail__fields-2">
                                <label class="cao-detail__field">
                                    <span class="po-beta__label">Product cost</span>
                                    <div class="cao-detail__inline cao-detail__inline--money">
                                        <input
                                            v-model="productCostAmount"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            class="po-beta__control po-beta__control--amount"
                                            :disabled="isNew"
                                            @blur="onMerchandiserBlur"
                                        />
                                        <select
                                            v-model="productCostCurrency"
                                            class="po-beta__control po-beta__control--currency"
                                            :disabled="isNew"
                                            @change="onMerchandiserBlur"
                                        >
                                            <option
                                                v-for="opt in filterOptions?.currencies ?? []"
                                                :key="opt.value"
                                                :value="opt.value"
                                            >
                                                {{ opt.label }}
                                            </option>
                                        </select>
                                    </div>
                                </label>

                                <label class="cao-detail__field">
                                    <span class="po-beta__label">Shipping cost</span>
                                    <div class="cao-detail__inline cao-detail__inline--money">
                                        <input
                                            v-model="shippingCostAmount"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            class="po-beta__control po-beta__control--amount"
                                            :disabled="isNew"
                                            @blur="onMerchandiserBlur"
                                        />
                                        <select
                                            v-model="shippingCostCurrency"
                                            class="po-beta__control po-beta__control--currency"
                                            :disabled="isNew"
                                            @change="onMerchandiserBlur"
                                        >
                                            <option
                                                v-for="opt in filterOptions?.currencies ?? []"
                                                :key="opt.value"
                                                :value="opt.value"
                                            >
                                                {{ opt.label }}
                                            </option>
                                        </select>
                                    </div>
                                </label>
                            </div>

                            <label class="cao-detail__field">
                                <span class="po-beta__label">Shipping delay</span>
                                <div class="cao-detail__inline cao-detail__inline--delay">
                                    <input
                                        v-model.number="receiveDelayAmount"
                                        type="number"
                                        min="1"
                                        step="1"
                                        placeholder="6"
                                        class="po-beta__control po-beta__control--qty"
                                        :disabled="isNew"
                                        @blur="onMerchandiserBlur"
                                    />
                                    <select
                                        v-model="receiveDelayUnit"
                                        class="po-beta__control po-beta__control--unit"
                                        :disabled="isNew"
                                        @change="onMerchandiserBlur"
                                    >
                                        <option
                                            v-for="opt in filterOptions?.receive_delay_units ?? []"
                                            :key="opt.value"
                                            :value="opt.value"
                                        >
                                            {{ opt.label }}
                                        </option>
                                    </select>
                                    <span v-if="order?.receive_delay_label" class="cao-detail__inline-note">
                                        ≈ {{ order.receive_delay_label }}
                                    </span>
                                </div>
                            </label>

                            <p v-if="order?.landed_cost_cad || order?.fx_rate_date" class="cao-detail__fx-note">
                                <strong>Landed {{ formatMoney2OrEmpty(order?.landed_cost_cad) || '—' }} CAD</strong>
                                <template v-if="order?.fx_rate_date">
                                    · FX {{ order.fx_rate_date }}
                                    <template v-if="productFxCadToForeignLabel">
                                        · product {{ productFxCadToForeignLabel }}
                                    </template>
                                    <template
                                        v-if="
                                            shippingFxCadToForeignLabel &&
                                            shippingFxCadToForeignLabel !== productFxCadToForeignLabel
                                        "
                                    >
                                        · shipping {{ shippingFxCadToForeignLabel }}
                                    </template>
                                </template>
                            </p>
                        </section>
                    </div>

                    <div class="cao-detail__merch-visual">
                        <span class="po-beta__label">Product visual</span>
                        <div class="cao-detail__visual-block">
                            <div v-if="order?.product_visual" class="po-beta__visual">
                                <img
                                    :src="order.product_visual.url"
                                    :alt="order.product_visual.filename ?? 'Product visual'"
                                />
                                <div class="po-beta__visual-bar">
                                    <span class="po-beta__visual-name">{{
                                        order.product_visual.filename ?? 'Uploaded image'
                                    }}</span>
                                    <button
                                        type="button"
                                        class="po-beta__visual-remove"
                                        :disabled="uploadingProduct || deletingVisual"
                                        @click="deleteVisual('product')"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>
                            <div class="cao-detail__upload-row">
                                <input
                                    ref="productFileInput"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp,image/gif"
                                    class="po-beta__upload-input"
                                    :disabled="isNew || uploadingProduct"
                                    @change="onProductFileChange"
                                />
                                <button
                                    type="button"
                                    class="po-beta__btn po-beta__btn--compact"
                                    :disabled="isNew || uploadingProduct"
                                    @click="pickProductFile"
                                >
                                    {{ order?.product_visual ? 'Replace' : 'Upload' }}
                                </button>
                                <span v-if="uploadingProduct" class="po-beta__hint">Uploading…</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
                </div>

                <div
                    v-if="showCompetitorPricesPanel"
                    class="cao-detail__workspace-col cao-detail__workspace-col--right"
                >
                    <section
                        ref="competitorPricesPanelRef"
                        class="cao-detail__panel cao-detail__panel--competitor-prices"
                    >
                        <header class="cao-detail__panel-head">
                            <div class="cao-detail__panel-head-row">
                                <h2 class="cao-detail__panel-title">Canadian competitor prices</h2>
                                <div class="cao-detail__competitor-prices-actions">
                                    <button
                                        type="button"
                                        class="po-beta__btn po-beta__btn--ghost"
                                        :disabled="competitorPricesLoading"
                                        @click="refreshCompetitorPrices('fast')"
                                    >
                                        {{ competitorPricesLoading ? 'Searching…' : 'Refresh fast (4)' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="po-beta__btn po-beta__btn--ghost"
                                        :disabled="competitorPricesLoading"
                                        @click="refreshCompetitorPrices('full')"
                                    >
                                        {{ competitorPricesLoading ? 'Searching…' : 'All retailers (8)' }}
                                    </button>
                                </div>
                            </div>
                            <p class="cao-detail__panel-desc">
                                When you pick or save a product name, all CAD retailers are searched
                                <strong>in parallel</strong>. Retailer names open the store homepage; prices
                                open the product page when found.
                            </p>
                            <p
                                v-if="competitorPricesSearchSummary"
                                class="cao-detail__competitor-prices-meta po-beta__hint"
                                role="status"
                            >
                                {{ competitorPricesSearchSummary }}
                            </p>
                            <p
                                v-if="competitorPricesStale"
                                class="cao-detail__competitor-prices-stale po-beta__hint"
                            >
                                Product name changed since the last search — refresh to update prices.
                            </p>
                            <p
                                v-else-if="order?.competitor_prices_fetched_at"
                                class="cao-detail__competitor-prices-meta po-beta__hint"
                            >
                                Last searched {{ formatTorontoDateTime(order.competitor_prices_fetched_at) }}
                                for “{{ order.competitor_prices_product_name }}”.
                            </p>
                        </header>

                        <div class="cao-detail__panel-body cao-detail__panel-body--competitor">
                            <p v-if="competitorPricesError" class="cao-detail__competitor-prices-error">
                                {{ competitorPricesError }}
                            </p>
                            <div
                                v-if="!competitorPricesLoading && competitorPriceDisplayRows.length === 0"
                                class="po-beta__hint"
                            >
                                No competitor prices yet — pick a suggested name or blur the product name field
                                to search.
                            </div>
                            <table
                                v-else-if="competitorPriceDisplayRows.length > 0"
                                class="cao-detail__competitor-prices-table"
                            >
                                <thead>
                                    <tr>
                                        <th>Retailer</th>
                                        <th>Price (CAD)</th>
                                        <th>Availability</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="quote in competitorPriceDisplayRows"
                                        :key="quote.site_key"
                                        :class="{
                                            'is-found': quote.status === 'found',
                                            'is-miss': quote.status !== 'found' && quote.status !== 'pending',
                                            'is-pending': quote.status === 'pending',
                                        }"
                                    >
                                        <td>
                                            <a
                                                v-if="competitorSiteHomeUrl(quote)"
                                                :href="competitorSiteHomeUrl(quote)!"
                                                class="cao-detail__competitor-retailer-link"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                {{ quote.site_name }}
                                            </a>
                                            <span v-else>{{ quote.site_name }}</span>
                                        </td>
                                        <td>
                                            <a
                                                v-if="competitorQuoteHasProductLink(quote)"
                                                :href="quote.product_url!"
                                                class="cao-detail__competitor-price-link"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                :title="quote.error_message ?? undefined"
                                            >
                                                {{ competitorQuotePriceLabel(quote) }}
                                            </a>
                                            <span
                                                v-else
                                                class="cao-detail__competitor-price-text"
                                                :title="quote.error_message ?? undefined"
                                            >
                                                {{ competitorQuotePriceLabel(quote) }}
                                            </span>
                                        </td>
                                        <td>{{ competitorQuoteAvailabilityLabel(quote) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>

            <section
                class="cao-detail__panel cao-detail__panel--pricing"
                :class="{ 'is-muted': isNew || !isQuoted }"
            >
                <header class="cao-detail__panel-head">
                    <div class="cao-detail__panel-head-row">
                        <h2 class="cao-detail__panel-title">Customer offer</h2>
                        <span
                            v-if="pricingSaveHint"
                            class="cao-detail__save-hint"
                            :class="{ 'is-error': pricingSaveState === 'error' }"
                        >
                            {{ pricingSaveHint }}
                        </span>
                    </div>
                    <p class="cao-detail__panel-desc">
                        Set price and deposit, then lock in the customer offer to quote the customer and unlock
                        fulfillment. Merchandiser pricing stays editable after lock.
                    </p>
                </header>

                <div
                    class="cao-detail__panel-body cao-detail__panel-body--pricing"
                    :class="{ 'cao-detail__panel-body--offer-summary': showPricingSummary || showOfferLayoutPlaceholder }"
                >
                    <div
                        v-if="showOfferLayoutPlaceholder"
                        class="cao-detail__offer-layout cao-detail__offer-layout--placeholder"
                        aria-hidden="true"
                    >
                        <div class="cao-detail__offer-primary">
                            <div class="cao-detail__offer-skeleton-box" />
                        </div>
                        <aside class="cao-detail__offer-fulfillment">
                            <div class="cao-detail__offer-skeleton-box cao-detail__offer-skeleton-box--fulfillment" />
                        </aside>
                        <aside class="cao-detail__offer-message">
                            <div class="cao-detail__offer-skeleton-box cao-detail__offer-skeleton-box--message" />
                        </aside>
                    </div>

                    <template v-else-if="showCustomerOfferContent">
                    <div v-if="!showPricingSummary" class="cao-detail__pricing-col">
                        <p class="cao-detail__pricing-tier">Merchandiser</p>
                        <div class="cao-detail__fields-2">
                            <label class="cao-detail__field">
                                <span class="po-beta__label">Multiplier</span>
                                <div class="cao-detail__inline cao-detail__inline--delay">
                                    <input
                                        v-model="merchandiserPriceMultiplier"
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        class="po-beta__control po-beta__control--qty"
                                        :disabled="isNew || !isQuoted"
                                        @focus="markPricingFieldEditing('merchandiserMultiplier')"
                                        @blur="onMerchandiserMultiplierBlur"
                                    />
                                    <span class="cao-detail__inline-note">× landed</span>
                                </div>
                            </label>

                            <label class="cao-detail__field">
                                <span class="po-beta__label">Price (CAD)</span>
                                <div class="cao-detail__inline">
                                    <input
                                        v-model="merchandiserPriceCad"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        class="po-beta__control po-beta__control--amount"
                                        :disabled="isNew || !isQuoted"
                                        @blur="onMerchandiserPricingBlur"
                                    />
                                </div>
                            </label>
                        </div>

                        <p
                            v-if="merchandiserEffectiveMultiplier || merchandiserCommissionCad"
                            class="cao-detail__pricing-stats"
                        >
                            <template v-if="merchandiserEffectiveMultiplier">
                                <strong>Actual {{ merchandiserEffectiveMultiplier }}×</strong>
                            </template>
                            <template v-if="merchandiserCommissionCad">
                                · Merchandiser commission
                                {{ formatMoney2OrEmpty(merchandiserCommissionCad) }}
                            </template>
                        </p>

                        <p v-if="merchandiserPayPriceCad" class="cao-detail__pricing-stats">
                            Pay merchandiser {{ formatMoney2OrEmpty(merchandiserPayPriceCad) }}
                        </p>
                    </div>

                    <div v-if="!showPricingSummary" class="cao-detail__pricing-col cao-detail__pricing-col--our">
                        <p class="cao-detail__pricing-tier cao-detail__pricing-tier--our">
                            Our offer
                            <span v-if="order?.offer_locked_at" class="cao-detail__milestone-done">
                                Locked {{ formatTorontoDateTime(order.offer_locked_at) }}
                            </span>
                        </p>
                        <div class="cao-detail__fields-2">
                            <label class="cao-detail__field">
                                <span class="po-beta__label">Multiplier</span>
                                <div class="cao-detail__inline cao-detail__inline--delay">
                                    <input
                                        v-model="ourPriceMultiplier"
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        class="po-beta__control po-beta__control--qty"
                                        :disabled="isNew || !isQuoted || isOfferLocked"
                                        @focus="markPricingFieldEditing('ourMultiplier')"
                                        @blur="onOurMultiplierBlur"
                                    />
                                    <span class="cao-detail__inline-note">× landed</span>
                                </div>
                            </label>

                            <label class="cao-detail__field">
                                <span class="po-beta__label">Customer price (CAD)</span>
                                <div class="cao-detail__inline">
                                    <input
                                        v-model="customerPriceCad"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        class="po-beta__control po-beta__control--amount"
                                        :disabled="isNew || !isQuoted || isOfferLocked"
                                        @blur="onCustomerPriceBlur"
                                    />
                                </div>
                            </label>
                        </div>

                        <p v-if="ourEffectiveMultiplier || ourCommissionCad" class="cao-detail__pricing-stats">
                            <template v-if="ourEffectiveMultiplier">
                                <strong>Actual {{ ourEffectiveMultiplier }}×</strong>
                            </template>
                            <template v-if="ourCommissionCad">
                                · Our commission {{ formatMoney2OrEmpty(ourCommissionCad) }}
                            </template>
                        </p>

                        <p v-if="componentSellingPriceCad" class="cao-detail__pricing-stats">
                            Component total {{ formatMoney2OrEmpty(componentSellingPriceCad) }}
                        </p>

                        <div class="cao-detail__fields-2">
                            <label class="cao-detail__field">
                                <span class="po-beta__label">Deposit</span>
                                <div class="cao-detail__inline cao-detail__inline--delay">
                                    <input
                                        v-model="depositPercent"
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        class="po-beta__control po-beta__control--qty"
                                        :disabled="isNew || !isQuoted || isOfferLocked"
                                        @focus="markPricingFieldEditing('depositPercent')"
                                        @blur="onDepositPercentBlur"
                                        @keydown="preventEnterSubmit"
                                    />
                                    <span class="cao-detail__inline-note">%</span>
                                </div>
                            </label>

                            <label class="cao-detail__field">
                                <span class="po-beta__label">Deposit (CAD)</span>
                                <div class="cao-detail__inline">
                                    <input
                                        v-model="depositAmountOverrideCad"
                                        type="text"
                                        inputmode="decimal"
                                        class="po-beta__control po-beta__control--amount"
                                        placeholder="Override"
                                        :disabled="isNew || !isQuoted || isOfferLocked"
                                        @focus="markPricingFieldEditing('depositAmount')"
                                        @blur="onDepositAmountBlur"
                                        @keydown="preventEnterSubmit"
                                    />
                                </div>
                            </label>
                        </div>

                        <p v-if="customerPriceCad && !showPricingSummary" class="cao-detail__fx-note">
                            <strong>Balance {{ formatMoney2OrEmpty(pricingBalanceCad) }}</strong>
                            <template v-if="pricingDepositAmountCad">
                                · after {{ formatMoney2OrEmpty(pricingDepositAmountCad) }} deposit
                                <template v-if="depositAmountOverrideCad.trim() === ''">
                                    ({{ depositPercent }}%)
                                </template>
                            </template>
                        </p>

                        <div v-if="!isOfferLocked && isQuoted && !isNew" class="cao-detail__pricing-lock">
                            <button
                                type="button"
                                class="po-beta__btn"
                                :disabled="!canLockOffer || lockingOffer"
                                @click="lockOffer"
                            >
                                {{ lockingOffer ? 'Locking…' : 'Lock in offer' }}
                            </button>
                            <span class="po-beta__hint">
                                Locks customer price and deposit only; merchandiser pricing stays editable.
                            </span>
                        </div>

                        <div v-else-if="isOfferLocked && isQuoted && !isNew" class="cao-detail__pricing-lock">
                            <button
                                v-if="canUnlockOffer"
                                type="button"
                                class="po-beta__btn po-beta__btn--ghost"
                                :disabled="unlockingOffer"
                                @click="showUnlockOfferConfirm = true"
                            >
                                {{ unlockingOffer ? 'Unlocking…' : 'Unlock offer' }}
                            </button>
                            <span v-if="canUnlockOffer" class="po-beta__hint">
                                Reopens customer price, OPV markup, and deposit for editing.
                            </span>
                            <span v-else-if="offerUnlockBlockedHint" class="po-beta__hint">
                                {{ offerUnlockBlockedHint }}
                            </span>
                        </div>
                    </div>

                    <p v-if="!isQuoted && !isNew" class="cao-detail__pricing-hint po-beta__hint">
                        Complete the merchandiser quote before setting customer price and deposit.
                    </p>

                    <p
                        v-if="isQuoted && !isNew && !isOfferLocked"
                        class="cao-detail__pricing-hint po-beta__hint"
                    >
                        Lock in the customer offer to unlock fulfillment and reconciliation.
                    </p>

                    <div v-if="showPricingSummary" class="cao-detail__offer-layout">
                        <div class="cao-detail__offer-primary">
                    <div class="cao-detail__pricing-summary">
                        <h3 class="cao-detail__pricing-summary-title">Pricing summary</h3>

                        <div
                            class="cao-detail__pricing-summary-grid"
                            :class="
                                pricingSummaryShowsOriginal
                                    ? 'cao-detail__pricing-summary-grid--with-original'
                                    : 'cao-detail__pricing-summary-grid--cad-only'
                            "
                        >
                            <div class="cao-detail__pricing-summary-head">
                                <span class="cao-detail__pricing-summary-head-cell"></span>
                                <span
                                    v-if="pricingSummaryShowsOriginal"
                                    class="cao-detail__pricing-summary-head-cell"
                                >
                                    Original
                                </span>
                                <span class="cao-detail__pricing-summary-head-cell">CAD</span>
                            </div>

                            <template v-for="row in pricingSummaryRows" :key="row.key">
                                <div
                                    class="cao-detail__pricing-summary-label"
                                    :class="{ 'is-total': row.key === 'selling-price' || row.key === 'remaining' }"
                                >
                                    {{ row.label }}
                                </div>
                                <div
                                    v-if="pricingSummaryShowsOriginal"
                                    class="cao-detail__pricing-summary-value"
                                    :class="{
                                        'is-total': row.key === 'selling-price' || row.key === 'remaining',
                                        'is-empty': row.originalText === null && row.tierControls === null && row.editable !== 'deposit',
                                        'cao-detail__pricing-summary-value--tools': row.tierControls !== null || row.editable === 'deposit',
                                    }"
                                >
                                    <template v-if="row.tierControls === 'merchandiser'">
                                        <div class="cao-detail__pricing-summary-tier">
                                            <input
                                                v-model="merchandiserPriceMultiplier"
                                                type="number"
                                                min="0.01"
                                                step="0.01"
                                                class="cao-detail__pricing-summary-tier-mult"
                                                :disabled="isNew || !isQuoted"
                                                @focus="markPricingFieldEditing('merchandiserMultiplier')"
                                                @blur="onMerchandiserMultiplierBlur"
                                            />
                                            <span class="cao-detail__inline-note">× landed</span>
                                            <span
                                                v-if="merchandiserPayPriceCad"
                                                class="cao-detail__inline-note cao-detail__pricing-summary-tier-hint"
                                            >
                                                {{ formatMoney2OrEmpty(merchandiserPayPriceCad) }}
                                            </span>
                                        </div>
                                    </template>
                                    <template v-else-if="row.tierControls === 'our-offer'">
                                        <div class="cao-detail__pricing-summary-tier">
                                            <input
                                                v-model="ourPriceMultiplier"
                                                type="number"
                                                min="0.01"
                                                step="0.01"
                                                class="cao-detail__pricing-summary-tier-mult"
                                                :disabled="isNew || !isQuoted || isOfferLocked"
                                                @focus="markPricingFieldEditing('ourMultiplier')"
                                                @blur="onOurMultiplierBlur"
                                            />
                                            <span class="cao-detail__inline-note">× spread</span>
                                            <span
                                                v-if="opvSpreadCad"
                                                class="cao-detail__inline-note cao-detail__pricing-summary-tier-hint"
                                            >
                                                {{ formatMoney2OrEmpty(opvSpreadCad) }}
                                            </span>
                                        </div>
                                    </template>
                                    <template v-else-if="row.editable === 'deposit'">
                                        <div class="cao-detail__pricing-summary-tier">
                                            <input
                                                v-model="depositPercent"
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="0.01"
                                                class="cao-detail__pricing-summary-tier-mult"
                                                :disabled="isNew || !isQuoted || isOfferLocked"
                                                @focus="markPricingFieldEditing('depositPercent')"
                                                @blur="onDepositPercentBlur"
                                                @keydown="preventEnterSubmit"
                                            />
                                            <span class="cao-detail__inline-note">%</span>
                                        </div>
                                    </template>
                                    <template v-else>
                                        {{ row.originalText ?? '—' }}
                                    </template>
                                </div>
                                <div
                                    class="cao-detail__pricing-summary-value"
                                    :class="{
                                        'is-total': row.key === 'selling-price' || row.key === 'remaining',
                                        'is-empty': row.cadText === null && row.editable === null,
                                        'cao-detail__pricing-summary-value--tools':
                                            !pricingSummaryShowsOriginal &&
                                            (row.tierControls !== null || row.editable === 'deposit'),
                                    }"
                                >
                                    <div
                                        v-if="!pricingSummaryShowsOriginal && row.tierControls === 'merchandiser'"
                                        class="cao-detail__pricing-summary-tier cao-detail__pricing-summary-tier--stacked"
                                    >
                                        <input
                                            v-model="merchandiserPriceMultiplier"
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            class="cao-detail__pricing-summary-tier-mult"
                                            :disabled="isNew || !isQuoted"
                                            @focus="markPricingFieldEditing('merchandiserMultiplier')"
                                            @blur="onMerchandiserMultiplierBlur"
                                        />
                                        <span class="cao-detail__inline-note">× landed</span>
                                        <span
                                            v-if="merchandiserPayPriceCad"
                                            class="cao-detail__inline-note cao-detail__pricing-summary-tier-hint"
                                        >
                                            {{ formatMoney2OrEmpty(merchandiserPayPriceCad) }}
                                        </span>
                                    </div>
                                    <div
                                        v-else-if="!pricingSummaryShowsOriginal && row.tierControls === 'our-offer'"
                                        class="cao-detail__pricing-summary-tier cao-detail__pricing-summary-tier--stacked"
                                    >
                                        <input
                                            v-model="ourPriceMultiplier"
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            class="cao-detail__pricing-summary-tier-mult"
                                            :disabled="isNew || !isQuoted || isOfferLocked"
                                            @focus="markPricingFieldEditing('ourMultiplier')"
                                            @blur="onOurMultiplierBlur"
                                        />
                                        <span class="cao-detail__inline-note">× spread</span>
                                        <span
                                            v-if="opvSpreadCad"
                                            class="cao-detail__inline-note cao-detail__pricing-summary-tier-hint"
                                        >
                                            {{ formatMoney2OrEmpty(opvSpreadCad) }}
                                        </span>
                                    </div>
                                    <div
                                        v-else-if="!pricingSummaryShowsOriginal && row.editable === 'deposit'"
                                        class="cao-detail__pricing-summary-tier cao-detail__pricing-summary-tier--stacked"
                                    >
                                        <input
                                            v-model="depositPercent"
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            class="cao-detail__pricing-summary-tier-mult"
                                            :disabled="isNew || !isQuoted || isOfferLocked"
                                            @focus="markPricingFieldEditing('depositPercent')"
                                            @blur="onDepositPercentBlur"
                                            @keydown="preventEnterSubmit"
                                        />
                                        <span class="cao-detail__inline-note">%</span>
                                    </div>
                                    <input
                                        v-if="row.editable === 'merchandiser-commission'"
                                        v-model="merchandiserCommissionOverrideCad"
                                        type="text"
                                        inputmode="decimal"
                                        class="cao-detail__pricing-summary-input"
                                        :placeholder="
                                            formatMoney2OrEmpty(merchandiserCommissionCad) || 'Override'
                                        "
                                        :disabled="isNew || !isQuoted"
                                        @focus="markPricingFieldEditing('merchandiserCommission')"
                                        @blur="onMerchandiserCommissionBlur"
                                        @keydown="preventEnterSubmit"
                                    />
                                    <input
                                        v-else-if="row.editable === 'opv-markup'"
                                        v-model="ourCommissionOverrideCad"
                                        type="text"
                                        inputmode="decimal"
                                        class="cao-detail__pricing-summary-input"
                                        :placeholder="formatMoney2OrEmpty(ourCommissionCad) || 'Override'"
                                        :disabled="isNew || !isQuoted || isOfferLocked"
                                        @focus="markPricingFieldEditing('ourCommission')"
                                        @blur="onOurCommissionBlur"
                                        @keydown="preventEnterSubmit"
                                    />
                                    <input
                                        v-else-if="row.editable === 'customer-price'"
                                        v-model="customerPriceCad"
                                        type="text"
                                        inputmode="decimal"
                                        class="cao-detail__pricing-summary-input"
                                        :placeholder="
                                            formatMoney2OrEmpty(customerPriceCad) ||
                                            formatMoney2OrEmpty(effectiveCustomerPriceCad) ||
                                            'Price'
                                        "
                                        :disabled="isNew || !isQuoted || isOfferLocked"
                                        @focus="markPricingFieldEditing('customerPrice')"
                                        @blur="onCustomerPriceBlur"
                                        @keydown="preventEnterSubmit"
                                    />
                                    <input
                                        v-else-if="row.editable === 'deposit'"
                                        v-model="depositAmountOverrideCad"
                                        type="text"
                                        inputmode="decimal"
                                        class="cao-detail__pricing-summary-input"
                                        :placeholder="
                                            formatMoney2OrEmpty(pricingDepositFromPercentCad) || 'Override'
                                        "
                                        :disabled="isNew || !isQuoted || isOfferLocked"
                                        @focus="markPricingFieldEditing('depositAmount')"
                                        @blur="onDepositAmountBlur"
                                        @keydown="preventEnterSubmit"
                                    />
                                    <template v-else>
                                        {{ formatPricingSummaryCell(row.cadText) }}
                                    </template>
                                </div>
                            </template>
                        </div>

                        <div v-if="isQuoted && !isNew" class="cao-detail__pricing-summary-footer">
                            <div v-if="!isOfferLocked" class="cao-detail__pricing-lock">
                                <button
                                    type="button"
                                    class="po-beta__btn"
                                    :disabled="!canLockOffer || lockingOffer"
                                    @click="lockOffer"
                                >
                                    {{ lockingOffer ? 'Locking…' : 'Lock in offer' }}
                                </button>
                                <span class="po-beta__hint">
                                    Locks customer price and deposit only; merchandiser pricing stays editable.
                                </span>
                            </div>
                            <template v-else>
                                <p class="cao-detail__pricing-summary-locked po-beta__hint">
                                    <span class="cao-detail__pricing-summary-locked-line">
                                        Customer offer locked
                                        {{ order?.offer_locked_at ? formatTorontoDateTime(order.offer_locked_at) : '' }}.
                                    </span>
                                    <span class="cao-detail__pricing-summary-locked-line">
                                        Merchandiser commission stays editable; customer price, OPV markup, and deposit
                                        are locked.
                                    </span>
                                </p>
                                <div v-if="canUnlockOffer" class="cao-detail__pricing-lock">
                                    <button
                                        type="button"
                                        class="po-beta__btn po-beta__btn--ghost"
                                        :disabled="unlockingOffer"
                                        @click="showUnlockOfferConfirm = true"
                                    >
                                        {{ unlockingOffer ? 'Unlocking…' : 'Unlock offer' }}
                                    </button>
                                    <span class="po-beta__hint">
                                        Reopens customer price, OPV markup, and deposit for editing.
                                    </span>
                                </div>
                                <p v-else-if="offerUnlockBlockedHint" class="po-beta__hint">
                                    {{ offerUnlockBlockedHint }}
                                </p>
                            </template>
                        </div>
                    </div>
                        </div>

                        <aside v-if="!isNew && isQuoted" class="cao-detail__offer-fulfillment">
                            <h3 class="cao-detail__offer-fulfillment-title">Fulfillment</h3>
                            <p class="cao-detail__offer-fulfillment-desc po-beta__hint">
                                Track deposit, merchandiser order, and product receipt. ETA uses shipping delay.
                            </p>
                            <div class="cao-detail__milestones-col">
                                <article class="cao-detail__milestone-card">
                                    <div class="cao-detail__milestone-head">
                                        <span class="po-beta__label">Deposit received</span>
                                        <span
                                            v-if="order?.deposit_received_at"
                                            class="cao-detail__milestone-done"
                                        >
                                            {{ formatTorontoDateTime(order.deposit_received_at) }}
                                        </span>
                                    </div>
                                    <button
                                        v-if="!order?.deposit_received_at"
                                        type="button"
                                        class="po-beta__btn po-beta__btn--compact"
                                        :disabled="isNew || !isOfferLocked || markingDepositReceived"
                                        @click="markDepositReceived"
                                    >
                                        {{ markingDepositReceived ? 'Saving…' : 'Mark deposit in' }}
                                    </button>
                                </article>

                                <article class="cao-detail__milestone-card">
                                    <div class="cao-detail__milestone-head">
                                        <span class="po-beta__label">Merchandiser ordered</span>
                                        <span
                                            v-if="order?.merchandiser_ordered_at"
                                            class="cao-detail__milestone-done"
                                        >
                                            {{ formatTorontoDateTime(order.merchandiser_ordered_at) }}
                                        </span>
                                    </div>
                                    <button
                                        v-if="!order?.merchandiser_ordered_at"
                                        type="button"
                                        class="po-beta__btn po-beta__btn--compact"
                                        :disabled="
                                            isNew ||
                                            !isOfferLocked ||
                                            !isDepositReceived ||
                                            markingMerchandiserOrdered
                                        "
                                        @click="markMerchandiserOrdered"
                                    >
                                        {{ markingMerchandiserOrdered ? 'Saving…' : 'Mark ordered' }}
                                    </button>
                                    <div class="cao-detail__milestone-proof">
                                        <span class="po-beta__label">Order proof</span>
                                        <p class="po-beta__hint">Screenshot from merchandiser.</p>
                                        <div
                                            v-if="order?.merchandiser_order_proof_visual"
                                            class="po-beta__visual cao-detail__proof-thumb"
                                        >
                                            <img
                                                :src="order.merchandiser_order_proof_visual.url"
                                                :alt="
                                                    order.merchandiser_order_proof_visual.filename ??
                                                    'Merchandiser order proof'
                                                "
                                            />
                                        </div>
                                        <div class="cao-detail__upload-row">
                                            <input
                                                ref="orderProofFileInput"
                                                type="file"
                                                accept="image/jpeg,image/png,image/webp,image/gif"
                                                class="po-beta__upload-input"
                                                :disabled="isNew || !isOfferLocked || uploadingOrderProof"
                                                @change="onOrderProofFileChange"
                                            />
                                            <button
                                                type="button"
                                                class="po-beta__btn po-beta__btn--compact"
                                                :disabled="isNew || !isOfferLocked || uploadingOrderProof"
                                                @click="pickOrderProofFile"
                                            >
                                                {{
                                                    order?.merchandiser_order_proof_visual
                                                        ? 'Replace'
                                                        : 'Upload'
                                                }}
                                            </button>
                                            <button
                                                v-if="order?.merchandiser_order_proof_visual"
                                                type="button"
                                                class="po-beta__btn po-beta__btn--compact po-beta__btn--ghost"
                                                :disabled="uploadingOrderProof || deletingVisual"
                                                @click="deleteVisual('merchandiser-order-proof')"
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    </div>
                                </article>

                                <article class="cao-detail__milestone-card">
                                    <div class="cao-detail__milestone-head">
                                        <span class="po-beta__label">Product received</span>
                                        <span
                                            v-if="order?.product_received_at"
                                            class="cao-detail__milestone-done"
                                        >
                                            {{ formatTorontoDateTime(order.product_received_at) }}
                                        </span>
                                    </div>
                                    <button
                                        v-if="!order?.product_received_at"
                                        type="button"
                                        class="po-beta__btn po-beta__btn--compact"
                                        :disabled="
                                            isNew ||
                                            !isMerchandiserOrdered ||
                                            markingProductReceived
                                        "
                                        @click="markProductReceived"
                                    >
                                        {{ markingProductReceived ? 'Saving…' : 'Mark product received' }}
                                    </button>
                                    <p
                                        v-if="!order?.product_received_at && isMerchandiserOrdered"
                                        class="po-beta__hint"
                                    >
                                        Mark when the product is in hand (distinct from merchandiser arrival date).
                                    </p>
                                </article>
                            </div>
                            <div class="cao-detail__fulfillment-foot">
                                <p v-if="order?.estimated_arrival_at" class="cao-detail__fx-note">
                                    <strong>ETA {{ formatTorontoDate(order.estimated_arrival_at) }}</strong>
                                    <template v-if="order.receive_delay_label">
                                        · {{ order.receive_delay_label }} after merchandiser order
                                    </template>
                                </p>
                                <p
                                    v-else-if="order?.merchandiser_ordered_at && order.receive_delay_label"
                                    class="po-beta__hint"
                                >
                                    ETA pending — shipping delay is required.
                                </p>
                                <p v-else-if="!isOfferLocked" class="po-beta__hint">
                                    Lock in the customer offer to enable fulfillment tracking.
                                </p>
                                <p v-else-if="isOfferLocked && !isDepositReceived" class="po-beta__hint">
                                    Mark deposit received before placing the merchandiser order.
                                </p>
                            </div>
                        </aside>

                        <aside v-if="isQuoted && !isNew" class="cao-detail__offer-message cao-detail__customer-message">
                            <div class="cao-detail__customer-message-head">
                                <h3 class="cao-detail__offer-message-title">Customer message</h3>
                                <button
                                    type="button"
                                    class="po-beta__btn po-beta__btn--compact"
                                    :disabled="!customerOfferMessage"
                                    @click="copyCustomerOfferMessage"
                                >
                                    {{ customerMessageCopied ? 'Copied' : 'Copy message' }}
                                </button>
                            </div>
                            <textarea
                                v-if="customerOfferMessage"
                                class="cao-detail__customer-message-body po-beta__control po-beta__control--textarea"
                                :value="customerOfferMessage"
                                rows="14"
                                readonly
                            />
                            <p v-else class="po-beta__hint">
                                Set selling price to generate the disclaimer message.
                            </p>
                            <p v-if="customerOfferMessage" class="po-beta__hint">
                                Paste into Instagram or Facebook DM after you set price and deposit.
                            </p>
                        </aside>
                    </div>

                    <div
                        v-if="isQuoted && !isNew && !showPricingSummary"
                        class="cao-detail__customer-message"
                    >
                        <div class="cao-detail__customer-message-head">
                            <span class="po-beta__label">Customer message</span>
                            <button
                                type="button"
                                class="po-beta__btn po-beta__btn--compact"
                                :disabled="!customerOfferMessage"
                                @click="copyCustomerOfferMessage"
                            >
                                {{ customerMessageCopied ? 'Copied' : 'Copy message' }}
                            </button>
                        </div>
                        <textarea
                            v-if="customerOfferMessage"
                            class="cao-detail__customer-message-body po-beta__control po-beta__control--textarea"
                            :value="customerOfferMessage"
                            rows="12"
                            readonly
                        />
                        <p v-else class="po-beta__hint">
                            Set selling price to generate the disclaimer message.
                        </p>
                        <p v-if="customerOfferMessage" class="po-beta__hint">
                            Paste into Instagram or Facebook DM after you set price and deposit.
                        </p>
                    </div>
                    </template>
                </div>
            </section>

            <section
                v-if="showReconciliationSection"
                class="cao-detail__panel cao-detail__panel--reconciliation"
            >
                <header class="cao-detail__panel-head">
                    <div class="cao-detail__panel-head-row">
                        <h2 class="cao-detail__panel-title">Reconciliation</h2>
                        <span
                            v-if="reconciliationSaveHint"
                            class="cao-detail__save-hint"
                            :class="{ 'is-error': reconciliationSaveState === 'error' }"
                        >
                            {{ reconciliationSaveHint }}
                        </span>
                    </div>
                    <p class="cao-detail__panel-desc">
                        Record what we actually paid and received after the order. Customer price stays locked from the
                        offer above.
                    </p>
                </header>

                <div class="cao-detail__panel-body cao-detail__panel-body--reconciliation">
                    <div class="cao-detail__reconciliation-inputs">
                        <div class="cao-detail__reconciliation-compact">
                            <div class="cao-detail__fields-2">
                                <label class="cao-detail__field">
                                    <span class="po-beta__label">Product cost</span>
                                    <div class="cao-detail__inline cao-detail__inline--money">
                                        <input
                                            v-model="actualProductCostAmount"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            class="po-beta__control po-beta__control--amount"
                                            @blur="onReconciliationBlur"
                                        />
                                        <select
                                            v-model="actualProductCostCurrency"
                                            class="po-beta__control po-beta__control--currency"
                                            @change="onReconciliationBlur"
                                        >
                                            <option
                                                v-for="opt in filterOptions?.currencies ?? []"
                                                :key="opt.value"
                                                :value="opt.value"
                                            >
                                                {{ opt.label }}
                                            </option>
                                        </select>
                                    </div>
                                </label>

                                <label class="cao-detail__field">
                                    <span class="po-beta__label">Shipping cost</span>
                                    <div class="cao-detail__inline cao-detail__inline--money">
                                        <input
                                            v-model="actualShippingCostAmount"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            class="po-beta__control po-beta__control--amount"
                                            @blur="onReconciliationBlur"
                                        />
                                        <select
                                            v-model="actualShippingCostCurrency"
                                            class="po-beta__control po-beta__control--currency"
                                            @change="onReconciliationBlur"
                                        >
                                            <option
                                                v-for="opt in filterOptions?.currencies ?? []"
                                                :key="opt.value"
                                                :value="opt.value"
                                            >
                                                {{ opt.label }}
                                            </option>
                                        </select>
                                    </div>
                                </label>
                            </div>

                            <label class="cao-detail__field cao-detail__field--received-date">
                                <span class="po-beta__label">Received date</span>
                                <input
                                    v-model="actualArrivalAt"
                                    type="date"
                                    class="po-beta__control"
                                    @change="onReconciliationBlur"
                                />
                            </label>

                            <p
                                v-if="order?.actual_landed_cost_cad || order?.actual_fx_rate_date"
                                class="cao-detail__fx-note"
                            >
                                <strong>
                                    Landed {{ formatMoney2OrEmpty(order?.actual_landed_cost_cad) || '—' }} CAD
                                </strong>
                                <template v-if="order?.actual_fx_rate_date">
                                    · FX {{ order.actual_fx_rate_date }}
                                    <template v-if="actualProductFxCadToForeignLabel">
                                        · product {{ actualProductFxCadToForeignLabel }}
                                    </template>
                                    <template
                                        v-if="
                                            actualShippingFxCadToForeignLabel &&
                                            actualShippingFxCadToForeignLabel !== actualProductFxCadToForeignLabel
                                        "
                                    >
                                        · shipping {{ actualShippingFxCadToForeignLabel }}
                                    </template>
                                </template>
                            </p>
                        </div>
                    </div>

                    <div class="cao-detail__pricing-summary cao-detail__pricing-summary--reconciliation">
                        <h3 class="cao-detail__pricing-summary-title">Settlement</h3>
                        <div
                            class="cao-detail__pricing-summary-grid"
                            :class="
                                reconciliationSummaryShowsOriginal
                                    ? 'cao-detail__pricing-summary-grid--with-original'
                                    : 'cao-detail__pricing-summary-grid--cad-only'
                            "
                        >
                            <div class="cao-detail__pricing-summary-head">
                                <span class="cao-detail__pricing-summary-head-cell"></span>
                                <span
                                    v-if="reconciliationSummaryShowsOriginal"
                                    class="cao-detail__pricing-summary-head-cell"
                                >
                                    Original
                                </span>
                                <span class="cao-detail__pricing-summary-head-cell">CAD</span>
                            </div>

                            <template v-for="row in reconciliationSummaryRows" :key="row.key">
                                <div
                                    class="cao-detail__pricing-summary-label"
                                    :class="{
                                        'is-total': row.isTotal,
                                        'is-primary': row.isPrimary,
                                    }"
                                >
                                    {{ row.label }}
                                </div>
                                <div
                                    v-if="reconciliationSummaryShowsOriginal"
                                    class="cao-detail__pricing-summary-value"
                                    :class="{
                                        'is-total': row.isTotal,
                                        'is-primary': row.isPrimary,
                                        'is-empty': row.originalText === null,
                                    }"
                                >
                                    {{ formatReconciliationSummaryCell(row.originalText) }}
                                </div>
                                <div
                                    class="cao-detail__pricing-summary-value"
                                    :class="{
                                        'is-total': row.isTotal,
                                        'is-primary': row.isPrimary,
                                        'is-empty': row.cadText === null,
                                    }"
                                >
                                    {{ formatReconciliationSummaryCell(row.cadText) }}
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="order && !showPricingSummary" class="cao-detail__panel cao-detail__panel--fulfillment">
                <header class="cao-detail__panel-head">
                    <h2 class="cao-detail__panel-title">Fulfillment</h2>
                    <p class="cao-detail__panel-desc">
                        Track deposit, merchandiser order, and product receipt. ETA is calculated from order date plus
                        shipping delay.
                    </p>
                </header>

                <div class="cao-detail__panel-body cao-detail__panel-body--fulfillment">
                    <div class="cao-detail__milestones-row">
                        <article class="cao-detail__milestone-card">
                            <div class="cao-detail__milestone-head">
                                <span class="po-beta__label">Deposit received</span>
                                <span
                                    v-if="order?.deposit_received_at"
                                    class="cao-detail__milestone-done"
                                >
                                    {{ formatTorontoDateTime(order.deposit_received_at) }}
                                </span>
                            </div>
                            <button
                                v-if="!order?.deposit_received_at"
                                type="button"
                                class="po-beta__btn po-beta__btn--compact"
                                :disabled="isNew || !isOfferLocked || markingDepositReceived"
                                @click="markDepositReceived"
                            >
                                {{ markingDepositReceived ? 'Saving…' : 'Mark deposit in' }}
                            </button>
                        </article>

                        <article class="cao-detail__milestone-card">
                            <div class="cao-detail__milestone-head">
                                <span class="po-beta__label">Merchandiser ordered</span>
                                <span
                                    v-if="order?.merchandiser_ordered_at"
                                    class="cao-detail__milestone-done"
                                >
                                    {{ formatTorontoDateTime(order.merchandiser_ordered_at) }}
                                </span>
                            </div>
                            <button
                                v-if="!order?.merchandiser_ordered_at"
                                type="button"
                                class="po-beta__btn po-beta__btn--compact"
                                :disabled="
                                    isNew ||
                                    !isOfferLocked ||
                                    !isDepositReceived ||
                                    markingMerchandiserOrdered
                                "
                                @click="markMerchandiserOrdered"
                            >
                                {{ markingMerchandiserOrdered ? 'Saving…' : 'Mark ordered' }}
                            </button>
                            <div class="cao-detail__milestone-proof">
                                <span class="po-beta__label">Order proof</span>
                                <p class="po-beta__hint">Screenshot from merchandiser.</p>
                                <div
                                    v-if="order?.merchandiser_order_proof_visual"
                                    class="po-beta__visual cao-detail__proof-thumb"
                                >
                                    <img
                                        :src="order.merchandiser_order_proof_visual.url"
                                        :alt="
                                            order.merchandiser_order_proof_visual.filename ??
                                            'Merchandiser order proof'
                                        "
                                    />
                                </div>
                                <div class="cao-detail__upload-row">
                                    <input
                                        ref="orderProofFileInput"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp,image/gif"
                                        class="po-beta__upload-input"
                                        :disabled="isNew || !isOfferLocked || uploadingOrderProof"
                                        @change="onOrderProofFileChange"
                                    />
                                    <button
                                        type="button"
                                        class="po-beta__btn po-beta__btn--compact"
                                        :disabled="isNew || !isOfferLocked || uploadingOrderProof"
                                        @click="pickOrderProofFile"
                                    >
                                        {{
                                            order?.merchandiser_order_proof_visual
                                                ? 'Replace'
                                                : 'Upload'
                                        }}
                                    </button>
                                    <button
                                        v-if="order?.merchandiser_order_proof_visual"
                                        type="button"
                                        class="po-beta__btn po-beta__btn--compact po-beta__btn--ghost"
                                        :disabled="uploadingOrderProof || deletingVisual"
                                        @click="deleteVisual('merchandiser-order-proof')"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>
                        </article>

                        <article class="cao-detail__milestone-card">
                            <div class="cao-detail__milestone-head">
                                <span class="po-beta__label">Product received</span>
                                <span
                                    v-if="order?.product_received_at"
                                    class="cao-detail__milestone-done"
                                >
                                    {{ formatTorontoDateTime(order.product_received_at) }}
                                </span>
                            </div>
                            <button
                                v-if="!order?.product_received_at"
                                type="button"
                                class="po-beta__btn po-beta__btn--compact"
                                :disabled="
                                    isNew ||
                                    !isMerchandiserOrdered ||
                                    markingProductReceived
                                "
                                @click="markProductReceived"
                            >
                                {{ markingProductReceived ? 'Saving…' : 'Mark product received' }}
                            </button>
                            <p
                                v-if="!order?.product_received_at && isMerchandiserOrdered"
                                class="po-beta__hint"
                            >
                                Mark when the product is in hand (distinct from merchandiser arrival date).
                            </p>
                        </article>
                    </div>

                    <div class="cao-detail__fulfillment-foot">
                        <p v-if="order?.estimated_arrival_at" class="cao-detail__fx-note">
                            <strong>ETA {{ formatTorontoDate(order.estimated_arrival_at) }}</strong>
                            <template v-if="order.receive_delay_label">
                                · {{ order.receive_delay_label }} after merchandiser order
                            </template>
                        </p>
                        <p
                            v-else-if="order?.merchandiser_ordered_at && order.receive_delay_label"
                            class="po-beta__hint"
                        >
                            ETA pending — shipping delay is required.
                        </p>
                        <p v-else-if="!isOfferLocked && !isNew && isQuoted" class="po-beta__hint">
                            Lock in the customer offer above to enable fulfillment tracking.
                        </p>
                        <p v-else-if="isOfferLocked && !isDepositReceived" class="po-beta__hint">
                            Mark deposit received before placing the merchandiser order.
                        </p>
                    </div>
                </div>
            </section>
        </div>

        <p v-if="order?.created_at" class="cao-detail__meta">
            Created {{ formatTorontoDateTime(order.created_at) }}
            <span v-if="order.updated_at"> · Updated {{ formatTorontoDateTime(order.updated_at) }}</span>
        </p>

        <ConfirmDialog
            :open="showDeleteConfirm"
            title="Delete custom order?"
            message="This permanently deletes the order and uploaded images."
            confirm-text="Delete"
            variant="danger"
            @confirm="confirmDelete"
            @cancel="showDeleteConfirm = false"
        />

        <ConfirmDialog
            :open="showRejectConfirm"
            title="Reject this order?"
            message="The order stays in the system with all pricing and notes. You can revive it later if the customer returns."
            confirm-text="Reject"
            variant="danger"
            @confirm="confirmReject"
            @cancel="showRejectConfirm = false"
        />

        <ConfirmDialog
            :open="showUnlockOfferConfirm"
            title="Unlock customer offer?"
            message="Customer price, OPV markup, and deposit will become editable again. Fulfillment stays available until you change pricing."
            confirm-text="Unlock offer"
            @confirm="confirmUnlockOffer"
            @cancel="showUnlockOfferConfirm = false"
        />
    </div>
</template>
