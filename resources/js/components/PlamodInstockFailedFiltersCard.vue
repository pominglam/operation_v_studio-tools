<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { plamodInstockFailedFilterKey, type PlamodInstockFailedFilter } from '../lib/plamodRestock';

const props = defineProps<{
    filters: PlamodInstockFailedFilter[];
    disabled: boolean;
    retrying: boolean;
}>();

const emit = defineEmits<{
    retry: [filters: PlamodInstockFailedFilter[]];
}>();

const selected = ref<Record<string, boolean>>({});

watch(
    () => props.filters.map((filter) => plamodInstockFailedFilterKey(filter)).join('|'),
    () => {
        selected.value = Object.fromEntries(
            props.filters.map((filter) => [plamodInstockFailedFilterKey(filter), true]),
        );
    },
    { immediate: true },
);

const selectedFilters = computed((): PlamodInstockFailedFilter[] =>
    props.filters.filter((filter) => selected.value[plamodInstockFailedFilterKey(filter)] === true),
);

function reason(filter: PlamodInstockFailedFilter): string {
    if (filter.error) {
        return filter.error;
    }
    if (filter.expected > 0 && filter.rows === 0) {
        return `0 of ${filter.expected} rows`;
    }

    return 'No rows imported';
}

function retrySelected(): void {
    if (props.disabled || selectedFilters.value.length === 0) {
        return;
    }
    emit('retry', selectedFilters.value);
}
</script>

<template>
    <div
        class="rounded-md border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-950"
        data-testid="restock-failed-filters"
    >
        <p class="font-medium">
            {{ filters.length }} brand filter{{ filters.length === 1 ? '' : 's' }} failed
        </p>
        <p class="mt-1 text-amber-900">
            Uncheck any you do not care about, then retry the rest. Already imported SKUs stay in
            the snapshot.
        </p>
        <ul class="mt-3 space-y-2">
            <li v-for="filter in filters" :key="plamodInstockFailedFilterKey(filter)">
                <label class="flex items-start gap-2">
                    <input
                        type="checkbox"
                        class="mt-0.5"
                        :checked="selected[plamodInstockFailedFilterKey(filter)] === true"
                        :disabled="disabled"
                        :data-testid="`restock-failed-filter-${filter.name}`"
                        @change="
                            selected[plamodInstockFailedFilterKey(filter)] = (
                                $event.target as HTMLInputElement
                            ).checked
                        "
                    />
                    <span>
                        <span class="font-medium">{{ filter.name }}</span>
                        <span class="block text-xs text-amber-800">{{ reason(filter) }}</span>
                    </span>
                </label>
            </li>
        </ul>
        <button
            type="button"
            class="mt-3 rounded-md border border-amber-300 bg-white px-3 py-1.5 text-sm font-medium text-amber-950 hover:bg-amber-100 disabled:opacity-50"
            :disabled="disabled || selectedFilters.length === 0"
            data-testid="restock-retry-failed-filters"
            @click="retrySelected"
        >
            {{
                retrying
                    ? 'Retrying selected filters…'
                    : `Retry selected (${selectedFilters.length})`
            }}
        </button>
    </div>
</template>
