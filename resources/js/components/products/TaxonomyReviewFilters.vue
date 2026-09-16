<script setup lang="ts">
import { onBeforeUnmount } from 'vue';

export type CanonicalFilterKey =
    | 'department'
    | 'manufacturer'
    | 'franchise'
    | 'product_line'
    | 'subline'
    | 'grade'
    | 'series'
    | 'scale'
    | 'workshop_shelf'
    | 'accessory_kind';

export type TaxonomyReviewFilterPayload = {
    search: string;
    status: string;
    canonical: Record<CanonicalFilterKey, string>;
    maximumConfidence: string;
    archived: 'active' | 'all' | 'archived';
    differencesOnly: boolean;
    missingField: CanonicalFilterKey | '';
};

const props = defineProps<{
    options: Record<CanonicalFilterKey, string[]>;
    modelValue: TaxonomyReviewFilterPayload;
}>();

const emit = defineEmits<{
    apply: [filters: TaxonomyReviewFilterPayload];
    'update:modelValue': [filters: TaxonomyReviewFilterPayload];
}>();

const canonicalFields: Array<{ key: CanonicalFilterKey; label: string }> = [
    { key: 'department', label: 'Department' },
    { key: 'manufacturer', label: 'Manufacturer' },
    { key: 'franchise', label: 'Franchise' },
    { key: 'product_line', label: 'Product line' },
    { key: 'subline', label: 'Sub-line' },
    { key: 'grade', label: 'Grade' },
    { key: 'series', label: 'Series' },
    { key: 'scale', label: 'Scale' },
    { key: 'workshop_shelf', label: 'T&S shelf' },
    { key: 'accessory_kind', label: 'Accessory kind' },
];

function statusLabel(value: string): string {
    if (value === '') {
        return 'All';
    }
    if (value === 'proposed') {
        return 'Proposed';
    }
    if (value === 'verified') {
        return 'Verified';
    }
    if (value === 'overridden') {
        return 'Overridden';
    }

    return value;
}

function commit(next: TaxonomyReviewFilterPayload): void {
    emit('update:modelValue', next);
    emit('apply', next);
}

function patch(partial: Partial<TaxonomyReviewFilterPayload>): void {
    commit({ ...props.modelValue, ...partial });
}

function patchCanonical(key: CanonicalFilterKey, value: string): void {
    commit({
        ...props.modelValue,
        canonical: { ...props.modelValue.canonical, [key]: value },
    });
}

const SEARCH_DEBOUNCE_MS = 300;

let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null;

function clearSearchDebounce(): void {
    if (searchDebounceTimer !== null) {
        window.clearTimeout(searchDebounceTimer);
        searchDebounceTimer = null;
    }
}

function applySearch(value: string): void {
    commit({
        ...props.modelValue,
        search: value.trim(),
    });
}

function updateSearch(value: string): void {
    emit('update:modelValue', { ...props.modelValue, search: value });
    clearSearchDebounce();
    searchDebounceTimer = window.setTimeout(() => {
        searchDebounceTimer = null;
        applySearch(value);
    }, SEARCH_DEBOUNCE_MS);
}

function submitSearch(): void {
    clearSearchDebounce();
    applySearch(props.modelValue.search);
}

onBeforeUnmount(() => {
    clearSearchDebounce();
});
</script>

<template>
    <form
        class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4"
        @submit.prevent="submitSearch"
    >
        <label class="text-sm font-medium text-slate-700">
            Search SKU or product name
            <input
                data-testid="taxonomy-search"
                :value="modelValue.search"
                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                type="search"
                placeholder="Search SKU or title…"
                @input="updateSearch(($event.target as HTMLInputElement).value)"
            />
        </label>
        <label class="text-sm font-medium text-slate-700">
            Status
            <select
                data-testid="taxonomy-status"
                :value="modelValue.status"
                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
                @change="patch({ status: ($event.target as HTMLSelectElement).value })"
            >
                <option value="">All</option>
                <option value="proposed">Proposed</option>
                <option value="verified">Verified</option>
                <option value="overridden">Overridden</option>
            </select>
        </label>
        <label
            v-for="field in canonicalFields"
            :key="field.key"
            class="text-sm font-medium text-slate-700"
        >
            {{ field.label }}
            <select
                :data-testid="`taxonomy-${field.key.replace('_', '-')}`"
                :value="modelValue.canonical[field.key]"
                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
                @change="
                    patchCanonical(field.key, ($event.target as HTMLSelectElement).value)
                "
            >
                <option value="">All {{ field.label.toLocaleLowerCase() }}</option>
                <option v-for="option in options[field.key]" :key="option" :value="option">
                    {{ option }}
                </option>
            </select>
        </label>
        <label class="text-sm font-medium text-slate-700">
            Maximum confidence
            <select
                data-testid="taxonomy-confidence"
                :value="modelValue.maximumConfidence"
                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
                @change="
                    patch({ maximumConfidence: ($event.target as HTMLSelectElement).value })
                "
            >
                <option value="">Any confidence</option>
                <option value="75">75% or lower</option>
                <option value="60">60% or lower</option>
            </select>
        </label>
        <label class="text-sm font-medium text-slate-700">
            Missing field
            <select
                data-testid="taxonomy-missing-field"
                :value="modelValue.missingField"
                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
                @change="
                    patch({
                        missingField: ($event.target as HTMLSelectElement)
                            .value as CanonicalFilterKey | '',
                    })
                "
            >
                <option value="">Any completeness</option>
                <option
                    v-for="field in canonicalFields"
                    :key="`missing-${field.key}`"
                    :value="field.key"
                >
                    Missing {{ field.label.toLocaleLowerCase() }}
                </option>
            </select>
        </label>
        <label class="text-sm font-medium text-slate-700">
            Archive state
            <select
                data-testid="taxonomy-archived"
                :value="modelValue.archived"
                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
                @change="
                    patch({
                        archived: ($event.target as HTMLSelectElement).value as
                            | 'active'
                            | 'all'
                            | 'archived',
                    })
                "
            >
                <option value="all">Active and archived</option>
                <option value="active">Active only</option>
                <option value="archived">Archived only</option>
            </select>
        </label>
        <label class="flex items-center gap-2 self-end pb-2 text-sm font-medium text-slate-700">
            <input
                data-testid="taxonomy-differences-only"
                :checked="modelValue.differencesOnly"
                type="checkbox"
                class="size-4 rounded border-slate-300"
                @change="
                    patch({
                        differencesOnly: ($event.target as HTMLInputElement).checked,
                    })
                "
            />
            Differences only
        </label>
        <p class="col-span-full text-xs text-slate-500">
            Dropdowns reload immediately. Search applies after you pause typing or press
            <strong>Enter</strong>. Loaded status:
            <strong>{{ statusLabel(modelValue.status) }}</strong
            >.
        </p>
    </form>
</template>
