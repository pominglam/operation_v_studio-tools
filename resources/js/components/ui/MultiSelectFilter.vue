<script setup lang="ts">
import { computed, ref } from 'vue';

export type MultiSelectOption = {
  value: string;
  label: string;
};

const props = defineProps<{
  label: string;
  options: MultiSelectOption[];
  modelValue: string[];
  placeholder?: string;
}>();

const emit = defineEmits<{
  (e: 'update:modelValue', value: string[]): void;
}>();

const open = ref(false);

const selectedSet = computed<Set<string>>(() => new Set(props.modelValue));
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
</script>

<template>
  <div class="relative">
    <div class="text-xs font-semibold uppercase tracking-wide text-slate-600">{{ label }}</div>
    <button
      class="mt-1 inline-flex w-full items-center justify-between gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 hover:bg-slate-50"
      type="button"
      @click="open = !open"
    >
      <span class="truncate">{{ selectedLabel }}</span>
      <span class="text-slate-500">▾</span>
    </button>

    <div v-if="open" class="absolute z-20 mt-2 w-64 rounded-md border border-slate-200 bg-white p-2 shadow-lg">
      <div class="flex items-center justify-between gap-2 px-1 pb-2">
        <div class="text-sm font-medium text-slate-900">{{ label }}</div>
        <button class="text-xs font-medium text-slate-700 underline hover:text-slate-900" type="button" @click="clear">
          Clear
        </button>
      </div>

      <div class="max-h-64 overflow-auto">
        <label v-for="o in options" :key="o.value" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm hover:bg-slate-50">
          <input
            class="h-4 w-4 rounded border-slate-300"
            type="checkbox"
            :checked="selectedSet.has(o.value)"
            @change="toggle(o.value)"
          />
          <span class="text-slate-800">{{ o.label }}</span>
        </label>
        <div v-if="options.length === 0" class="px-2 py-2 text-sm text-slate-600">No options.</div>
      </div>

      <div class="pt-2">
        <button
          class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50"
          type="button"
          @click="open = false"
        >
          Done
        </button>
      </div>
    </div>
  </div>
</template>


