import { ref, type Ref } from 'vue';

/**
 * When true, the app shell uses a full-viewport red treatment for the employee
 * inventory scan flow (last barcode had no matching active product).
 */
export const employeeInventoryScanNotFoundBg: Ref<boolean> = ref(false);
