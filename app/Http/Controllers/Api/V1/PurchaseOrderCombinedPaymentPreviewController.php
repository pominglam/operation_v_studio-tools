<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\PurchaseOrders\PurchaseOrderCombinedPaymentInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrderCombinedPaymentRequest;
use App\Http\Resources\Api\V1\PurchaseOrderCombinedPaymentResource;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderCombinedPaymentException;
use App\Services\PurchaseOrders\PurchaseOrderCombinedPaymentPreviewService;
use Illuminate\Http\JsonResponse;

final class PurchaseOrderCombinedPaymentPreviewController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderCombinedPaymentPreviewService $previewer,
    ) {}

    public function __invoke(
        PurchaseOrderCombinedPaymentRequest $request,
    ): PurchaseOrderCombinedPaymentResource|JsonResponse {
        try {
            /** @var array{
             *   purchase_order_ids: array<int, string>,
             *   total_paid_cad: int|float|string,
             *   includes_shipping: bool
             * } $validated
             */
            $validated = $request->validated();
            $preview = $this->previewer->preview(
                PurchaseOrderCombinedPaymentInput::fromValidated($validated),
            );

            return PurchaseOrderCombinedPaymentResource::make($preview);
        } catch (PurchaseOrderCombinedPaymentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
