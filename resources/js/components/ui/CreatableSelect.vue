<script setup lang="ts">
import { computed, ref, watch } from 'vue';

const ADD_NEW = '__add_new__';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        options: string[];
        disabled?: boolean;
        placeholder?: string;
        selectTestId?: string;
        inputTestId?: string;
    }>(),
    {
        disabled: false,
        placeholder: 'Select or add new…',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const selectValue = ref('');
const customValue = ref('');
const useCustom = ref(false);

const sortedOptions = computed(() =>
    Array.from(
        new Set(
            props.options
                .map((option) => option.trim())
                .filter((option) => option !== ''),
        ),
    ).sort((a, b) => a.localeCompare(b)),
);

function syncFromModel(value: string): void {
    const trimmed = value.trim();
    if (trimmed === '') {
        selectValue.value = '';
        customValue.value = '';
        useCustom.value = false;
        return;
    }
    if (sortedOptions.value.includes(trimmed)) {
        selectValue.value = trimmed;
        customValue.value = '';
        useCustom.value = false;
        return;
    }
    selectValue.value = ADD_NEW;
    customValue.value = trimmed;
    useCustom.value = true;
}

watch(
    () => props.modelValue,
    (value) => {
        syncFromModel(value);
    },
    { immediate: true },
);

watch(customValue, (value) => {
    if (!useCustom.value || props.disabled) {
        return;
    }
    emit('update:modelValue', value.trim());
});

function onSelectChange(): void {
    if (selectValue.value === ADD_NEW) {
        useCustom.value = true;
        customValue.value = props.modelValue.trim();
        emit('update:modelValue', customValue.value);
        return;
    }
    useCustom.value = false;
    customValue.value = '';
    emit('update:modelValue', selectValue.value);
}

function useExistingOptions(): void {
    useCustom.value = false;
    selectValue.value = '';
    customValue.value = '';
    emit('update:modelValue', '');
}
</script>

<template>
    <div class="space-y-1">
        <select
            v-if="!useCustom"
            v-model="selectValue"
            class="w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm disabled:bg-slate-50"
            :disabled="disabled"
            :data-testid="selectTestId"
            @change="onSelectChange"
        >
            <option value="">{{ placeholder }}</option>
            <option v-for="option in sortedOptions" :key="option" :value="option">
                {{ option }}
            </option>
            <option :value="ADD_NEW">+ Add new…</option>
        </select>
        <div v-else class="flex gap-1">
            <input
                v-model="customValue"
                class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1.5 text-sm disabled:bg-slate-50"
                type="text"
                :disabled="disabled"
                :placeholder="placeholder"
                :data-testid="inputTestId"
            />
            <button
                type="button"
                class="shrink-0 rounded-md border border-slate-300 px-2 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                :disabled="disabled"
                @click="useExistingOptions"
            >
                List
            </button>
        </div>
    </div>
</template>
