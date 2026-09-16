<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { StorePreorderBulkChanges } from '../../types/storePreorder';

const props = defineProps<{
    open: boolean;
    selectedCount: number;
    closedCount: number;
    busy: boolean;
}>();

const emit = defineEmits<{
    (e: 'cancel'): void;
    (e: 'confirm', changes: StorePreorderBulkChanges): void;
}>();

const applyCap = ref(false);
const applyDeposit = ref(false);
const applySell = ref(false);
const applyClosing = ref(false);
const capValue = ref('');
const depositValue = ref('20');
const sellValue = ref('');
const closingValue = ref('');
const localError = ref<string | null>(null);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }
        applyCap.value = false;
        applyDeposit.value = false;
        applySell.value = false;
        applyClosing.value = false;
        capValue.value = '';
        depositValue.value = '20';
        sellValue.value = '';
        closingValue.value = '';
        localError.value = null;
    },
);

const canSubmit = computed(
    () => applyCap.value || applyDeposit.value || applySell.value || applyClosing.value,
);

function parseCap(): { ok: boolean; value: number | null } {
    const raw = String(capValue.value ?? '').trim();
    if (raw === '') {
        return { ok: true, value: null };
    }
    const n = Number(raw);
    if (!Number.isInteger(n) || n < 1 || n > 9999) {
        return { ok: false, value: null };
    }

    return { ok: true, value: n };
}

function submit(): void {
    if (!canSubmit.value || props.busy) {
        return;
    }
    const changes: StorePreorderBulkChanges = {};
    if (applyCap.value) {
        const parsed = parseCap();
        if (!parsed.ok) {
            localError.value = 'Cap must be a whole number from 1 to 9999, or empty for no cap.';
            return;
        }
        changes.cap_qty = parsed.value;
    }
    if (applyDeposit.value) {
        const n = Number(depositValue.value);
        if (!Number.isFinite(n) || n < 1 || n > 100) {
            localError.value = 'Deposit must be between 1 and 100.';
            return;
        }
        changes.deposit_percent = String(n);
    }
    if (applySell.value) {
        const n = Number(sellValue.value);
        if (!Number.isFinite(n) || n < 0.01 || n > 99999.99) {
            localError.value = 'Sell $ must be between 0.01 and 99999.99.';
            return;
        }
        changes.selling_price = String(n);
    }
    if (applyClosing.value) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(closingValue.value)) {
            localError.value = 'Closing date is required when that field is applied.';
            return;
        }
        changes.window_ends_on = closingValue.value;
    }
    localError.value = null;
    emit('confirm', changes);
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="store-preorders-bulk-edit-title"
            @click.self="emit('cancel')"
        >
            <div class="w-full max-w-lg rounded-lg bg-white p-4 shadow-xl">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2
                            id="store-preorders-bulk-edit-title"
                            class="text-sm font-semibold text-slate-900"
                        >
                            Bulk edit {{ selectedCount }}
                            {{ selectedCount === 1 ? 'store preorder' : 'store preorders' }}
                        </h2>
                        <p class="mt-1 text-sm text-slate-600">
                            Only checked fields are applied. Closed offers are skipped.
                            <span v-if="closedCount > 0">
                                {{ closedCount }} selected
                                {{ closedCount === 1 ? 'row is' : 'rows are' }} closed.
                            </span>
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded px-2 py-1 text-sm text-slate-500 hover:bg-slate-100"
                        :disabled="busy"
                        @click="emit('cancel')"
                    >
                        Close
                    </button>
                </div>

                <div class="mt-4 space-y-3">
                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="applyCap"
                            type="checkbox"
                            class="rounded border-slate-300"
                            data-testid="store-preorders-bulk-apply-cap"
                        />
                        Cap
                        <input
                            v-model="capValue"
                            type="number"
                            min="1"
                            max="9999"
                            step="1"
                            placeholder="No cap"
                            class="h-8 w-24 rounded-md border border-slate-300 px-2 text-sm disabled:opacity-50"
                            :disabled="!applyCap"
                            data-testid="store-preorders-bulk-cap"
                        />
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="applyDeposit"
                            type="checkbox"
                            class="rounded border-slate-300"
                            data-testid="store-preorders-bulk-apply-deposit"
                        />
                        Deposit %
                        <input
                            v-model="depositValue"
                            type="number"
                            min="1"
                            max="100"
                            step="0.01"
                            class="h-8 w-24 rounded-md border border-slate-300 px-2 text-sm disabled:opacity-50"
                            :disabled="!applyDeposit"
                            data-testid="store-preorders-bulk-deposit"
                        />
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="applySell"
                            type="checkbox"
                            class="rounded border-slate-300"
                            data-testid="store-preorders-bulk-apply-sell"
                        />
                        Sell $
                        <input
                            v-model="sellValue"
                            type="number"
                            min="0.01"
                            max="99999.99"
                            step="0.01"
                            class="h-8 w-28 rounded-md border border-slate-300 px-2 text-sm disabled:opacity-50"
                            :disabled="!applySell"
                            data-testid="store-preorders-bulk-sell"
                        />
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="applyClosing"
                            type="checkbox"
                            class="rounded border-slate-300"
                            data-testid="store-preorders-bulk-apply-closing"
                        />
                        Closing
                        <input
                            v-model="closingValue"
                            type="date"
                            class="h-8 rounded-md border border-slate-300 px-2 text-sm disabled:opacity-50"
                            :disabled="!applyClosing"
                            data-testid="store-preorders-bulk-closing"
                        />
                    </label>
                </div>

                <p v-if="localError" class="mt-3 text-sm text-red-700">{{ localError }}</p>

                <div class="mt-4 flex items-center justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                        :disabled="busy"
                        @click="emit('cancel')"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                        data-testid="store-preorders-bulk-edit-confirm"
                        :disabled="busy || !canSubmit"
                        @click="submit"
                    >
                        {{ busy ? 'Updating…' : 'Apply to selected' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
