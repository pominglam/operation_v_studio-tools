<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\ProductReplenishmentService;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProductReplenishmentExportController extends Controller
{
    public function __invoke(ProductReplenishmentService $service): StreamedResponse
    {
        $tmp = fopen('php://temp', 'w+b');
        if ($tmp === false) {
            throw new RuntimeException('Failed to create export stream.');
        }

        fwrite($tmp, "\xEF\xBB\xBF");
        fputcsv($tmp, [
            'SKU',
            'Product Name',
            'Barcode',
            'Available Qty',
            'Maintain Qty',
            'Inbound Qty (Open POs)',
            'Suggested Order Qty',
        ]);

        $rows = $service->previewRows();
        foreach ($rows as $row) {
            fputcsv($tmp, [
                (string) $row['sku'],
                (string) $row['product_name'],
                (string) ($row['barcode'] ?? ''),
                (string) $row['available_qty'],
                (string) $row['maintain_qty'],
                (string) $row['inbound_open_po_qty'],
                (string) $row['suggested_order_qty'],
            ]);
        }

        rewind($tmp);
        $filename = sprintf('products-replenishment-%s.csv', now()->format('Y-m-d'));

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
            'X-Exported-Rows' => (string) $rows->count(),
        ])->setStatusCode(Response::HTTP_OK);
    }
}
