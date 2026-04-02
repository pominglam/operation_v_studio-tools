<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

export type MultiSelectOption = {
    value: string;
    label: string;
    muted?: boolean;
};

const props = defineProps<{
    label: string;
    options: MultiSelectOption[];
    modelValue: string[];
    placeholder?: string;
    testId?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string[]): void;
}>();

const open = ref(false);
const rootEl = ref<HTMLElement | null>(null);
const selectAllEl = ref<HTMLInputElement | null>(null);
const searchEl = ref<HTMLInputElement | null>(null);
const searchQuery = ref('');

const selectedSet = computed<Set<string>>(() => new Set(props.modelValue));
const allOptionValues = computed<string[]>(() => props.options.map((o) => o.value));
const allSelected = computed<boolean>(() => props.options.length > 0 && props.modelValue.length === props.options.length);
const someSelected = computed<boolean>(() => props.modelValue.length > 0 && props.modelValue.length < props.options.length);
const filteredOptions = computed<MultiSelectOption[]>(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (q === '') return props.options;
    return props.options.filter((o) => o.label.toLowerCase().includes(q));
});
const filteredOptionValues = computed<string[]>(() => filteredOptions.value.map((o) => o.value));
const filteredAllSelected = computed<boolean>(() => {
    if (filteredOptionValues.value.length === 0) return false;
    const s = selectedSet.value;
    return filteredOptionValues.value.every((v) => s.has(v));
});
const filteredSomeSelected = computed<boolean>(() => {
    if (filteredOptionValues.value.length === 0) return false;
    const s = selectedSet.value;
    const selectedCount = filteredOptionValues.value.filter((v) => s.has(v)).length;
    return selectedCount > 0 && selectedCount < filteredOptionValues.value.length;
});
const selectedLabel = computed<string>(() => {
    if (props.modelValue.length === 0) return props.placeholder ?? 'All';
    if (props.modelValue.length === 1) {
        const opt = props.options.find((o) => o.value === props.modelValue[0]);
        return opt?.label ?? props.modelValue[0];
    }
    return `${props.modelValue.length} selected`;
});

function toggle(value: string): void {
    const next = new Set(props.modelValue);
    if (next.has(value)) next.delete(value);
    else next.add(value);
    emit('update:modelValue', Array.from(next));
}

function clear(): void {
    emit('update:modelValue', []);
}

function selectAll(): void {
    // When searching, select all matches; otherwise select all options.
    const values = searchQuery.value.trim() !== '' ? filteredOptionValues.value : allOptionValues.value;
    const next = new Set(props.modelValue);
    for (const v of values) next.add(v);
    emit('update:modelValue', Array.from(next));
}

function close(): void {
    open.value = false;
}

function onDocumentPointerDown(e: PointerEvent): void {
    if (!open.value) return;
    const target = e.target as Node | null;
    if (!target) return;
    if (rootEl.value && rootEl.value.contains(target)) return;
    close();
}

function onDocumentKeyDown(e: KeyboardEvent): void {
    if (!open.value) return;
    if (e.key === 'Escape') {
        e.preventDefault();
        close();
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', onDocumentPointerDown, { capture: true });
    document.addEventListener('keydown', onDocumentKeyDown);
});

// Keep the select-all checkbox in an indeterminate state when partially selected
watch([open, someSelected, filteredSomeSelected], () => {
    if (!open.value) return;
    if (selectAllEl.value) {
        selectAllEl.value.indeterminate = searchQuery.value.trim() !== '' ? filteredSomeSelected.value : someSelected.value;
    }
});

watch(open, async (v) => {
    if (!v) {
        searchQuery.value = '';
        return;
    }
    searchQuery.value = '';
    await nextTick();
    searchEl.value?.focus();
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointerDown, {
        capture: true,
    } as AddEventListenerOptions);
    document.removeEventListener('keydown', onDocumentKeyDown);
});
</script>

<template>
    <div ref="rootEl" class="relative" :data-testid="props.testId">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-600">{{ label }}</div>
        <button
            class="mt-1 inline-flex w-full items-center justify-between gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 hover:bg-slate-50"
            type="button"
            @click="open = !open"
            :data-testid="props.testId ? `${props.testId}-button` : undefined"
        >
            <span class="truncate">{{ selectedLabel }}</span>
            <span class="text-slate-500">▾</span>
        </button>

        <div
            v-if="open"
            class="absolute z-20 mt-2 w-[28rem] max-w-[calc(100vw-2rem)] rounded-md border border-slate-200 bg-white p-2 shadow-lg"
            :data-testid="props.testId ? `${props.testId}-panel` : undefined"
        >
            <div class="flex items-center justify-between gap-2 px-1 pb-2">
                <div class="text-sm font-medium text-slate-900">{{ label }}</div>
                <button
                    class="text-xs font-medium text-slate-700 underline hover:text-slate-900"
                    type="button"
                    @click="clear"
                >
                    Clear
                </button>
            </div>

            <div class="px-1 pb-2">
                <input
                    ref="searchEl"
                    v-model="searchQuery"
                    class="w-full rounded-md border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-900 placeholder:text-slate-400"
                    type="text"
                    placeholder="Search…"
                    :data-testid="props.testId ? `${props.testId}-search` : undefined"
                />
            </div>

            <div class="max-h-64 overflow-auto">
                <label
                    v-if="options.length > 0"
                    class="flex cursor-pointer items-center justify-between gap-2 rounded px-2 py-1 text-sm hover:bg-slate-50"
                >
                    <div class="flex items-center gap-2">
                        <input
                            class="h-4 w-4 rounded border-slate-300"
                            type="checkbox"
                            :checked="searchQuery.trim() !== '' ? filteredAllSelected : allSelected"
                            :aria-checked="
                                searchQuery.trim() !== ''
                                    ? (filteredSomeSelected ? 'mixed' : filteredAllSelected)
                                    : (someSelected ? 'mixed' : allSelected)
                            "
                            @click.stop
                            @change="allSelected ? clear() : selectAll()"
                            ref="selectAllEl"
                        />
                        <span class="font-medium text-slate-900">Select all</span>
                    </div>
                    <span class="text-xs text-slate-500">
                        {{
                            searchQuery.trim() !== ''
                                ? `${filteredOptionValues.filter((v) => selectedSet.has(v)).length}/${filteredOptions.length}`
                                : `${modelValue.length}/${options.length}`
                        }}
                    </span>
                </label>
                <div v-if="options.length > 0" class="my-1 border-t border-slate-100"></div>

                <label
                    v-for="o in filteredOptions"
                    :key="o.value"
                    class="flex min-w-0 cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm hover:bg-slate-50"
                >
                    <input
                        class="h-4 w-4 rounded border-slate-300"
                        type="checkbox"
                        :checked="selectedSet.has(o.value)"
                        @change="toggle(o.value)"
                    />
                    <span
                        class="min-w-0 truncate"
                        :class="o.muted ? 'text-slate-400' : 'text-slate-800'"
                    >
                        {{ o.label }}
                    </span>
                </label>
                <div
                    v-if="options.length === 0"
                    class="px-2 py-2 text-sm text-slate-600"
                >
                    No options.
                </div>
                <div
                    v-else-if="filteredOptions.length === 0"
                    class="px-2 py-2 text-sm text-slate-600"
                >
                    No matches.
                </div>
            </div>
        </div>
    </div>
</template>
