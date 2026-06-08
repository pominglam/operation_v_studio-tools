import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import ProductsPage from './pages/ProductsPage.vue';
import MaintenancePage from './pages/MaintenancePage.vue';
import ShopifyWebhookLogsPage from './pages/ShopifyWebhookLogsPage.vue';
import PriceResearchPage from './pages/PriceResearchPage.vue';
import PriceResearchRunLogsPage from './pages/PriceResearchRunLogsPage.vue';
import PriceResearchReportsPage from './pages/PriceResearchReportsPage.vue';
import SyncProgressPage from './pages/SyncProgressPage.vue';
import InventoryCheckPage from './pages/InventoryCheckPage.vue';
import InventoryCheckDetailPage from './pages/InventoryCheckDetailPage.vue';
import PurchaseOrdersPage from './pages/PurchaseOrdersPage.vue';
import PurchaseOrderDetailPage from './pages/PurchaseOrderDetailPage.vue';
import TcgEventsPage from './pages/TcgEventsPage.vue';
import PreordersPage from './pages/PreordersPage.vue';
import EmployeeInventoryCountPage from './pages/EmployeeInventoryCountPage.vue';
import { currentAccessRole } from './lib/accessRole';

const accessRole = currentAccessRole();
const isEmployee = accessRole === 'employee';

const routes: RouteRecordRaw[] = [
    { path: '/', redirect: isEmployee ? '/employee/inventory-count' : '/products' },
    { path: '/import', redirect: { path: '/products', hash: '#import' } },
    {
        path: '/employee/inventory-count',
        name: 'employee-inventory-count',
        component: EmployeeInventoryCountPage,
    },
    { path: '/products', name: 'products', component: ProductsPage },
    { path: '/purchase-orders', name: 'purchase-orders', component: PurchaseOrdersPage },
    {
        path: '/purchase-orders/:id',
        name: 'purchase-order-detail',
        component: PurchaseOrderDetailPage,
    },
    { path: '/inventory-check', name: 'inventory-check', component: InventoryCheckPage },
    {
        path: '/inventory-check/:id',
        name: 'inventory-check-detail',
        component: InventoryCheckDetailPage,
    },
    { path: '/price-research', name: 'price-research', component: PriceResearchPage },
    {
        path: '/price-research/reports',
        name: 'price-research-reports',
        component: PriceResearchReportsPage,
    },
    {
        path: '/price-research/runs/:id/logs',
        name: 'price-research-run-logs',
        component: PriceResearchRunLogsPage,
    },
    { path: '/sync-progress', name: 'sync-progress', component: SyncProgressPage },
    { path: '/tcg-events', name: 'tcg-events', component: TcgEventsPage },
    { path: '/preorders', name: 'preorders', component: PreordersPage },
    { path: '/maintenance', name: 'maintenance', component: MaintenancePage },
    { path: '/shopify/webhooks', name: 'shopify-webhooks', component: ShopifyWebhookLogsPage },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach((to) => {
    if (!isEmployee) return true;
    if (to.path === '/employee/inventory-count') return true;

    return '/employee/inventory-count';
});
