<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SpecialOrderCashReceivedUpdateRequest;
use App\Http\Resources\Api\V1\SpecialOrderResource;
use App\Services\SpecialOrders\SpecialOrderCashPaymentService;
use Illuminate\Http\JsonResponse;

final class SpecialOrderCashReceivedUpdateController extends Controller
{
    public function __construct(
        private readonly SpecialOrderCashPaymentService $cashPayments,
    ) {}

    public function __invoke(string $id, SpecialOrderCashReceivedUpdateRequest $request): SpecialOrderResource|JsonResponse
    {
        try {
            $order = $this->cashPayments->recordCashReceived(
                $id,
                (string) $request->validated('cash_received_cad'),
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return SpecialOrderResource::make($order);
    }
}
