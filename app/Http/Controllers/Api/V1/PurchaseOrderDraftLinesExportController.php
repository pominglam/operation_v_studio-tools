<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PurchaseOrders\PurchaseOrderLinesExportService;
use App\Services\PurchaseOrders\PurchaseOrderQueryService;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PurchaseOrderDraftLinesExportController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderQueryService $purchaseOrders,
        private readonly PurchaseOrderLinesExportService $export,
    ) {}

    public function __invoke(string $id): StreamedResponse
    {
        $po = $this->purchaseOrders->findByUuidOrFail($id);
        $po->loadMissing('items.product');

        $filename = $this->export->suggestedFilename($po);
        $headers = $this->export->csvHeaders($po);

        $export = $this->export;

        return response()->streamDownload(function () use ($po, $headers, $export): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            foreach ($po->items as $item) {
                $row = $export->csvRow($po, $item);
                if ($row === null) {
                    continue;
                }
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
