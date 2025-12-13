import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import ImportPage from './pages/ImportPage.vue';
import ProductsPage from './pages/ProductsPage.vue';
import MaintenancePage from './pages/MaintenancePage.vue';
import PriceResearchPage from './pages/PriceResearchPage.vue';

const routes: RouteRecordRaw[] = [
  { path: '/', redirect: '/import' },
  { path: '/import', name: 'import', component: ImportPage },
  { path: '/products', name: 'products', component: ProductsPage },
  { path: '/price-research', name: 'price-research', component: PriceResearchPage },
  { path: '/maintenance', name: 'maintenance', component: MaintenancePage },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});


