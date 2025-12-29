<script setup lang="ts">
import { RouterLink, useRoute } from 'vue-router';
import logoUrl from '../assets/operation-v-logo.svg?url';

const route = useRoute();

function isActive(name: string): boolean {
    if (name === 'inventory-check') {
        return route.path.startsWith('/inventory-check');
    }
    return route.name === name;
}
</script>

<template>
    <header class="border-b border-slate-200 bg-white">
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
