<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import CreatableSelect from '../ui/CreatableSelect.vue';
import type { SeriesResolution, TaxonomyVerification } from '../../types/productTaxonomy';

const props = defineProps<{
    open: boolean;
    item: TaxonomyVerification | null;
    seriesOptions: string[];
    busy: boolean;
}>();

const emit = defineEmits<{
    confirm: [item: TaxonomyVerification, series: string | null, notes: string | null];
    cancel: [];
}>();

const seriesValue = ref('');
const notes = ref('');
const localError = ref<string | null>(null);

type SourceRow = {
    key: string;
    label: string;
    value: string | null;
    url: string | null;
};

const sourceRows = computed((): SourceRow[] => {
    const resolution = props.item?.series_resolution;
    if (resolution === null || resolution === undefined) {
        return [];
    }

    return [
        { key: 'erp', label: 'ERP stored', value: resolution.erp, url: null },
        { key: 'plamod', label: 'Plamod', value: resolution.plamod, url: resolution.plamod_url },
        { key: 'rules', label: 'Title rules', value: resolution.rules, url: null },
        { key: 'wiki', label: 'Wiki (cached)', value: resolution.wiki, url: resolution.wiki_url },
        { key: 'bandai', label: 'Bandai sync', value: resolution.bandai, url: resolution.bandai_url },
    ];
});

const suggestedSeries = computed((): string[] => {
    const resolution = props.item?.series_resolution;
    const values = new Set<string>();
    if (props.item?.product.series) {
        values.add(props.item.product.series);
    }
    for (const row of sourceRows.value) {
        if (row.value && row.value.trim() !== '') {
            values.add(row.value.trim());
        }
    }
    if (resolution?.final_decision) {
        values.add(resolution.final_decision);
    }

    return [...values].sort((a, b) => a.localeCompare(b));
});

const seriesDropdownOptions = computed(() =>
    Array.from(new Set([...suggestedSeries.value, ...props.seriesOptions])).sort((a, b) =>
        a.localeCompare(b),
    ),
);

const confidenceClass = computed(() => {
    const confidence = props.item?.series_resolution?.confidence;
    if (confidence === 'high') return 'bg-emerald-100 text-emerald-800';
    if (confidence === 'medium') return 'bg-amber-100 text-amber-900';
    if (confidence === 'review') return 'bg-red-100 text-red-800';
    return 'bg-slate-100 text-slate-700';
});

watch(
    () => props.open,
    (open) => {
        if (!open || props.item === null) {
            return;
        }
        const resolution = props.item.series_resolution;
        seriesValue.value =
            props.item.product.series ??
            resolution?.final_decision ??
            resolution?.rules ??
            '';
        notes.value = props.item.operator_notes ?? '';
        localError.value = null;
    },
);

function pickSuggestion(value: string | null): void {
    if (value && value.trim() !== '') {
        seriesValue.value = value.trim();
    }
}

function submit(): void {
    if (props.item === null) {
        return;
    }
    const next = seriesValue.value.trim();
    if (next === '') {
        localError.value = 'Choose or enter a series value.';
        return;
    }
    emit('confirm', props.item, next, notes.value.trim() || null);
}
</script>

<template>
    <div
        v-if="open && item"
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
        role="dialog"
        aria-modal="true"
        data-testid="taxonomy-series-decision-dialog"
        @click.self="emit('cancel')"
    >
        <form
            class="flex max-h-[90vh] w-full max-w-xl flex-col overflow-hidden rounded-lg bg-white shadow-xl"
            @submit.prevent="submit"
        >
            <div class="space-y-1 border-b border-slate-200 p-5">
                <h2 class="text-sm font-semibold text-slate-900">Series decision</h2>
                <p class="font-mono text-xs text-slate-600">{{ item.product.sku }}</p>
                <p class="text-sm text-slate-800">{{ item.product.description }}</p>
            </div>

            <div class="space-y-4 overflow-y-auto p-5">
                <div
                    v-if="item.series_resolution"
                    class="flex flex-wrap items-center gap-2 text-xs"
                >
                    <span class="font-semibold text-slate-600">Suggested confidence</span>
                    <span
                        class="rounded-full px-2 py-0.5 font-semibold uppercase tracking-wide"
                        :class="confidenceClass"
                    >
                        {{ item.series_resolution.confidence }}
                    </span>
                    <span
                        v-if="item.series_resolution.final_decision"
                        class="text-slate-600"
                        :title="item.series_resolution.decision_reason ?? undefined"
                    >
                        Final suggestion: {{ item.series_resolution.final_decision }}
                    </span>
                </div>

                <div v-if="sourceRows.length > 0" class="overflow-hidden rounded-lg border border-sky-200">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-sky-50 text-xs font-semibold uppercase tracking-wide text-sky-900">
                            <tr>
                                <th class="px-3 py-2">Source</th>
                                <th class="px-3 py-2">Value</th>
                                <th class="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in sourceRows"
                                :key="row.key"
                                class="border-t border-sky-100"
                            >
                                <td class="px-3 py-2 font-medium text-slate-700">{{ row.label }}</td>
                                <td class="px-3 py-2 text-slate-800">
                                    <a
                                        v-if="row.url"
                                        :href="row.url"
                                        target="_blank"
                                        rel="noreferrer"
                                        class="text-sky-700 hover:underline"
                                    >
                                        {{ row.value ?? '—' }}
                                    </a>
                                    <span v-else>{{ row.value ?? '—' }}</span>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button
                                        v-if="row.value"
                                        type="button"
                                        class="text-xs font-semibold text-sky-700 hover:underline"
                                        @click="pickSuggestion(row.value)"
                                    >
                                        Use
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-slate-500">
                    No multi-source series resolution for this product (non–model-kit or out of scope).
                    You can still set series manually below.
                </p>

                <label class="block text-sm font-medium text-slate-700">
                    Series (ERP)
                    <CreatableSelect
                        v-model="seriesValue"
                        class="mt-1"
                        :options="seriesDropdownOptions"
                        :disabled="busy"
                        placeholder="Select or type series…"
                        select-test-id="taxonomy-series-decision-value"
                    />
                </label>

                <label class="block text-sm font-medium text-slate-700">
                    Notes
                    <textarea
                        v-model="notes"
                        class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                        rows="2"
                        :disabled="busy"
                    />
                </label>

                <p v-if="localError" class="text-sm text-red-700">{{ localError }}</p>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-200 p-5">
                <button
                    type="button"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    :disabled="busy"
                    @click="emit('cancel')"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    data-testid="taxonomy-series-decision-save"
                    class="rounded-lg bg-slate-950 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    :disabled="busy"
                >
                    Save series
                </button>
            </div>
        </form>
    </div>
</template>
