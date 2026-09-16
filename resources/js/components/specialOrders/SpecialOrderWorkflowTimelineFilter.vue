<script setup lang="ts">
import { computed } from 'vue';
import {
    SPECIAL_ORDER_WORKFLOW_PIPELINE,
    SPECIAL_ORDER_WORKFLOW_PROCESSING,
    SPECIAL_ORDER_WORKFLOW_QUOTING,
    SPECIAL_ORDER_WORKFLOW_REJECTED,
    SPECIAL_ORDER_WORKFLOW_TIMELINE,
    specialOrderWorkflowStatusLabel,
    specialOrderWorkflowStatusSetsEqual,
    specialOrderWorkflowStatusTailwindClass,
    specialOrderWorkflowTimelineShortLabel,
    type SpecialOrderWorkflowStatus,
} from '../../lib/specialOrderWorkflow';

import type { SpecialOrderWorkflowStatusCounts } from '../../types/specialOrders';

const props = defineProps<{
    modelValue: SpecialOrderWorkflowStatus[];
    statusCounts?: SpecialOrderWorkflowStatusCounts | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: SpecialOrderWorkflowStatus[]];
}>();

const totalCount = computed(() => {
    if (!props.statusCounts) return 0;

    return Object.values(props.statusCounts).reduce((sum, n) => sum + n, 0);
});

const allSelected = computed(() =>
    specialOrderWorkflowStatusSetsEqual(props.modelValue, SPECIAL_ORDER_WORKFLOW_TIMELINE),
);

const quotingSelected = computed(() =>
    specialOrderWorkflowStatusSetsEqual(props.modelValue, SPECIAL_ORDER_WORKFLOW_QUOTING),
);

const processingSelected = computed(() =>
    specialOrderWorkflowStatusSetsEqual(
        props.modelValue,
        SPECIAL_ORDER_WORKFLOW_PROCESSING,
    ),
);

const rejectedSelected = computed(() =>
    specialOrderWorkflowStatusSetsEqual(
        props.modelValue,
        SPECIAL_ORDER_WORKFLOW_REJECTED,
    ),
);

function isVisible(status: SpecialOrderWorkflowStatus): boolean {
    return props.modelValue.includes(status);
}

function selectAll(): void {
    emit('update:modelValue', [...SPECIAL_ORDER_WORKFLOW_TIMELINE]);
}

function selectQuoting(): void {
    emit('update:modelValue', [...SPECIAL_ORDER_WORKFLOW_QUOTING]);
}

function selectProcessing(): void {
    emit('update:modelValue', [...SPECIAL_ORDER_WORKFLOW_PROCESSING]);
}

function selectRejected(): void {
    emit('update:modelValue', [...SPECIAL_ORDER_WORKFLOW_REJECTED]);
}

function toggle(status: SpecialOrderWorkflowStatus): void {
    if (isVisible(status)) {
        if (props.modelValue.length <= 1) {
            return;
        }
        emit(
            'update:modelValue',
            props.modelValue.filter((s) => s !== status),
        );
        return;
    }

    emit('update:modelValue', [...props.modelValue, status]);
}

function stepClass(status: SpecialOrderWorkflowStatus): string {
    const base = specialOrderWorkflowStatusTailwindClass(status);
    if (isVisible(status)) {
        return base;
    }

    return 'bg-slate-100 text-slate-400 line-through';
}

function countFor(status: SpecialOrderWorkflowStatus): number {
    return props.statusCounts?.[status] ?? 0;
}

function countForGroup(statuses: SpecialOrderWorkflowStatus[]): number {
    return statuses.reduce((sum, status) => sum + countFor(status), 0);
}

function groupPillClass(selected: boolean): string {
    return selected
        ? 'bg-slate-700 text-white opacity-100 ring-2 ring-slate-900/10'
        : 'bg-slate-100 text-slate-600 opacity-80 hover:opacity-100';
}
</script>

<template>
    <div class="text-sm">
        <span class="mb-2 block font-medium text-slate-700">Workflow timeline</span>

        <div class="mb-2 flex flex-wrap items-center gap-2" aria-label="Filter orders by workflow group">
            <button
                type="button"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium transition-opacity"
                title="Show all workflow steps"
                :aria-pressed="allSelected"
                :class="
                    allSelected
                        ? 'bg-slate-900 text-white opacity-100 ring-2 ring-slate-900/10'
                        : 'bg-slate-100 text-slate-500 opacity-80 hover:opacity-100'
                "
                @click="selectAll"
            >
                All ({{ totalCount }})
            </button>
            <button
                type="button"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium transition-opacity"
                title="Show quoting steps only"
                :aria-pressed="quotingSelected"
                :class="groupPillClass(quotingSelected)"
                @click="selectQuoting"
            >
                Quoting ({{ countForGroup(SPECIAL_ORDER_WORKFLOW_QUOTING) }})
            </button>
            <button
                type="button"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium transition-opacity"
                title="Show processing steps only"
                :aria-pressed="processingSelected"
                :class="groupPillClass(processingSelected)"
                @click="selectProcessing"
            >
                Processing ({{ countForGroup(SPECIAL_ORDER_WORKFLOW_PROCESSING) }})
            </button>
            <button
                type="button"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium transition-opacity"
                title="Show rejected orders only"
                :aria-pressed="rejectedSelected"
                :class="
                    rejectedSelected
                        ? 'bg-rose-100 text-rose-800 opacity-100 ring-2 ring-slate-900/10'
                        : 'bg-slate-100 text-slate-600 opacity-80 hover:opacity-100'
                "
                @click="selectRejected"
            >
                {{ specialOrderWorkflowTimelineShortLabel('rejected') }}
                ({{ countFor('rejected') }})
            </button>
        </div>

        <div class="min-w-0 overflow-x-auto pb-1">
            <ol
                class="flex min-w-max items-center gap-0"
                aria-label="Filter orders by workflow step"
            >
                <li
                    v-for="(status, index) in SPECIAL_ORDER_WORKFLOW_PIPELINE"
                    :key="status"
                    class="flex items-center"
                >
                    <button
                        type="button"
                        class="group flex flex-col items-center gap-1 px-1"
                        :title="
                            isVisible(status)
                                ? `Hide ${specialOrderWorkflowStatusLabel(status)}`
                                : `Show ${specialOrderWorkflowStatusLabel(status)}`
                        "
                        :aria-pressed="isVisible(status)"
                        @click="toggle(status)"
                    >
                        <span
                            class="inline-flex min-w-[2.75rem] items-center justify-center whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium transition-opacity"
                            :class="[
                                stepClass(status),
                                isVisible(status)
                                    ? 'opacity-100 ring-2 ring-slate-900/10'
                                    : 'opacity-60',
                            ]"
                        >
                            {{ specialOrderWorkflowTimelineShortLabel(status) }}
                            ({{ countFor(status) }})
                        </span>
                    </button>
                    <span
                        v-if="index < SPECIAL_ORDER_WORKFLOW_PIPELINE.length - 1"
                        class="mx-0.5 h-px w-3 shrink-0 bg-slate-300"
                        aria-hidden="true"
                    />
                </li>
            </ol>
        </div>
    </div>
</template>
