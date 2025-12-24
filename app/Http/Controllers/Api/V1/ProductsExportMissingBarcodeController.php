<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductsExportRequest;
use App\Services\Products\ProductExportService;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProductsExportMissingBarcodeController extends Controller
{
    public function __construct(
        private readonly ProductExportService $exports,
    ) {}

    public function __invoke(ProductsExportRequest $request): StreamedResponse
    {
        /** @var string|null $sortBy */
        $sortBy = $request->validated('sort_by');

        /** @var string $sortDir */
        $sortDir = $request->validated('sort_dir') ?? 'asc';

        $filename = sprintf('products-missing-barcode-%s.csv', now()->format('Y-m-d'));

        try {
            $tmp = fopen('php://temp', 'w+b');
            if ($tmp === false) {
                throw new RuntimeException('Failed to create export stream.');
            }

            fputcsv($tmp, ['Variant SKU', 'Title', 'Variant Barcode']);

            $products = $this->exports->listMissingBarcodeForExport($sortBy, $sortDir);
            foreach ($products as $p) {
                fputcsv($tmp, [(string) $p->sku, (string) $p->description, (string) ($p->barcode ?? '')]);
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


