<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api } from '../lib/api';
import { employeeInventoryScanNotFoundBg } from '../lib/employeeInventoryScanUi';
import { formatMoney2OrEmpty } from '../lib/money';

type SessionItem = {
    id: number;
    product_id: string | null;
    barcode_scanned: string | null;
    sku: string;
    product_name: string | null;
    quantity: number;
    available_amount: number | null;
    difference: number | null;
    selling_price: string | null;
    match_status: 'matched' | 'unmatched' | 'ambiguous';
    match_error: string | null;
    issue_flag: boolean;
    issue_reason: string | null;
    applied: boolean;
    applied_at: string | null;
    image_url: string | null;
    updated_at: string | null;
};

type SessionPayload = {
    session: {
        id: string;
        name: string | null;
        workflow_state: string;
        counts: { lines: number; units: number; issues: number };
    };
    items: SessionItem[];
};

const STORAGE_KEY = 'employee_inventory_count_session_id';

const loading = ref(false);
const error = ref<string | null>(null);
const message = ref<string | null>(null);
const barcodeInput = ref('');
const scanBusy = ref(false);
const endingSession = ref(false);
const savingLine = ref<Record<number, true>>({});
const sessionData = ref<SessionPayload | null>(null);
const lastScannedBarcode = ref<string | null>(null);
const scanInputEl = ref<HTMLInputElement | null>(null);
let idleScanTimer: ReturnType<typeof setTimeout> | null = null;

const hasSession = computed<boolean>(() => sessionData.value !== null);
const items = computed<SessionItem[]>(() => sessionData.value?.items ?? []);
const recentResult = computed<SessionItem | null>(() => {
    if (!lastScannedBarcode.value) return null;
    const barcode = lastScannedBarcode.value;
    const matched = items.value.filter((x) => (x.barcode_scanned ?? '') === barcode);
    if (matched.length === 0) return null;

    return matched.slice().sort((a, b) => b.id - a.id)[0] ?? null;
});

const fullPageNotFoundBg = computed<boolean>(
    () =>
        hasSession.value &&
        recentResult.value !== null &&
        isBarcodeNotFoundLine(recentResult.value),
);

watch(
    fullPageNotFoundBg,
    (v) => {
        employeeInventoryScanNotFoundBg.value = v;
    },
    { immediate: true },
);

function isSavingLine(lineId: number): boolean {
    return savingLine.value[lineId] === true;
}

/** Matches server copy from EmployeeInventoryCountService when no active product has this barcode. */
function isBarcodeNotFoundLine(line: SessionItem): boolean {
    const err = line.match_error ?? '';

    return line.match_status === 'unmatched' && err.includes('No active product found');
}

function rememberSessionId(id: string): void {
    try {
        window.localStorage.setItem(STORAGE_KEY, id);
    } catch {
        // ignore
    }
}

function getRememberedSessionId(): string | null {
    try {
        const v = window.localStorage.getItem(STORAGE_KEY);
        if (!v) return null;
        const s = v.trim();

        return s !== '' ? s : null;
    } catch {
        return null;
    }
}

async function createSession(): Promise<string> {
    const res = await api.post<{ data: SessionPayload }>(
        '/api/v1/inventory-check/employee/sessions',
        {
            name: null,
        },
    );
    sessionData.value = res.data.data;
    const id = res.data.data.session.id;
    rememberSessionId(id);

    return id;
}

async function loadSession(sessionId: string): Promise<void> {
    const res = await api.get<{ data: SessionPayload }>(
        `/api/v1/inventory-check/employee/sessions/${sessionId}`,
    );
    sessionData.value = res.data.data;
    rememberSessionId(res.data.data.session.id);
}

async function ensureSession(): Promise<void> {
    loading.value = true;
    error.value = null;
    message.value = null;
    try {
        const remembered = getRememberedSessionId();
        if (remembered) {
            try {
                await loadSession(remembered);
                return;
            } catch {
                // stale/deleted session -> create a new one
            }
        }
        await createSession();
    } catch {
        error.value = 'Failed to initialize inventory count session.';
    } finally {
        loading.value = false;
    }
}

async function refocusInput(): Promise<void> {
    await nextTick();
    scanInputEl.value?.focus();
}

async function scanBarcode(): Promise<void> {
    if (!sessionData.value) return;
    if (scanBusy.value) return;
    const barcode = barcodeInput.value.trim();
    if (barcode === '') {
        return;
    }

    scanBusy.value = true;
    error.value = null;
    message.value = null;
    try {
        const sessionId = sessionData.value.session.id;
        const res = await api.post<{ data: SessionPayload }>(
            `/api/v1/inventory-check/employee/sessions/${sessionId}/scan`,
            { barcode },
        );
        sessionData.value = res.data.data;
        lastScannedBarcode.value = barcode;
        barcodeInput.value = '';
    } catch {
        error.value = 'Scan failed. Try again.';
    } finally {
        scanBusy.value = false;
        await refocusInput();
    }
}

async function startNewSession(): Promise<void> {
    if (endingSession.value) return;
    const confirmed = window.confirm('End this scan session and start a new one?');
    if (!confirmed) return;

    endingSession.value = true;
    error.value = null;
    message.value = null;
    clearIdleScanTimer();
    try {
        await createSession();
        lastScannedBarcode.value = null;
        barcodeInput.value = '';
        message.value = 'Started a new inventory count session.';
    } catch {
        error.value = 'Failed to start a new session.';
    } finally {
        endingSession.value = false;
        await refocusInput();
    }
}

function clearIdleScanTimer(): void {
    if (idleScanTimer !== null) {
        clearTimeout(idleScanTimer);
        idleScanTimer = null;
    }
}

watch(barcodeInput, (next) => {
    clearIdleScanTimer();
    if (scanBusy.value) return;
    if (!sessionData.value) return;
    if (next.trim() === '') return;

    idleScanTimer = setTimeout(() => {
        if (scanBusy.value) return;
        if (barcodeInput.value.trim() === '') return;
        void scanBarcode();
    }, 500);
});

async function setLineQuantity(lineId: number, quantity: number): Promise<void> {
    if (!sessionData.value) return;
    if (isSavingLine(lineId)) return;

    const nextQty = Math.max(0, Math.floor(quantity));
    savingLine.value = { ...savingLine.value, [lineId]: true };
    error.value = null;
    try {
        const sessionId = sessionData.value.session.id;
        const res = await api.patch<{ data: SessionPayload }>(
            `/api/v1/inventory-check/employee/sessions/${sessionId}/lines/${lineId}`,
            { quantity: nextQty },
        );
        sessionData.value = res.data.data;
    } catch {
        error.value = 'Failed to update quantity.';
    } finally {
        const { [lineId]: _omit, ...rest } = savingLine.value;
        savingLine.value = rest;
    }
}

async function setLineProductName(lineId: number, productName: string): Promise<void> {
    if (!sessionData.value) return;
    if (isSavingLine(lineId)) return;

    savingLine.value = { ...savingLine.value, [lineId]: true };
    error.value = null;
    try {
        const sessionId = sessionData.value.session.id;
        const res = await api.patch<{ data: SessionPayload }>(
            `/api/v1/inventory-check/employee/sessions/${sessionId}/lines/${lineId}`,
            { product_name: productName },
        );
        sessionData.value = res.data.data;
    } catch {
        error.value = 'Failed to update name.';
    } finally {
        const { [lineId]: _omit, ...rest } = savingLine.value;
        savingLine.value = rest;
    }
}

async function removeLine(lineId: number): Promise<void> {
    if (!sessionData.value) return;
    if (isSavingLine(lineId)) return;

    savingLine.value = { ...savingLine.value, [lineId]: true };
    error.value = null;
    try {
        const sessionId = sessionData.value.session.id;
        const res = await api.delete<{ data: SessionPayload }>(
            `/api/v1/inventory-check/employee/sessions/${sessionId}/lines/${lineId}`,
        );
        sessionData.value = res.data.data;
    } catch {
        error.value = 'Failed to remove line.';
    } finally {
        const { [lineId]: _omit, ...rest } = savingLine.value;
        savingLine.value = rest;
    }
}

onMounted(async () => {
    await ensureSession();
    await refocusInput();
});

onBeforeUnmount(() => {
    clearIdleScanTimer();
    employeeInventoryScanNotFoundBg.value = false;
});
</script>

<template>
    <section class="space-y-4">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Inventory Count</h1>
            <p class="mt-1 text-sm text-slate-600">
                Scan barcodes to count inventory. Quantities are saved for admin review before
                applying.
            </p>
        </div>

        <div
            v-if="error"
            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
        >
            {{ error }}
        </div>
        <div
            v-if="message"
            class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
        >
            {{ message }}
        </div>

        <div v-if="loading" class="text-sm text-slate-600">Loading…</div>

        <template v-else-if="hasSession">
            <div
                v-if="recentResult"
                :class="[
                    'rounded-lg border p-4',
                    isBarcodeNotFoundLine(recentResult)
                        ? 'border-red-300 bg-red-100'
                        : 'border-emerald-200 bg-emerald-50',
                ]"
                data-testid="employee-recent-result"
            >
                <div class="flex items-center gap-3">
                    <div
                        :class="[
                            'h-20 w-20 shrink-0 overflow-hidden rounded-md border bg-white',
                            isBarcodeNotFoundLine(recentResult)
                                ? 'border-red-200'
                                : 'border-emerald-200',
                        ]"
                    >
                        <img
                            v-if="recentResult.image_url"
                            :src="recentResult.image_url"
                            :alt="recentResult.sku"
                            class="h-full w-full object-cover"
                        />
                        <div
                            v-else
                            class="flex h-full w-full items-center justify-center text-xs text-slate-500"
                        >
                            No image
                        </div>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div
                            :class="[
                                'text-xs font-semibold uppercase tracking-wide',
                                isBarcodeNotFoundLine(recentResult)
                                    ? 'text-red-800'
                                    : 'text-emerald-700',
                            ]"
                        >
                            {{
                                isBarcodeNotFoundLine(recentResult)
                                    ? 'Barcode not found'
                                    : 'Last scan'
                            }}
                        </div>
                        <div class="mt-1 truncate text-sm font-semibold text-slate-900">
                            {{ recentResult.product_name ?? recentResult.sku }}
                        </div>
                        <div class="mt-1 text-xs text-slate-700">SKU: {{ recentResult.sku }}</div>
                        <div
                            v-if="isBarcodeNotFoundLine(recentResult) && recentResult.match_error"
                            class="mt-1 text-xs font-medium text-red-900"
                        >
                            {{ recentResult.match_error }}
                        </div>
                        <div v-else class="mt-1 text-xs text-slate-700">
                            Price: {{ formatMoney2OrEmpty(recentResult.selling_price) || '—' }}
                        </div>
                        <div class="mt-1 text-xs text-slate-700">
                            Qty in this session: {{ recentResult.quantity }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <label
                            class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                            >Scan barcode</label
                        >
                        <input
                            ref="scanInputEl"
                            v-model="barcodeInput"
                            class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-3 text-lg font-semibold text-slate-900"
                            type="text"
                            inputmode="numeric"
                            autocomplete="off"
                            autocapitalize="off"
                            spellcheck="false"
                            placeholder="Scan barcode"
                            data-testid="employee-scan-input"
                            @keydown.enter.prevent="scanBarcode"
                        />
                    </div>
                    <div class="flex flex-col justify-end gap-2">
                        <button
                            type="button"
                            class="inline-flex w-full items-center justify-center rounded-md bg-slate-900 px-4 py-3 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-60"
                            :disabled="scanBusy"
                            data-testid="employee-scan-submit"
                            @click="scanBarcode"
                        >
                            {{ scanBusy ? 'Saving…' : 'Scan (+1)' }}
                        </button>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-slate-600">
                    <div>
                        Session:
                        <span class="font-semibold text-slate-900">{{
                            sessionData!.session.id.slice(0, 8)
                        }}</span>
                    </div>
                    <div>
                        Lines:
                        <span class="font-semibold text-slate-900">{{
                            sessionData!.session.counts.lines
                        }}</span>
                    </div>
                    <div>
                        Units:
                        <span class="font-semibold text-slate-900">{{
                            sessionData!.session.counts.units
                        }}</span>
                    </div>
                    <div>
                        Issues:
                        <span class="font-semibold text-slate-900">{{
                            sessionData!.session.counts.issues
                        }}</span>
                    </div>
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                        :disabled="endingSession || scanBusy"
                        @click="startNewSession"
                    >
                        {{ endingSession ? 'Starting…' : 'Session over / start new' }}
                    </button>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div
                    class="border-b border-slate-200 bg-slate-50 px-4 py-2 text-sm font-semibold text-slate-700"
                >
                    Scanned items
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr
                                class="text-left text-xs font-semibold uppercase tracking-wide text-slate-600"
                            >
                                <th class="px-3 py-2">Product</th>
                                <th class="px-3 py-2">SKU</th>
                                <th class="px-3 py-2">Price</th>
                                <th class="px-3 py-2">Qty</th>
                                <th class="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-if="items.length === 0">
                                <td class="px-3 py-3 text-slate-600" colspan="5">No scans yet.</td>
                            </tr>
                            <tr
                                v-for="line in items"
                                :key="line.id"
                                :class="
                                    isBarcodeNotFoundLine(line)
                                        ? 'border-l-4 border-l-red-600 bg-red-100 hover:bg-red-200/90'
                                        : 'hover:bg-slate-50'
                                "
                                :data-testid="`employee-line-${line.id}`"
                            >
                                <td class="px-3 py-2">
                                    <div class="font-medium text-slate-900">
                                        {{ line.product_name ?? line.sku }}
                                    </div>
                                    <input
                                        class="mt-1 w-full rounded-md border border-slate-200 px-2 py-1 text-xs text-slate-900"
                                        type="text"
                                        :value="line.product_name ?? ''"
                                        :disabled="isSavingLine(line.id)"
                                        @change="
                                            setLineProductName(
                                                line.id,
                                                ($event.target as HTMLInputElement).value,
                                            )
                                        "
                                    />
                                    <div
                                        v-if="line.issue_flag"
                                        :class="[
                                            'mt-0.5 text-xs font-semibold',
                                            isBarcodeNotFoundLine(line)
                                                ? 'text-red-900'
                                                : 'text-amber-700',
                                        ]"
                                    >
                                        Issue:
                                        {{
                                            line.issue_reason ?? line.match_error ?? 'Review needed'
                                        }}
                                    </div>
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">{{ line.sku }}</td>
                                <td class="px-3 py-2">
                                    {{ formatMoney2OrEmpty(line.selling_price) || '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-1">
                                        <button
                                            type="button"
                                            class="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-50"
                                            :disabled="isSavingLine(line.id)"
                                            @click="
                                                setLineQuantity(
                                                    line.id,
                                                    Math.max(0, line.quantity - 1),
                                                )
                                            "
                                        >
                                            -
                                        </button>
                                        <input
                                            class="w-20 rounded-md border border-slate-200 px-2 py-1 text-right text-sm"
                                            type="number"
                                            min="0"
                                            :value="line.quantity"
                                            :disabled="isSavingLine(line.id)"
                                            @change="
                                                setLineQuantity(
                                                    line.id,
                                                    Number.parseInt(
                                                        ($event.target as HTMLInputElement).value ||
                                                            '0',
                                                        10,
                                                    ),
                                                )
                                            "
                                        />
                                        <button
                                            type="button"
                                            class="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-50"
                                            :disabled="isSavingLine(line.id)"
                                            @click="setLineQuantity(line.id, line.quantity + 1)"
                                        >
                                            +
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button
                                        type="button"
                                        class="rounded-md border border-rose-200 bg-white px-2 py-1 text-xs text-rose-700 hover:bg-rose-50 disabled:opacity-50"
                                        :disabled="isSavingLine(line.id)"
                                        @click="removeLine(line.id)"
                                    >
                                        Remove
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </section>
</template>

