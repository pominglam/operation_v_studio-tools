<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderWorkflowPrepareInventoryException;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowCrawlNewProductsService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowExportShopifyContentService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowMarkLatestArrivalPublishedService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowPrepareInventoryService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowPullHandlesService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowSetPricesService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowVerifyService;
use Illuminate\Http\JsonResponse;

final class PurchaseOrderWorkflowActionController extends Controller
{
    public function previewSetPrices(
        string $id,
        PurchaseOrderWorkflowSetPricesService $setPrices,
    ): JsonResponse {
        return response()->json([
            'ok' => true,
            'data' => $setPrices->preview($id),
        ]);
    }

    public function previewExportShopifyContent(
        string $id,
        PurchaseOrderWorkflowExportShopifyContentService $exportShopify,
    ): JsonResponse {
        return response()->json([
            'ok' => true,
            'data' => $exportShopify->preview($id),
        ]);
    }

    public function pushExportShopifyContent(
        string $id,
        PurchaseOrderWorkflowExportShopifyContentService $exportShopify,
        PurchaseOrderWorkflowVerifyService $verify,
    ): JsonResponse {
        $summary = $exportShopify->push($id);
        $verification = $verify->verifyAndAutoCheck($id);

        return response()->json([
            'ok' => true,
            'data' => [
                'summary' => $summary,
                'steps' => $verification['steps'],
                'purchase_order' => PurchaseOrderResource::make($verification['purchase_order']),
            ],
        ]);
    }

    public function setPrices(
        string $id,
        PurchaseOrderWorkflowSetPricesService $setPrices,
        PurchaseOrderWorkflowVerifyService $verify,
    ): JsonResponse {
        $summary = $setPrices->apply($id);
        $verification = $verify->verifyAndAutoCheck($id);

        return response()->json([
            'ok' => true,
            'data' => [
                'summary' => $summary,
                'steps' => $verification['steps'],
                'purchase_order' => PurchaseOrderResource::make($verification['purchase_order']),
            ],
        ]);
    }

    public function previewPullHandles(
        string $id,
        PurchaseOrderWorkflowPullHandlesService $pullHandles,
    ): JsonResponse {
        return response()->json([
            'ok' => true,
            'data' => $pullHandles->preview($id),
        ]);
    }

    public function pullHandles(
        string $id,
        PurchaseOrderWorkflowPullHandlesService $pullHandles,
        PurchaseOrderWorkflowVerifyService $verify,
    ): JsonResponse {
        $summary = $pullHandles->pullHandles($id);
        $verification = $verify->verifyAndAutoCheck($id);

        return response()->json([
            'ok' => true,
            'data' => [
                'summary' => $summary,
                'steps' => $verification['steps'],
                'purchase_order' => PurchaseOrderResource::make($verification['purchase_order']),
            ],
        ]);
    }

    public function prepareInventory(
        string $id,
        PurchaseOrderWorkflowPrepareInventoryService $prepare,
    ): JsonResponse {
        try {
            $summary = $prepare->prepare($id);
        } catch (PurchaseOrderWorkflowPrepareInventoryException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'issues' => $e->issues(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'data' => $summary,
        ]);
    }

    public function markLatestArrivalPublished(
        string $id,
        PurchaseOrderWorkflowMarkLatestArrivalPublishedService $mark,
        PurchaseOrderWorkflowVerifyService $verify,
    ): JsonResponse {
        $summary = $mark->markForPo($id);
        $verification = $verify->verifyAndAutoCheck($id);

        return response()->json([
            'ok' => true,
            'data' => [
                'summary' => $summary,
                'steps' => $verification['steps'],
                'purchase_order' => PurchaseOrderResource::make($verification['purchase_order']),
            ],
        ]);
    }

    public function crawlNewProducts(
        string $id,
        PurchaseOrderWorkflowCrawlNewProductsService $crawl,
    ): JsonResponse {
        $summary = $crawl->crawlNewProducts($id);

        return response()->json([
            'ok' => true,
            'data' => $summary,
        ], 202);
    }
}
