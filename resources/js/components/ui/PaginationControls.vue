<script setup lang="ts">
const props = defineProps<{
  currentPage: number;
  lastPage: number;
  total: number;
  onChange: (page: number) => void;
}>();

function prev(): void {
  if (props.currentPage <= 1) return;
  props.onChange(props.currentPage - 1);
}

function next(): void {
  if (props.currentPage >= props.lastPage) return;
  props.onChange(props.currentPage + 1);
}
</script>

<template>
  <div class="flex flex-col gap-2 border-t border-slate-200 bg-white px-4 py-3 text-sm md:flex-row md:items-center md:justify-between">
    <div class="text-slate-600">
      <span class="font-medium text-slate-900">{{ total }}</span> total
      <span v-if="lastPage > 1"> • Page {{ currentPage }} of {{ lastPage }}</span>
    </div>

    <div class="flex items-center gap-2">
      <button
        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 font-medium text-slate-900 hover:bg-slate-50 disabled:opacity-50"
        type="button"
        :disabled="currentPage <= 1"
        @click="prev"
      >
        Prev
      </button>
      <button
        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 font-medium text-slate-900 hover:bg-slate-50 disabled:opacity-50"
        type="button"
        :disabled="currentPage >= lastPage"
        @click="next"
      >
        Next
      </button>
    </div>
  </div>
</template>


