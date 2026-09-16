export type CustomerFrequencyLabel = 'new' | 'repeat' | 'loyal';

export type CustomerRetentionSortKey =
    | 'display_name'
    | 'frequency_label'
    | 'rfm_group'
    | 'order_count'
    | 'spend'
    | 'aov'
    | 'cadence_status'
    | 'churn_status'
    | 'last_order_at'
    | 'recency_score'
    | 'frequency_score'
    | 'monetary_score';

export type CustomerChurnStatus = 'active' | 'churned';

export type CustomerCadenceStatus = 'on_cadence' | 'due' | 'lapsed';

export type CustomerStoreCadence = {
    median_gap_days: number;
    due_after_days: number;
    lapsed_after_days: number;
    typical_gap_count: number;
    gap_floor_days: number;
};

export type CustomerRetentionTab = 'months' | 'people';

export type CustomerRetentionRfmCatalogRow = {
    key: string;
    name: string;
    rule: string;
    who: string;
};

export type CustomerRetentionMonthRow = {
    month: string;
    acquired: number;
    returning_buyers: number;
    buyers: number;
    buyer_return_rate: string;
    orders: number;
    spend: string;
    acquired_spend: string;
    returning_spend: string;
    aov: string;
    acquired_now_repeat: number;
};

export type CustomerRetentionPerson = {
    id: string;
    display_name: string;
    frequency_label: CustomerFrequencyLabel;
    is_repeat: boolean;
    rfm_group: string;
    rfm_group_name: string;
    recency_score: number;
    frequency_score: number;
    monetary_score: number;
    fm_score: number;
    order_count: number;
    spend: string;
    aov: string;
    cadence: {
        status: CustomerCadenceStatus;
        days_since_last: number;
        due_after_days: number;
        lapsed_after_days: number;
    } | null;
    churn: {
        status: CustomerChurnStatus;
        avg_gap_days: number;
        days_since_last: number;
        threshold_days: number;
    } | null;
    last_order_at: string | null;
    shopify_admin_url: string | null;
};

export type CustomerRetentionOrder = {
    id: number;
    name: string | null;
    ordered_at: string | null;
    subtotal: string | null;
    channel_label: string | null;
    shopify_admin_url: string | null;
};

export type CustomerRetentionSummary = {
    eligible_orders: number;
    identified_orders: number;
    unidentified_orders: number;
    identified_spend: string;
    unidentified_spend: string;
    people: number;
    filtered_people: number;
    new_count: number;
    repeat_count: number;
    loyal_count: number;
    repeat_rate: string;
    churned_count: number;
    identified_aov: string;
    store_cadence: CustomerStoreCadence;
    cadence_on_count: number;
    cadence_due_count: number;
    cadence_lapsed_count: number;
    rfm_counts: Record<string, number>;
    rfm_catalog: CustomerRetentionRfmCatalogRow[];
    months: CustomerRetentionMonthRow[];
    revenue_currency: string;
    scoring_note: string;
};

export type CustomerRetentionIndexResponse = {
    data: CustomerRetentionPerson[];
    summary: CustomerRetentionSummary;
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

export type CustomerRetentionShowResponse = {
    data: CustomerRetentionPerson;
    orders: CustomerRetentionOrder[];
};
