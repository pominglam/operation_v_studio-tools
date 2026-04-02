<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductsExportSelectedRequest;
use App\Services\Products\ProductExportService;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProductsExportSelectedController extends Controller
{
    public function __construct(
        private readonly ProductExportService $exports,
    ) {}

    public function __invoke(ProductsExportSelectedRequest $request): StreamedResponse
    {
        /** @var string $exportType */
        $exportType = $request->validated('export_type');

        /** @var array<int, string> $uuids */
        $uuids = $request->validated('ids');

        /** @var string|null $sortBy */
        $sortBy = $request->validated('sort_by');

        /** @var string $sortDir */
        $sortDir = $request->validated('sort_dir') ?? 'asc';

        $filename = sprintf('products-selected-%s-%s.csv', $exportType, now()->format('Y-m-d'));

        try {
            $tmp = fopen('php://temp', 'w+b');
            if ($tmp === false) {
                throw new RuntimeException('Failed to create export stream.');
            }

            $exportedCount = 0;
            $includeMissingSellingPrice = (bool) $request->validated('include_missing_selling_price', false);

            if ($exportType === 'shopify' || $exportType === 'shopify_no_inventory') {
                $usedHandles = [];
                $products = $this->exports->listSelectedForShopifyExport($uuids, $sortBy, $sortDir);
                $isNoInventory = $exportType === 'shopify_no_inventory';
                fputcsv($tmp, $isNoInventory ? $this->exports->shopifyHeaderNoInventory() : $this->exports->shopifyHeader());

                foreach ($products as $product) {
                    $selling = $product->sellingPrice?->selling_price;
                    $hasSellingPrice = $selling !== null && trim((string) $selling) !== '';
                    if (! $hasSellingPrice && ! $includeMissingSellingPrice) {
                        continue;
                    }
                    $handle = $this->exports->shopifyHandleForProduct($product, $usedHandles);
                    fputcsv($tmp, $isNoInventory
                        ? $this->exports->shopifyRowNoInventory($product, $handle)
                        : $this->exports->shopifyRow($product, $handle));
                    $exportedCount++;
                }
            } elseif ($exportType === 'missing_barcode') {
                $products = $this->exports->listSelectedMissingBarcodeForExport($uuids, $sortBy, $sortDir);
                fputcsv($tmp, ['Variant SKU', 'Title', 'Variant Barcode']);
                foreach ($products as $p) {
                    fputcsv($tmp, [(string) $p->sku, (string) $p->description, (string) ($p->barcode ?? '')]);
                    $exportedCount++;
                }
            } elseif ($exportType === 'barcoded') {
                // UTF-8 BOM for Excel (so Chinese renders correctly).
                fwrite($tmp, "\xEF\xBB\xBF");
                fputcsv($tmp, [
                    'Handle',
                    'Vendor',
                    'SKU',
                    'Barcode',
                    'Type',
                    'Product Name',
                    'English name',
                    'Available amount',
                    'Selling price',
                    'Quantity in store',
                    'Difference',
                    'Notes',
                ]);

                $products = $this->exports->listSelectedBarcodedForExportSorted($uuids);
                foreach ($products as $p) {
                    $selling = $p->sellingPrice?->selling_price;
                    fputcsv($tmp, [
                        (string) ($p->handle ?? ''),
                        (string) ($p->vendor ?? ''),
                        (string) $p->sku,
                        (string) ($p->barcode ?? ''),
                        (string) ($p->type ?? ''),
                        (string) $p->description,
                        '',
                        $p->available_qty !== null ? (string) max(0, (int) $p->available_qty) : '',
                        $selling !== null ? (string) $selling : '',
                        '',
                        '',
                        '',
                    ]);
                    $exportedCount++;
                }
            }

            rewind($tmp);

            return response()->streamDownload(function () use ($tmp): void {
                $out = fopen('php://output', 'wb');
                if ($out === false) {
                    return;
                }

                stream_copy_to_stream($tmp, $out);

                fclose($out);
                fclose($tmp);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'X-Exported-Rows' => (string) $exportedCount,
            ])->setStatusCode(Response::HTTP_OK);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
