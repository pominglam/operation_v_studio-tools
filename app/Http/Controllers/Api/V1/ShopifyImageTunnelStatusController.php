<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Shopify\CloudflaredTunnel;
use Illuminate\Http\JsonResponse;

final class ShopifyImageTunnelStatusController extends Controller
{
    public function __invoke(CloudflaredTunnel $tunnel): JsonResponse
    {
        return response()->json($tunnel->status());
    }
}


