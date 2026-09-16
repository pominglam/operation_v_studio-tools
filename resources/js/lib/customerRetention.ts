import { formatStaffOrdersRevenue } from './staffOrdersReport';
import type {
    CustomerCadenceStatus,
    CustomerChurnStatus,
    CustomerFrequencyLabel,
    CustomerStoreCadence,
    CustomerRetentionIndexResponse,
    CustomerRetentionTab,
    CustomerRetentionOrder,
    CustomerRetentionPerson,
    CustomerRetentionMonthRow,
    CustomerRetentionRfmCatalogRow,
    CustomerRetentionShowResponse,
    CustomerRetentionSortKey,
    CustomerRetentionSummary,
} from '../types/customerRetention';

const FREQUENCY_LABELS: CustomerFrequencyLabel[] = ['new', 'repeat', 'loyal'];
const CHURN_STATUSES: CustomerChurnStatus[] = ['active', 'churned'];
const CADENCE_STATUSES: CustomerCadenceStatus[] = ['on_cadence', 'due', 'lapsed'];
const TABS: CustomerRetentionTab[] = ['months', 'people'];
const SORT_KEYS: CustomerRetentionSortKey[] = [
    'display_name',
    'frequency_label',
    'rfm_group',
    'order_count',
    'spend',
    'aov',
    'cadence_status',
    'churn_status',
    'last_order_at',
    'recency_score',
    'frequency_score',
    'monetary_score',
];

export function isCustomerFrequencyLabel(value: string): value is CustomerFrequencyLabel {
    return FREQUENCY_LABELS.includes(value as CustomerFrequencyLabel);
}

export function isCustomerRetentionSortKey(value: string): value is CustomerRetentionSortKey {
    return SORT_KEYS.includes(value as CustomerRetentionSortKey);
}

export function isCustomerChurnStatus(value: string): value is CustomerChurnStatus {
    return CHURN_STATUSES.includes(value as CustomerChurnStatus);
}

export function isCustomerCadenceStatus(value: string): value is CustomerCadenceStatus {
    return CADENCE_STATUSES.includes(value as CustomerCadenceStatus);
}

export function isCustomerRetentionTab(value: string): value is CustomerRetentionTab {
    return TABS.includes(value as CustomerRetentionTab);
}

export function frequencyLabelText(label: CustomerFrequencyLabel): string {
    if (label === 'new') return 'New';
    if (label === 'repeat') return 'Repeat';
    return 'Loyal';
}

export function formatRetentionMoney(amount: string | null, currency = 'CAD'): string {
    if (amount === null || amount === '') return '—';
    return formatStaffOrdersRevenue(amount, currency);
}

export function currentRetentionMonthKey(now = new Date()): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/Montreal',
        year: 'numeric',
        month: '2-digit',
    }).formatToParts(now);
    const year = parts.find((part) => part.type === 'year')?.value ?? '';
    const month = parts.find((part) => part.type === 'month')?.value ?? '';

    return `${year}-${month}`;
}

export function formatRetentionMonthLabel(month: string): string {
    const [yearText, monthText] = month.split('-');
    const date = new Date(Number(yearText), Number(monthText) - 1, 1);

    return date.toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
}

export function formatRepeatRate(rate: string): string {
    const value = Number(rate);
    if (!Number.isFinite(value)) return '—';
    return `${(value * 100).toFixed(1)}%`;
}

export function parseCustomerRetentionIndexResponse(raw: unknown): CustomerRetentionIndexResponse {
    if (
        !isRecord(raw) ||
        !Array.isArray(raw.data) ||
        !isRecord(raw.summary) ||
        !isRecord(raw.meta)
    ) {
        throw new Error('Customer retention report: unexpected response.');
    }

    return {
        data: raw.data.map(parsePerson),
        summary: parseSummary(raw.summary),
        meta: {
            current_page: numberOr(raw.meta.current_page, 1),
            last_page: numberOr(raw.meta.last_page, 1),
            per_page: numberOr(raw.meta.per_page, 50),
            total: numberOr(raw.meta.total, 0),
        },
    };
}

export function parseCustomerRetentionShowResponse(raw: unknown): CustomerRetentionShowResponse {
    if (!isRecord(raw) || !isRecord(raw.data) || !Array.isArray(raw.orders)) {
        throw new Error('Customer retention person: unexpected response.');
    }

    return {
        data: parsePerson(raw.data),
        orders: raw.orders.map(parseOrder),
    };
}

function parsePerson(raw: unknown): CustomerRetentionPerson {
    if (!isRecord(raw) || typeof raw.id !== 'string') {
        throw new Error('Customer retention report: person missing id.');
    }
    const frequency = String(raw.frequency_label);
    if (!isCustomerFrequencyLabel(frequency)) {
        throw new Error('Customer retention report: bad frequency label.');
    }

    return {
        id: raw.id,
        display_name: stringOr(raw.display_name, 'Identified customer'),
        frequency_label: frequency,
        is_repeat: Boolean(raw.is_repeat),
        rfm_group: stringOr(raw.rfm_group, ''),
        rfm_group_name: stringOr(raw.rfm_group_name, stringOr(raw.rfm_group, '')),
        recency_score: numberOr(raw.recency_score, 1),
        frequency_score: numberOr(raw.frequency_score, 1),
        monetary_score: numberOr(raw.monetary_score, 1),
        fm_score: numberOr(raw.fm_score, 1),
        order_count: numberOr(raw.order_count, 0),
        spend: stringOr(raw.spend, '0.00'),
        aov: stringOr(raw.aov, '0.00'),
        cadence: parseCadence(raw.cadence),
        churn: parseChurn(raw.churn),
        last_order_at: typeof raw.last_order_at === 'string' ? raw.last_order_at : null,
        shopify_admin_url: typeof raw.shopify_admin_url === 'string' ? raw.shopify_admin_url : null,
    };
}

function parseOrder(raw: unknown): CustomerRetentionOrder {
    if (!isRecord(raw) || typeof raw.id !== 'number') {
        throw new Error('Customer retention person: order missing id.');
    }

    return {
        id: raw.id,
        name: typeof raw.name === 'string' ? raw.name : null,
        ordered_at: typeof raw.ordered_at === 'string' ? raw.ordered_at : null,
        subtotal: typeof raw.subtotal === 'string' ? raw.subtotal : null,
        channel_label: typeof raw.channel_label === 'string' ? raw.channel_label : null,
        shopify_admin_url: typeof raw.shopify_admin_url === 'string' ? raw.shopify_admin_url : null,
    };
}

function parseSummary(raw: Record<string, unknown>): CustomerRetentionSummary {
    const catalog = Array.isArray(raw.rfm_catalog) ? raw.rfm_catalog.map(parseCatalogRow) : [];
    const countsRaw = isRecord(raw.rfm_counts) ? raw.rfm_counts : {};
    const rfmCounts: Record<string, number> = {};
    for (const [key, value] of Object.entries(countsRaw)) {
        rfmCounts[key] = numberOr(value, 0);
    }

    return {
        eligible_orders: numberOr(raw.eligible_orders, 0),
        identified_orders: numberOr(raw.identified_orders, 0),
        unidentified_orders: numberOr(raw.unidentified_orders, 0),
        identified_spend: stringOr(raw.identified_spend, '0.00'),
        unidentified_spend: stringOr(raw.unidentified_spend, '0.00'),
        people: numberOr(raw.people, 0),
        filtered_people: numberOr(raw.filtered_people, 0),
        new_count: numberOr(raw.new_count, 0),
        repeat_count: numberOr(raw.repeat_count, 0),
        loyal_count: numberOr(raw.loyal_count, 0),
        repeat_rate: stringOr(raw.repeat_rate, '0.0000'),
        churned_count: numberOr(raw.churned_count, 0),
        identified_aov: stringOr(raw.identified_aov, '0.00'),
        store_cadence: parseStoreCadence(raw.store_cadence),
        cadence_on_count: numberOr(raw.cadence_on_count, 0),
        cadence_due_count: numberOr(raw.cadence_due_count, 0),
        cadence_lapsed_count: numberOr(raw.cadence_lapsed_count, 0),
        rfm_counts: rfmCounts,
        rfm_catalog: catalog,
        months: Array.isArray(raw.months) ? raw.months.map(parseMonthRow) : [],
        revenue_currency: stringOr(raw.revenue_currency, 'CAD'),
        scoring_note: stringOr(raw.scoring_note, ''),
    };
}

function parseMonthRow(raw: unknown): CustomerRetentionMonthRow {
    if (!isRecord(raw) || typeof raw.month !== 'string') {
        throw new Error('Customer retention report: bad month row.');
    }

    return {
        month: raw.month,
        acquired: numberOr(raw.acquired, 0),
        returning_buyers: numberOr(raw.returning_buyers, 0),
        buyers: numberOr(raw.buyers, 0),
        buyer_return_rate: stringOr(raw.buyer_return_rate, '0.0000'),
        orders: numberOr(raw.orders, 0),
        spend: stringOr(raw.spend, '0.00'),
        acquired_spend: stringOr(raw.acquired_spend, '0.00'),
        returning_spend: stringOr(raw.returning_spend, '0.00'),
        aov: stringOr(raw.aov, '0.00'),
        acquired_now_repeat: numberOr(raw.acquired_now_repeat, 0),
    };
}

function parseStoreCadence(raw: unknown): CustomerStoreCadence {
    const row = isRecord(raw) ? raw : {};

    return {
        median_gap_days: numberOr(row.median_gap_days, 30),
        due_after_days: numberOr(row.due_after_days, 30),
        lapsed_after_days: numberOr(row.lapsed_after_days, 60),
        typical_gap_count: numberOr(row.typical_gap_count, 0),
        gap_floor_days: numberOr(row.gap_floor_days, 14),
    };
}

function parseCadence(raw: unknown): CustomerRetentionPerson['cadence'] {
    if (!isRecord(raw)) return null;
    const status = String(raw.status);
    if (!isCustomerCadenceStatus(status)) return null;

    return {
        status,
        days_since_last: numberOr(raw.days_since_last, 0),
        due_after_days: numberOr(raw.due_after_days, 0),
        lapsed_after_days: numberOr(raw.lapsed_after_days, 0),
    };
}

function parseChurn(raw: unknown): CustomerRetentionPerson['churn'] {
    if (!isRecord(raw)) return null;
    const status = String(raw.status);
    if (!isCustomerChurnStatus(status)) return null;

    return {
        status,
        avg_gap_days: numberOr(raw.avg_gap_days, 0),
        days_since_last: numberOr(raw.days_since_last, 0),
        threshold_days: numberOr(raw.threshold_days, 0),
    };
}

function parseCatalogRow(raw: unknown): CustomerRetentionRfmCatalogRow {
    if (!isRecord(raw)) {
        throw new Error('Customer retention report: bad RFM catalog row.');
    }

    return {
        key: stringOr(raw.key, ''),
        name: stringOr(raw.name, ''),
        rule: stringOr(raw.rule, ''),
        who: stringOr(raw.who, ''),
    };
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

function stringOr(value: unknown, fallback: string): string {
    return typeof value === 'string' && value !== '' ? value : fallback;
}

function numberOr(value: unknown, fallback: number): number {
    return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
}
