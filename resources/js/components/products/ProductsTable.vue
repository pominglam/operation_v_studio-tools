<script setup lang="ts">
import { computed, ref } from 'vue';

export type ProductRow = {
  id: string; // uuid
  sku: string;
  barcode: string | null;
  description: string;
  type: string | null;
  price: string | null;
  order: number | null;
  filled: number | null;
  extended: string | null;
};

export type UpdateProductPayload = {
  sku: string;
  barcode: string | null;
  description: string;
  type: string | null;
  price: string | null;
  order: number | null;
  filled: number | null;
  extended: string | null;
};

export type ProductSortKey =
  | 'sku'
  | 'barcode'
  | 'description'
  | 'type'
  | 'price'
  | 'order'
  | 'filled'
  | 'extended';

const props = defineProps<{
  loading: boolean;
  products: ProductRow[];
  sortBy: ProductSortKey;
  sortDir: 'asc' | 'desc';
  onSortChange: (sortBy: ProductSortKey) => void;
  onRefresh: () => Promise<void>;
  onBulkDelete: (ids: string[]) => Promise<number>;
  onUpdate: (id: string, payload: UpdateProductPayload) => Promise<void>;
}>();

const selected = ref<Set<string>>(new Set());
const bulkDeleting = ref(false);
const bulkMessage = ref<string | null>(null);
const bulkError = ref<string | null>(null);

const editingId = ref<string | null>(null);
const draft = ref<UpdateProductPayload | null>(null);
const saving = ref(false);
const rowError = ref<string | null>(null);

const allSelected = computed(() => props.products.length > 0 && selected.value.size === props.products.length);

function sortLabel(key: ProductSortKey): string {
  const map: Record<ProductSortKey, string> = {
    sku: 'SKU',
    barcode: 'Barcode',
    description: 'Description',
    type: 'Type',
    price: 'Price',
    order: 'Order',
    filled: 'Filled',
    extended: 'Extended',
  };
  return map[key];
}

function sortIndicator(key: ProductSortKey): string {
  if (props.sortBy !== key) return '';
  return props.sortDir === 'asc' ? ' ▲' : ' ▼';
}

function sortHeaderClass(key: ProductSortKey): string {
  return props.sortBy === key ? 'text-slate-900' : 'text-slate-600';
}

function syncSelection(): void {
  const allowed = new Set(props.products.map((p) => p.id));
  selected.value = new Set(Array.from(selected.value).filter((id) => allowed.has(id)));
}

function toggleAll(checked: boolean): void {
  if (checked) {
    selected.value = new Set(props.products.map((p) => p.id));
    return;
  }
  selected.value = new Set();
}

function toggleOne(id: string, checked: boolean): void {
  const next = new Set(selected.value);
  if (checked) next.add(id);
  else next.delete(id);
  selected.value = next;
}

async function bulkDelete(): Promise<void> {
  bulkError.value = null;
  bulkMessage.value = null;

  const ids = Array.from(selected.value);
  if (ids.length === 0) {
    bulkError.value = 'No products selected.';
    return;
  }

  const ok = window.confirm(`Delete ${ids.length} selected product(s)?`);
  if (!ok) return;

  bulkDeleting.value = true;

  try {
    const deleted = await props.onBulkDelete(ids);
    bulkMessage.value = `Deleted ${deleted} product(s).`;
    selected.value = new Set();
    await props.onRefresh();
    syncSelection();
  } catch (e: unknown) {
    bulkError.value = 'Failed to delete selected products.';
  } finally {
    bulkDeleting.value = false;
  }
}

function startEdit(p: ProductRow): void {
  rowError.value = null;
  editingId.value = p.id;
  draft.value = {
    sku: p.sku,
    barcode: p.barcode,
    description: p.description,
    type: p.type,
    price: p.price,
    order: p.order,
    filled: p.filled,
    extended: p.extended,
  };
}

function cancelEdit(): void {
  editingId.value = null;
  draft.value = null;
  rowError.value = null;
}

async function saveEdit(): Promise<void> {
  if (!editingId.value || !draft.value) return;

  rowError.value = null;
  if (!draft.value.sku.trim() || !draft.value.description.trim()) {
    rowError.value = 'SKU and Description are required.';
    return;
  }

  saving.value = true;
  try {
    await props.onUpdate(editingId.value, {
      sku: draft.value.sku.trim(),
      barcode: draft.value.barcode?.trim() || null,
      description: draft.value.description.trim(),
      type: draft.value.type?.trim() || null,
      price: draft.value.price?.trim() || null,
      order: draft.value.order,
      filled: draft.value.filled,
      extended: draft.value.extended?.trim() || null,
    });
    await props.onRefresh();
    cancelEdit();
  } catch (e: unknown) {
    rowError.value = 'Failed to save changes (check SKU uniqueness and required fields).';
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
    <div v-if="loading" class="px-4 py-3 text-sm text-slate-600">Loading…</div>

    <div v-else class="overflow-x-auto">
      <div
        v-if="selected.size > 0"
        class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm"
      >
        <div class="text-slate-700">
          <span class="font-semibold">{{ selected.size }}</span> selected
        </div>
        <div class="flex items-center gap-2">
          <button
            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
            type="button"
            :disabled="bulkDeleting"
            @click="selected = new Set()"
          >
            Clear
          </button>
          <button
            class="rounded-md bg-rose-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-rose-700 disabled:opacity-50"
            type="button"
            :disabled="bulkDeleting"
            @click="bulkDelete"
          >
            {{ bulkDeleting ? 'Deleting…' : 'Delete selected' }}
          </button>
        </div>
      </div>

      <div v-if="bulkError" class="m-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
        {{ bulkError }}
      </div>
      <div
        v-if="bulkMessage"
        class="m-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
      >
        {{ bulkMessage }}
      </div>
      <div v-if="rowError" class="m-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
        {{ rowError }}
      </div>

      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
          <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
            <th class="w-12 px-4 py-3">
              <input
                class="h-4 w-4 rounded border-slate-300"
                type="checkbox"
                :checked="allSelected"
                :disabled="products.length === 0"
                @change="toggleAll(($event.target as HTMLInputElement).checked)"
              />
            </th>
            <th class="px-4 py-3">
              <button type="button" class="hover:underline" :class="sortHeaderClass('sku')" @click="onSortChange('sku')">
                {{ sortLabel('sku') }}{{ sortIndicator('sku') }}
              </button>
            </th>
            <th class="px-4 py-3">
              <button type="button" class="hover:underline" :class="sortHeaderClass('barcode')" @click="onSortChange('barcode')">
                {{ sortLabel('barcode') }}{{ sortIndicator('barcode') }}
              </button>
            </th>
            <th class="px-4 py-3">
              <button
                type="button"
                class="hover:underline"
                :class="sortHeaderClass('description')"
                @click="onSortChange('description')"
              >
                {{ sortLabel('description') }}{{ sortIndicator('description') }}
              </button>
            </th>
            <th class="px-4 py-3">
              <button type="button" class="hover:underline" :class="sortHeaderClass('type')" @click="onSortChange('type')">
                {{ sortLabel('type') }}{{ sortIndicator('type') }}
              </button>
            </th>
            <th class="px-4 py-3 text-right">
              <button type="button" class="hover:underline" :class="sortHeaderClass('price')" @click="onSortChange('price')">
                {{ sortLabel('price') }}{{ sortIndicator('price') }}
              </button>
            </th>
            <th class="px-4 py-3 text-right">
              <button type="button" class="hover:underline" :class="sortHeaderClass('order')" @click="onSortChange('order')">
                {{ sortLabel('order') }}{{ sortIndicator('order') }}
              </button>
            </th>
            <th class="px-4 py-3 text-right">
              <button type="button" class="hover:underline" :class="sortHeaderClass('filled')" @click="onSortChange('filled')">
                {{ sortLabel('filled') }}{{ sortIndicator('filled') }}
              </button>
            </th>
            <th class="px-4 py-3 text-right">
              <button
                type="button"
                class="hover:underline"
                :class="sortHeaderClass('extended')"
                @click="onSortChange('extended')"
              >
                {{ sortLabel('extended') }}{{ sortIndicator('extended') }}
              </button>
            </th>
            <th class="px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="products.length === 0">
            <td class="px-4 py-4 text-slate-600" colspan="10">No products yet. Import a CSV or add one manually above.</td>
          </tr>

          <tr v-for="p in products" :key="p.id" class="hover:bg-slate-50">
            <td class="px-4 py-3">
              <input
                class="h-4 w-4 rounded border-slate-300"
                type="checkbox"
                :checked="selected.has(p.id)"
                @change="toggleOne(p.id, ($event.target as HTMLInputElement).checked)"
              />
            </td>

            <td class="px-4 py-3 font-medium text-slate-900">
              <template v-if="editingId === p.id">
                <input v-model="draft!.sku" class="w-40 rounded-md border border-slate-200 px-2 py-1 text-sm" type="text" />
              </template>
              <template v-else>{{ p.sku }}</template>
            </td>

            <td class="px-4 py-3 text-slate-700">
              <template v-if="editingId === p.id">
                <input
                  v-model="draft!.barcode"
                  class="w-44 rounded-md border border-slate-200 px-2 py-1 text-sm"
                  type="text"
                />
              </template>
              <template v-else>{{ p.barcode ?? '—' }}</template>
            </td>

            <td class="px-4 py-3 text-slate-700">
              <template v-if="editingId === p.id">
                <input
                  v-model="draft!.description"
                  class="w-[28rem] rounded-md border border-slate-200 px-2 py-1 text-sm"
                  type="text"
                />
              </template>
              <template v-else>{{ p.description }}</template>
            </td>

            <td class="px-4 py-3 text-slate-700">
              <template v-if="editingId === p.id">
                <input v-model="draft!.type" class="w-24 rounded-md border border-slate-200 px-2 py-1 text-sm" type="text" />
              </template>
              <template v-else>{{ p.type ?? '—' }}</template>
            </td>

            <td class="px-4 py-3 text-right tabular-nums text-slate-700">
              <template v-if="editingId === p.id">
                <input v-model="draft!.price" class="w-24 rounded-md border border-slate-200 px-2 py-1 text-sm text-right" type="text" />
              </template>
              <template v-else>{{ p.price ?? '—' }}</template>
            </td>

            <td class="px-4 py-3 text-right tabular-nums text-slate-700">
              <template v-if="editingId === p.id">
                <input v-model.number="draft!.order" class="w-20 rounded-md border border-slate-200 px-2 py-1 text-sm text-right" type="number" min="0" />
              </template>
              <template v-else>{{ p.order ?? '—' }}</template>
            </td>

            <td class="px-4 py-3 text-right tabular-nums text-slate-700">
              <template v-if="editingId === p.id">
                <input
                  v-model.number="draft!.filled"
                  class="w-20 rounded-md border border-slate-200 px-2 py-1 text-sm text-right"
                  type="number"
                  min="0"
                />
              </template>
              <template v-else>{{ p.filled ?? '—' }}</template>
            </td>

            <td class="px-4 py-3 text-right tabular-nums text-slate-700">
              <template v-if="editingId === p.id">
                <input
                  v-model="draft!.extended"
                  class="w-24 rounded-md border border-slate-200 px-2 py-1 text-sm text-right"
                  type="text"
                />
              </template>
              <template v-else>{{ p.extended ?? '—' }}</template>
            </td>

            <td class="px-4 py-3 text-right">
              <div v-if="editingId === p.id" class="flex justify-end gap-2">
                <button
                  class="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50"
                  type="button"
                  :disabled="saving"
                  @click="saveEdit"
                >
                  {{ saving ? 'Saving…' : 'Save' }}
                </button>
                <button
                  class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-900 transition hover:bg-slate-50"
                  type="button"
                  :disabled="saving"
                  @click="cancelEdit"
                >
                  Cancel
                </button>
              </div>
              <div v-else class="flex justify-end">
                <button
                  class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-900 transition hover:bg-slate-50"
                  type="button"
                  @click="startEdit(p)"
                >
                  Edit
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>


