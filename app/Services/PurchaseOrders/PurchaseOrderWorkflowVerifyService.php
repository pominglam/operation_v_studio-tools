<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\Models\PurchaseOrder;

final class PurchaseOrderWorkflowVerifyService
{
    public function __construct(
        private readonly PurchaseOrderWorkflowVerificationService $verification,
        private readonly PurchaseOrderWorkflowChecklistService $checklist,
        private readonly PurchaseOrderProductScopeService $scope,
    ) {}

    /**
     * @return array{
     *   steps: array<string, array{done:bool, checked:bool, newly_checked:bool, detail?:string}>,
     *   purchase_order: PurchaseOrder
     * }
     */
    public function verifyAndAutoCheck(string $purchaseOrderUuid): array
    {
        $evaluation = $this->verification->evaluate($purchaseOrderUuid);
        $po = $this->scope->findPoOrFail($purchaseOrderUuid);
        $existing = is_array($po->workflow_checklist_json) ? $po->workflow_checklist_json : [];

        $changes = [];
        $steps = [];

        foreach ($evaluation as $key => $result) {
            $alreadyChecked = (bool) ($existing[$key] ?? false);
            $done = (bool) ($result['done'] ?? false);
            $newlyChecked = false;

            if ($done && ! $alreadyChecked && $this->isAutoCheckable($key)) {
                $changes[$key] = true;
                $newlyChecked = true;
                $alreadyChecked = true;
            }

            $step = [
                'done' => $done,
                'checked' => $alreadyChecked,
                'newly_checked' => $newlyChecked,
            ];
            if (isset($result['detail']) && is_string($result['detail']) && $result['detail'] !== '') {
                $step['detail'] = $result['detail'];
            }
            $steps[$key] = $step;
        }

        if ($changes !== []) {
            $po = $this->checklist->update($purchaseOrderUuid, $changes);
        }

        return [
            'steps' => $steps,
            'purchase_order' => $po,
        ];
    }

    private function isAutoCheckable(string $key): bool
    {
        return ! in_array($key, [
            'select_and_arrange_product_images',
            'import_product_available_quantity',
            'update_product_available_with_shopify_current_inventory_quantity',
        ], true);
    }
}
