<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Shopify\ShopifyAdminConfigurationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrderPrepareInventoryRequest;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Services\Products\ClearStaleLatestArrivalService;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderWorkflowPrepareInventoryException;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderWorkflowPushInventoryException;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowCrawlNewProductsService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowExportShopifyContentService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowMarkLatestArrivalPublishedService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowMarkLatestArrivalService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowMarkPublishedOnShopifyService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowPrepareInventoryService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowPullHandlesService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowPushInventoryService;
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

    public function previewPushInventory(
        string $id,
        PurchaseOrderWorkflowPushInventoryService $pushInventory,
    ): JsonResponse {
        try {
            $data = $pushInventory->preview($id);
        } catch (ShopifyAdminConfigurationException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'data' => $data,
        ]);
    }

    public function pushInventory(
        string $id,
        PurchaseOrderWorkflowPushInventoryService $pushInventory,
        PurchaseOrderWorkflowVerifyService $verify,
    ): JsonResponse {
        try {
            $summary = $pushInventory->push($id);
        } catch (PurchaseOrderWorkflowPushInventoryException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'issues' => $e->issues(),
            ], 422);
        } catch (ShopifyAdminConfigurationException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

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
        PurchaseOrderPrepareInventoryRequest $request,
        PurchaseOrderWorkflowPrepareInventoryService $prepare,
    ): JsonResponse {
        try {
            $summary = $prepare->prepare($id, $request->pullShopify());
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

    public function markPublishedOnShopify(
        string $id,
        PurchaseOrderWorkflowMarkPublishedOnShopifyService $mark,
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

    public function markLatestArrival(
        string $id,
        PurchaseOrderWorkflowMarkLatestArrivalService $mark,
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
                'updated' => $summary['updated'],
                'steps' => $verification['steps'],
                'purchase_order' => PurchaseOrderResource::make($verification['purchase_order']),
            ],
        ]);
    }

    public function clearStaleLatestArrival(ClearStaleLatestArrivalService $clear): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => $clear->clear(),
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
