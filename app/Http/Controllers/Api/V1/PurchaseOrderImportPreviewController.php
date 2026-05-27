<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrderImportRequest;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderImportException;
use App\Services\PurchaseOrders\PurchaseOrderImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

final class PurchaseOrderImportPreviewController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderImportService $importer,
    ) {}

    public function __invoke(PurchaseOrderImportRequest $request): JsonResponse
    {
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return response()->json([
                'message' => 'No file uploaded.',
            ], 422);
        }

        /** @var array<string, mixed> $v */
        $v = $request->validated();

        try {
            $meta = [
                'vendor' => (string) $v['vendor'],
            ];
            if (array_key_exists('product_total', $v)) {
                $meta['product_total'] = $v['product_total'] !== null ? (string) $v['product_total'] : null;
            }
            if (array_key_exists('product_total_includes_fees', $v)) {
                $meta['product_total_includes_fees'] = (bool) $v['product_total_includes_fees'];
            }
            if (array_key_exists('shipping_total', $v)) {
                $meta['shipping_total'] = $v['shipping_total'] !== null ? (string) $v['shipping_total'] : null;
            }
            if (array_key_exists('shipping_currency_mode', $v)) {
                $meta['shipping_currency_mode'] = (string) $v['shipping_currency_mode'];
            }

            /** @var array{
             *   vendor:string,
             *   product_total?:string|null,
             *   shipping_total?:string|null,
             *   shipping_currency_mode?:string
             * } $meta */
            $result = $this->importer->preview($file, $meta);

            return response()->json($result);
        } catch (PurchaseOrderImportException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'issues' => $e->issues(),
            ], 422);
        }
    }
}
