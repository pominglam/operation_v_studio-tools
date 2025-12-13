<script setup lang="ts">
import { RouterView } from 'vue-router';
import AppNav from './AppNav.vue';
</script>

<template>
  <div class="min-h-screen bg-slate-50 text-slate-900">
    <AppNav />
    <main class="mx-auto w-full max-w-screen-2xl px-4 py-6">
      <RouterView />
    </main>
  </div>
</template>


