<script setup lang="ts">
import { ref } from 'vue';
import { api } from '../lib/api';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';

const flushing = ref(false);
const resettingRun = ref(false);
const message = ref<string | null>(null);
const error = ref<string | null>(null);

type ConfirmState =
    | {
          kind: 'flush_products';
          title: string;
          message: string;
          confirmText: string;
          variant: 'danger' | 'primary';
      }
    | {
          kind: 'reset_run';
          title: string;
          message: string;
          confirmText: string;
          variant: 'danger' | 'primary';
      };

const confirm = ref<ConfirmState | null>(null);

function requestFlush(): void {
    confirm.value = {
        kind: 'flush_products',
        title: 'Flush products table',
        message: 'This will delete ALL products from the database. Continue?',
        confirmText: 'Flush',
        variant: 'danger',
    };
}

function requestResetRun(): void {
    confirm.value = {
        kind: 'reset_run',
        title: 'Reset stuck price research run',
        message: 'Mark the current queued/running price research run as FAILED?',
        confirmText: 'Reset run',
        variant: 'danger',
    };
}

async function confirmAction(): Promise<void> {
    const current = confirm.value;
    if (!current) return;

    if (current.kind === 'flush_products') {
        await flush();
        return;
    }

    await resetPriceResearchRun();
}

function cancelConfirm(): void {
    confirm.value = null;
}

async function flush(): Promise<void> {
    flushing.value = true;
    message.value = null;
    error.value = null;

    try {
        await api.delete('/api/v1/products');
        message.value = 'All products flushed.';
    } catch (e: unknown) {
        error.value = 'Failed to flush products.';
    } finally {
        flushing.value = false;
        confirm.value = null;
    }
}

async function resetPriceResearchRun(): Promise<void> {
    resettingRun.value = true;
    message.value = null;
    error.value = null;

    try {
        const res = await api.post<{ message: string }>(
            '/api/v1/price-research/runs/reset',
            {},
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            error.value = res.data?.message ?? 'Failed to reset run.';
            return;
        }

        message.value = 'Price research run reset.';
    } catch (e: unknown) {
        error.value = 'Failed to reset run.';
    } finally {
        resettingRun.value = false;
        confirm.value = null;
    }
}
</script>

<template>
    <section class="space-y-4">
        <div>
            <h1 class="text-xl font-semibold">Maintenance</h1>
            <p class="mt-1 text-sm text-slate-600">
                Admin utilities for maintaining imported data.
            </p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="text-sm font-medium text-slate-900">Flush products table</div>
                    <div class="mt-1 text-sm text-slate-600">
                        Deletes all products currently stored.
                    </div>
                </div>

                <button
                    class="inline-flex items-center justify-center rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    :disabled="flushing"
                    @click="requestFlush"
                >
                    {{ flushing ? 'Flushing…' : 'Flush' }}
                </button>
            </div>

            <div
                v-if="error"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ error }}
            </div>

            <div
                v-if="message"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ message }}
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="text-sm font-medium text-slate-900">
                        Reset stuck price research run
                    </div>
                    <div class="mt-1 text-sm text-slate-600">
                        If the UI says the latest run is queued/running forever, you can mark it as
                        failed to unblock the dashboard.
                    </div>
                </div>

                <button
                    class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    :disabled="resettingRun"
                    @click="requestResetRun"
                >
                    {{ resettingRun ? 'Resetting…' : 'Reset run' }}
                </button>
            </div>

            <div
                v-if="error"
                class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ error }}
            </div>

            <div
                v-if="message"
                class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ message }}
            </div>
        </div>

        <ConfirmDialog
            :open="confirm !== null"
            :title="confirm?.title ?? ''"
            :message="confirm?.message ?? ''"
            :confirm-text="confirm?.confirmText ?? 'Confirm'"
            :variant="confirm?.variant ?? 'primary'"
            :busy="flushing || resettingRun"
            @cancel="cancelConfirm"
            @confirm="confirmAction"
        />
    </section>
</template>
