<script setup lang="ts">
import type { PoBetaAction } from '../../lib/purchaseOrderBeta';

defineProps<{
    query: string;
    groups: Array<{ name: PoBetaAction['group']; actions: PoBetaAction[] }>;
}>();

const emit = defineEmits<{
    (e: 'update:query', value: string): void;
    (e: 'run', action: PoBetaAction): void;
}>();
</script>

<template>
    <div class="po-beta__menu" data-testid="po-beta-actions-menu">
        <div class="po-beta__menu-head">
            <strong>Purchase order actions</strong>
            <input
                class="po-beta__menu-search"
                type="search"
                placeholder="Find an action…"
                data-testid="po-beta-action-search"
                :value="query"
                @input="emit('update:query', ($event.target as HTMLInputElement).value)"
            />
        </div>
        <div class="po-beta__menu-grid">
            <div v-for="group in groups" :key="group.name">
                <p class="po-beta__label">{{ group.name }}</p>
                <button
                    v-for="action in group.actions"
                    :key="action.id"
                    type="button"
                    @click="emit('run', action)"
                >
                    <strong>{{ action.label }}</strong>
                    <span class="po-beta__quiet">{{
                        action.implemented
                            ? action.description
                            : `${action.description} · opens classic UI`
                    }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
