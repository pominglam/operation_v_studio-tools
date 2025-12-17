<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductsExportRequest;
use App\Models\Product;
use App\Services\Products\ProductExportService;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProductsExportController extends Controller
{
    public function __construct(
        private readonly ProductExportService $exports,
    ) {}

    public function __invoke(ProductsExportRequest $request): StreamedResponse
    {
        /** @var string $format */
        $format = $request->validated('format');

        /** @var string|null $sortBy */
        $sortBy = $request->validated('sort_by');

        /** @var string $sortDir */
        $sortDir = $request->validated('sort_dir') ?? 'asc';

        $filename = sprintf('products-%s-%s.csv', $format, now()->format('Y-m-d'));

        try {
            $tmp = fopen('php://temp', 'w+b');
            if ($tmp === false) {
                throw new RuntimeException('Failed to create export stream.');
            }

            $exportedCount = 0;

            if ($format === 'shopify') {
                fputcsv($tmp, $this->exports->shopifyHeader());
                $usedHandles = [];

                // Export must be 100% of products (no filters).
                $products = $this->exports->listForExport(search: null, types: [], sortBy: $sortBy, sortDir: $sortDir);
                foreach ($products as $product) {
                    $handle = $this->exports->shopifyHandleForProduct($product, $usedHandles);
                    fputcsv($tmp, $this->exports->shopifyRow($product, $handle));
                    $exportedCount++;
                }
            }

            $totalProducts = Product::query()->count();
            if ($exportedCount !== $totalProducts) {
                throw new RuntimeException("Export validation failed: exported {$exportedCount} rows but expected {$totalProducts}.");
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
            ])->setStatusCode(Response::HTTP_OK);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
