<script setup lang="ts">
defineProps<{
    label: string;
    active: boolean;
    dir: 'asc' | 'desc';
    testId?: string;
}>();

const emit = defineEmits<{
    (e: 'sort'): void;
}>();
</script>

<template>
    <th class="px-3 py-2">
        <button
            type="button"
            class="inline-flex items-center gap-1 uppercase tracking-wide hover:underline"
            :class="active ? 'font-semibold text-slate-900' : 'font-medium text-slate-600'"
            :data-testid="testId"
            :aria-sort="active ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'"
            @click="emit('sort')"
        >
            {{ label }}
            <span v-if="active" aria-hidden="true">{{ dir === 'asc' ? '▲' : '▼' }}</span>
        </button>
    </th>
</template>
