<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\ShopifyContentExportService;
use App\Services\Shopify\CloudflaredTunnel;
use Illuminate\Http\JsonResponse;

final class ShopifyContentExportPrepareController extends Controller
{
    public function __construct(
        private readonly ShopifyContentExportService $exports,
        private readonly CloudflaredTunnel $tunnel,
    ) {}

    public function __invoke(): JsonResponse
    {
        $status = $this->tunnel->status();
        $tunnelUrl = $status['tunnel_url'] ?? null;
        $result = $this->exports->prepare(is_string($tunnelUrl) ? $tunnelUrl : null);

        return response()->json([
            ...$result,
            // Return a relative URL so the browser always downloads from the same origin/port
            // (APP_URL can be different in local/dev and would break downloads).
            'download_url' => "/api/v1/products/exports/shopify-content/download/{$result['export_id']}",
            'tunnel' => $status,
        ]);
    }
}


