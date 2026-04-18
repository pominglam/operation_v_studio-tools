<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PurchaseOrders\PurchaseOrderQueryService;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PurchaseOrderDraftLinesExportController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderQueryService $purchaseOrders,
    ) {}

    public function __invoke(string $id): StreamedResponse
    {
        $po = $this->purchaseOrders->findByUuidOrFail($id);
        $po->loadMissing('items.product');

        $filename = sprintf('purchase-order-%s-lines.csv', $po->uuid);

        return response()->streamDownload(function () use ($po): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['SKU', 'Product Name', 'Barcode', 'Qty Ordered']);

            foreach ($po->items as $item) {
                fputcsv($out, [
                    (string) $item->sku,
                    (string) ($item->product?->description ?? ''),
                    (string) ($item->product?->barcode ?? ''),
                    (string) ($item->qty_ordered ?? 0),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
