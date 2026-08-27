<script setup lang="ts">
import { reactive, ref } from 'vue';

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

defineProps<{
    options: Record<CanonicalFilterKey, string[]>;
}>();

const emit = defineEmits<{
    apply: [filters: TaxonomyReviewFilterPayload];
}>();

const search = ref('');
const status = ref('proposed');
const canonical = reactive<Record<CanonicalFilterKey, string>>({
    department: '',
    manufacturer: '',
    franchise: '',
    product_line: '',
    subline: '',
    grade: '',
    series: '',
    scale: '',
    workshop_shelf: '',
    accessory_kind: '',
});
const maximumConfidence = ref('');
const archived = ref<'active' | 'all' | 'archived'>('all');
const differencesOnly = ref(false);
const missingField = ref<CanonicalFilterKey | ''>('');
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

function submit(): void {
    emit('apply', {
        search: search.value.trim(),
        status: status.value,
        canonical: { ...canonical },
        maximumConfidence: maximumConfidence.value,
        archived: archived.value,
        differencesOnly: differencesOnly.value,
        missingField: missingField.value,
    });
}
</script>

<template>
    <form
        class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4"
        @submit.prevent="submit"
    >
        <label class="text-sm font-medium text-slate-700">
            Search SKU or product name
            <input
                data-testid="taxonomy-search"
                v-model="search"
                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                type="search"
            />
        </label>
        <label class="text-sm font-medium text-slate-700">
            Status
            <select
                data-testid="taxonomy-status"
                v-model="status"
                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
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
                v-model="canonical[field.key]"
                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
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
                v-model="maximumConfidence"
                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
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
                v-model="missingField"
                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
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
                v-model="archived"
                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
            >
                <option value="all">Active and archived</option>
                <option value="active">Active only</option>
                <option value="archived">Archived only</option>
            </select>
        </label>
        <label class="flex items-center gap-2 self-end pb-2 text-sm font-medium text-slate-700">
            <input
                data-testid="taxonomy-differences-only"
                v-model="differencesOnly"
                type="checkbox"
                class="size-4 rounded border-slate-300"
            />
            Differences only
        </label>
        <button
            data-testid="taxonomy-filter-submit"
            type="button"
            class="self-end rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50"
            @click="submit"
        >
            Apply filters
        </button>
    </form>
</template>
