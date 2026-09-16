import type { MultiSelectOption } from '../components/ui/MultiSelectFilter.vue';
import type { PurchaseOrderFilterSource } from './purchaseOrderFilterOption';

export const PRODUCT_FILTER_EMPTY = '__empty__';

export type LabeledFilterOption = {
    value: string;
    label: string;
};

export type ProductFilterOptionsPayload = {
    main_types?: string[];
    types?: string[];
    departments?: string[];
    manufacturers?: string[];
    franchises?: string[];
    product_lines?: string[];
    sublines?: string[];
    vendors?: string[];
    grades?: string[];
    scales?: string[];
    series?: string[];
    empty_fields?: string[];
    purchase_orders?: PurchaseOrderFilterSource[];
    missing_info?: LabeledFilterOption[];
    product_flags?: LabeledFilterOption[];
    shipment_methods?: LabeledFilterOption[];
    ready?: LabeledFilterOption[];
    archived?: LabeledFilterOption[];
    store_preorder?: LabeledFilterOption[];
    published?: LabeledFilterOption[];
    po_novelty?: LabeledFilterOption[];
};

export function toMultiSelectOptions(values: string[]): MultiSelectOption[] {
    return values
        .map((value) => value.trim())
        .filter(Boolean)
        .map((value) => ({ value, label: value }));
}

export function withEmptyOption(
    options: MultiSelectOption[],
    includeEmpty: boolean,
): MultiSelectOption[] {
    if (!includeEmpty) {
        return options;
    }

    return [{ value: PRODUCT_FILTER_EMPTY, label: '(empty)' }, ...options];
}

export function keepValidSelections(selected: string[], options: MultiSelectOption[]): string[] {
    const allowed = new Set(options.map((option) => option.value));

    return selected.filter((value) => allowed.has(value));
}

export function catalogValues(options: MultiSelectOption[]): string[] {
    return options.map((option) => option.value).filter((value) => value !== PRODUCT_FILTER_EMPTY);
}

export function hasEmptyField(emptyFields: string[], field: string): boolean {
    return emptyFields.includes(field);
}
