<script setup lang="ts">
import { RouterLink } from 'vue-router';

import colorBarUrl from '../../assets/operation-v-colorBar.png?url';
import logoUrl from '../../assets/operation-v-logo.svg?url';

defineProps<{
    crumb: string;
}>();
</script>

<template>
    <div class="po-beta__color-bar" aria-hidden="true">
        <img :src="colorBarUrl" alt="" width="890" height="8" />
    </div>
    <header class="po-beta__masthead">
        <img :src="logoUrl" alt="Operation V Studio" class="po-beta__logo" />
        <nav class="po-beta__context-nav" aria-label="Sourcing">
            <RouterLink class="is-active" to="/purchase-orders">Purchase orders</RouterLink>
            <RouterLink to="/preorders">Preorders</RouterLink>
            <RouterLink to="/restocking/plamod">Restock</RouterLink>
        </nav>
        <span style="flex: 1"></span>
        <nav class="po-beta__system-nav" aria-label="System">
            <RouterLink to="/sync-progress">Sync status</RouterLink>
            <span aria-hidden="true">|</span>
            <RouterLink to="/maintenance">Maintenance</RouterLink>
        </nav>
    </header>
    <div class="po-beta__layout">
        <aside class="po-beta__rail" aria-label="App sections">
            <RouterLink to="/products">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 3 20 7.5v9L12 21 4 16.5v-9L12 3z"></path>
                    <path d="M12 12 20 7.5M12 12v9M12 12 4 7.5"></path>
                </svg>
                Operations
            </RouterLink>
            <span class="is-active">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M6 8h12l-1 11H7L6 8z"></path>
                    <path d="M9 8V7a3 3 0 0 1 6 0v1"></path>
                </svg>
                Sourcing
            </span>
            <RouterLink to="/price-research">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M5 19V11h3v8H5zM10.5 19V7h3v12h-3zM16 19v-6h3v6h-3z"></path>
                </svg>
                Insights
            </RouterLink>
            <RouterLink to="/maintenance">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 7h16M4 12h16M4 17h16"></path>
                    <circle cx="8" cy="7" r="1.7"></circle>
                    <circle cx="14" cy="12" r="1.7"></circle>
                    <circle cx="9" cy="17" r="1.7"></circle>
                </svg>
                System
            </RouterLink>
        </aside>
        <div class="po-beta__page">
            <p class="po-beta__crumbs">Purchase orders / {{ crumb }}</p>
            <slot />
        </div>
    </div>
</template>
