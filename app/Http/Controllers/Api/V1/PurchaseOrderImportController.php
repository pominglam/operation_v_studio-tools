<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrderImportRequest;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderImportException;
use App\Services\PurchaseOrders\PurchaseOrderImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

final class PurchaseOrderImportController extends Controller
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
            if (array_key_exists('purchase_order_uuid', $v)) {
                $meta['purchase_order_uuid'] = (string) $v['purchase_order_uuid'];
            }
            if (array_key_exists('import_mode', $v)) {
                $meta['import_mode'] = (string) $v['import_mode'];
            }
            if (array_key_exists('ordered_date', $v)) {
                $meta['ordered_date'] = $v['ordered_date'] !== null ? (string) $v['ordered_date'] : null;
            }
            if (array_key_exists('shipped_date', $v)) {
                $meta['shipped_date'] = $v['shipped_date'] !== null ? (string) $v['shipped_date'] : null;
            }
            if (array_key_exists('estimated_arrival_date', $v)) {
                $meta['estimated_arrival_date'] = $v['estimated_arrival_date'] !== null ? (string) $v['estimated_arrival_date'] : null;
            }
            if (array_key_exists('received_date', $v)) {
                $meta['received_date'] = $v['received_date'] !== null ? (string) $v['received_date'] : null;
            }
            if (array_key_exists('fully_on_shelves_date', $v)) {
                $meta['fully_on_shelves_date'] = $v['fully_on_shelves_date'] !== null ? (string) $v['fully_on_shelves_date'] : null;
            }
            if (array_key_exists('shipping_total', $v)) {
                $meta['shipping_total'] = $v['shipping_total'] !== null ? (string) $v['shipping_total'] : null;
            }
            if (array_key_exists('shipping_currency_mode', $v)) {
                $meta['shipping_currency_mode'] = (string) $v['shipping_currency_mode'];
            }
            if (array_key_exists('product_total', $v)) {
                $meta['product_total'] = $v['product_total'] !== null ? (string) $v['product_total'] : null;
            }
            if (array_key_exists('surcharge_total', $v)) {
                $meta['surcharge_total'] = $v['surcharge_total'] !== null ? (string) $v['surcharge_total'] : null;
            }
            if (array_key_exists('notes', $v)) {
                $meta['notes'] = $v['notes'] !== null ? (string) $v['notes'] : null;
            }
            if (array_key_exists('reset_receipt_before_reimport', $v)) {
                $meta['reset_receipt_before_reimport'] = (bool) $v['reset_receipt_before_reimport'];
            }

            /** @var array{
             *   vendor:string,
             *   purchase_order_uuid?:string,
             *   import_mode?:string,
             *   ordered_date?:string|null,
             *   shipped_date?:string|null,
             *   estimated_arrival_date?:string|null,
             *   received_date?:string|null,
             *   fully_on_shelves_date?:string|null,
             *   shipping_total?:string|null,
             *   shipping_currency_mode?:string,
             *   product_total?:string|null,
             *   surcharge_total?:string|null,
             *   notes?:string|null,
             *   reset_receipt_before_reimport?:bool
             * } $meta */
            $result = $this->importer->import($file, $meta);

            return response()->json($result);
        } catch (PurchaseOrderImportException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'issues' => $e->issues(),
            ], 422);
        }
    }
}
