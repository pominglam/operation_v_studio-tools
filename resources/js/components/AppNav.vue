<script setup lang="ts">
import { RouterLink, useRoute } from 'vue-router';

const route = useRoute();

function isActive(name: string): boolean {
  return route.name === name;
}
</script>

<template>
  <header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex w-full max-w-6xl items-center justify-between px-4 py-3">
      <div class="flex items-center gap-2">
        <div class="h-8 w-8 rounded-lg bg-slate-900"></div>
        <div class="text-sm font-semibold tracking-tight">Pricing Tool</div>
      </div>

      <nav class="flex items-center gap-2 text-sm">
        <RouterLink
          to="/import"
          class="rounded-md px-3 py-1.5 transition"
          :class="isActive('import') ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
        >
          Import
        </RouterLink>
        <RouterLink
          to="/products"
          class="rounded-md px-3 py-1.5 transition"
          :class="isActive('products') ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
        >
          Products
        </RouterLink>
        <RouterLink
          to="/price-research"
          class="rounded-md px-3 py-1.5 transition"
          :class="isActive('price-research') ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
        >
          Research
        </RouterLink>
        <RouterLink
          to="/maintenance"
          class="rounded-md px-3 py-1.5 transition"
          :class="isActive('maintenance') ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
        >
          Maintenance
        </RouterLink>
      </nav>
    </div>
  </header>
</template>


