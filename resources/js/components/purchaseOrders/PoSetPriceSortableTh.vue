<script setup lang="ts">
defineProps<{
    label: string;
    indicator: string;
    headerClass: string;
    testId?: string;
    sortAction: () => void;
}>();
</script>

<template>
    <th class="py-1 pr-2">
        <button
            type="button"
            class="hover:underline"
            :class="headerClass"
            :data-testid="testId"
            @click="sortAction"
        >
            {{ label }}{{ indicator }}
        </button>
    </th>
</template>
