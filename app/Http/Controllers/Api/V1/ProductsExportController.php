<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductsExportRequest;
use App\Services\Products\ProductExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProductsExportController extends Controller
{
    public function __construct(
        private readonly ProductExportService $exports,
    ) {}

    public function __invoke(ProductsExportRequest $request): StreamedResponse|JsonResponse
    {
        /** @var string $format */
        $format = $request->validated('format');

        /** @var string|null $sortBy */
        $sortBy = $request->validated('sort_by');

        /** @var string $sortDir */
        $sortDir = $request->validated('sort_dir') ?? 'asc';

        $filename = sprintf('products-%s-%s.csv', $format, now()->format('Y-m-d'));

        try {
            $exportedCount = 0;

            if ($format === 'shopify' || $format === 'shopify_no_inventory') {
                $usedHandles = [];
                $isNoInventory = $format === 'shopify_no_inventory';

                $products = $this->exports->listForExport(search: null, types: [], sortBy: $sortBy, sortDir: $sortDir);
                $tmp = fopen('php://temp', 'w+b');
                if ($tmp === false) {
                    throw new RuntimeException('Failed to create export stream.');
                }

                fputcsv($tmp, $isNoInventory ? $this->exports->shopifyHeaderNoInventory() : $this->exports->shopifyHeader());

                foreach ($products as $product) {
                    $selling = $product->sellingPrice?->selling_price;
                    $hasSellingPrice = $selling !== null && trim((string) $selling) !== '';
                    if (! $hasSellingPrice) {
                        continue;
                    }
                    $handle = $this->exports->shopifyHandleForProduct($product, $usedHandles);
                    fputcsv($tmp, $isNoInventory
                        ? $this->exports->shopifyRowNoInventory($product, $handle)
                        : $this->exports->shopifyRow($product, $handle));
                    $exportedCount++;
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
            }
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
