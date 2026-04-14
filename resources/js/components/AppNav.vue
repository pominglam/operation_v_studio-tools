<script setup lang="ts">
import { RouterLink, useRoute } from 'vue-router';
import logoUrl from '../assets/operation-v-logo.svg?url';
import { currentAccessRole } from '../lib/accessRole';
import { employeeInventoryScanNotFoundBg } from '../lib/employeeInventoryScanUi';

const route = useRoute();
const isEmployee = currentAccessRole() === 'employee';

function isActive(name: string): boolean {
    if (name === 'inventory-check') {
        return route.path.startsWith('/inventory-check');
    }
    if (name === 'purchase-orders') {
        return route.path.startsWith('/purchase-orders');
    }
    return route.name === name;
}
</script>

<template>
    <header
        class="border-b transition-[background-color,border-color] duration-300 ease-out"
        :class="
            isEmployee && employeeInventoryScanNotFoundBg
                ? 'border-red-700 bg-red-500'
                : 'border-slate-200 bg-white'
        "
    >
        <div class="mx-auto flex w-full max-w-screen-2xl items-center justify-between px-4 py-3">
            <div class="flex items-center gap-3">
                <img
                    :src="logoUrl"
                    alt="Operation V"
                    class="h-[52px] w-auto max-w-[240px] shrink-0 object-contain"
                    loading="eager"
                />
            </div>

            <nav class="flex items-center gap-2 text-sm">
                <RouterLink
                    v-if="isEmployee"
                    to="/employee/inventory-count"
                    class="rounded-md px-3 py-1.5 transition"
                    :class="
                        route.path.startsWith('/employee/inventory-count')
                            ? 'bg-slate-900 text-white'
                            : 'text-slate-700 hover:bg-slate-100'
                    "
                >
                    Inventory Count
                </RouterLink>
                <RouterLink
                    v-if="!isEmployee"
                    to="/products"
                    class="rounded-md px-3 py-1.5 transition"
                    :class="
                        isActive('products')
                            ? 'bg-slate-900 text-white'
                            : 'text-slate-700 hover:bg-slate-100'
                    "
                >
                    Products
                </RouterLink>
                <RouterLink
                    v-if="!isEmployee"
                    to="/inventory-check"
                    class="rounded-md px-3 py-1.5 transition"
                    :class="
                        isActive('inventory-check')
                            ? 'bg-slate-900 text-white'
                            : 'text-slate-700 hover:bg-slate-100'
                    "
                >
                    Inventory Check
                </RouterLink>
                <RouterLink
                    v-if="!isEmployee"
                    to="/purchase-orders"
                    class="rounded-md px-3 py-1.5 transition"
                    :class="
                        isActive('purchase-orders')
                            ? 'bg-slate-900 text-white'
                            : 'text-slate-700 hover:bg-slate-100'
                    "
                >
                    Purchase Orders
                </RouterLink>
                <RouterLink
                    v-if="!isEmployee"
                    to="/price-research"
                    class="rounded-md px-3 py-1.5 transition"
                    :class="
                        isActive('price-research')
                            ? 'bg-slate-900 text-white'
                            : 'text-slate-700 hover:bg-slate-100'
                    "
                >
                    Pricing
                </RouterLink>
                <RouterLink
                    v-if="!isEmployee"
                    to="/tcg-events"
                    class="rounded-md px-3 py-1.5 transition"
                    :class="
                        isActive('tcg-events')
                            ? 'bg-slate-900 text-white'
                            : 'text-slate-700 hover:bg-slate-100'
                    "
                >
                    TCG Events
                </RouterLink>
                <RouterLink
                    v-if="!isEmployee"
                    to="/maintenance"
                    class="rounded-md px-3 py-1.5 transition"
                    :class="
                        isActive('maintenance')
                            ? 'bg-slate-900 text-white'
                            : 'text-slate-700 hover:bg-slate-100'
                    "
                >
                    Maintenance
                </RouterLink>
            </nav>
        </div>
    </header>
</template>
