<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\ProductExportService;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProductsExportBarcodedController extends Controller
{
    public function __construct(
        private readonly ProductExportService $exports,
    ) {}

    public function __invoke(): StreamedResponse
    {
        $filename = sprintf('products-barcoded-%s.csv', now()->format('Y-m-d'));

        try {
            $tmp = fopen('php://temp', 'w+b');
            if ($tmp === false) {
                throw new RuntimeException('Failed to create export stream.');
            }

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

            $products = $this->exports->listBarcodedForExportSorted();
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
