<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\PurchaseOrders\PurchaseOrderCombinedPaymentInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrderCombinedPaymentRequest;
use App\Http\Resources\Api\V1\PurchaseOrderCombinedPaymentResource;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderCombinedPaymentException;
use App\Services\PurchaseOrders\PurchaseOrderCombinedPaymentService;
use Illuminate\Http\JsonResponse;

final class PurchaseOrderCombinedPaymentCreateController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderCombinedPaymentService $combinedPayments,
    ) {}

    public function __invoke(PurchaseOrderCombinedPaymentRequest $request): JsonResponse
    {
        try {
            /** @var array{
             *   purchase_order_ids: array<int, string>,
             *   total_paid_cad: int|float|string,
             *   includes_shipping: bool
             * } $validated
             */
            $validated = $request->validated();
            $payment = $this->combinedPayments->create(
                PurchaseOrderCombinedPaymentInput::fromValidated($validated),
            );

            return PurchaseOrderCombinedPaymentResource::make($payment)
                ->response()
                ->setStatusCode(201);
        } catch (PurchaseOrderCombinedPaymentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
