<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShopifyContentExportPrepareRequest;
use App\Services\Products\ShopifyContentExportService;
use App\Services\Shopify\CloudflaredTunnel;
use Illuminate\Http\JsonResponse;

final class ShopifyContentNoInventoryExportPrepareController extends Controller
{
    public function __construct(
        private readonly ShopifyContentExportService $exports,
        private readonly CloudflaredTunnel $tunnel,
    ) {}

    public function __invoke(ShopifyContentExportPrepareRequest $request): JsonResponse
    {
        $status = $this->tunnel->status();
        $tunnelUrl = $status['tunnel_url'] ?? null;

        /** @var array<int, string> $ids */
        $ids = $request->validated('ids') ?? [];

        $result = $this->exports->prepareNoInventory(
            tunnelBaseUrl: is_string($tunnelUrl) ? $tunnelUrl : null,
            productUuids: $ids,
        );

        return response()->json([
            ...$result,
            // Return a relative URL so the browser always downloads from the same origin/port
            // (APP_URL can be different in local/dev and would break downloads).
            'download_url' => "/api/v1/products/exports/shopify-content/download/{$result['export_id']}",
            'tunnel' => $status,
        ]);
    }
}
