<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SpecialOrderResource;
use App\Services\SpecialOrders\SpecialOrderShopifyInvoiceService;
use Illuminate\Http\JsonResponse;

final class SpecialOrderShopifyBalanceInvoiceClearController extends Controller
{
    public function __construct(
        private readonly SpecialOrderShopifyInvoiceService $invoices,
    ) {}

    public function __invoke(string $id): JsonResponse|SpecialOrderResource
    {
        try {
            $order = $this->invoices->clearBalanceInvoice($id);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return SpecialOrderResource::make($order);
    }
}
