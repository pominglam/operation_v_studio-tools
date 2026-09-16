<script setup lang="ts">
import { computed, ref } from 'vue';
import { extractApiError } from '../../lib/api';
import {
    applySpecialOrderListStatusAction,
    specialOrderListStatusActions,
    type SpecialOrderListStatusActionId,
} from '../../lib/specialOrderListStatusActions';
import {
    specialOrderWorkflowStatusLabel,
    specialOrderWorkflowStatusTailwindClass,
    resolveSpecialOrderWorkflowStatus,
} from '../../lib/specialOrderWorkflow';
import type { SpecialOrder } from '../../types/specialOrders';

const props = defineProps<{
    order: SpecialOrder;
}>();

const emit = defineEmits<{
    updated: [order: SpecialOrder];
    error: [message: string];
}>();

const busy = ref(false);
const selectKey = ref(0);

const currentStatus = computed(() => resolveSpecialOrderWorkflowStatus(props.order));

const currentLabel = computed(() => specialOrderWorkflowStatusLabel(currentStatus.value));

const badgeClass = computed(() => specialOrderWorkflowStatusTailwindClass(currentStatus.value));

const actions = computed(() => specialOrderListStatusActions(props.order));

const isInteractive = computed(() => actions.value.length > 0 && !busy.value);

async function onPick(event: Event): Promise<void> {
    const select = event.target as HTMLSelectElement;
    const actionId = select.value as SpecialOrderListStatusActionId | '';
    selectKey.value += 1;

    if (!actionId) return;

    const action = actions.value.find((a) => a.id === actionId);
    if (!action) return;

    if (action.confirmMessage && !window.confirm(action.confirmMessage)) {
        return;
    }

    busy.value = true;
    try {
        const updated = await applySpecialOrderListStatusAction(props.order.id, actionId);
        emit('updated', updated);
    } catch (err) {
        emit('error', extractApiError(err));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="relative inline-block max-w-full whitespace-nowrap">
        <span
            v-if="!isInteractive"
            class="inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium"
            :class="badgeClass"
        >
            {{ busy ? 'Saving…' : currentLabel }}
        </span>
        <select
            v-else
            :key="selectKey"
            class="inline-block max-w-full cursor-pointer appearance-none rounded-full py-0.5 pl-2 pr-5 text-xs font-medium ring-2 ring-slate-900/10 transition-opacity hover:opacity-90"
            :class="badgeClass"
            :aria-label="`Status: ${currentLabel}. Choose a new status.`"
            :disabled="busy"
            @change="onPick"
        >
            <option value="" selected>{{ currentLabel }}</option>
            <option v-for="action in actions" :key="action.id" :value="action.id">
                → {{ action.label }}
            </option>
        </select>
        <span
            v-if="isInteractive"
            class="pointer-events-none absolute right-1.5 top-1/2 -translate-y-1/2 text-[9px] opacity-70"
            aria-hidden="true"
        >
            ▾
        </span>
    </div>
</template>
