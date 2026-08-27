<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductTaxonomyVerificationIndexRequest;
use App\Services\Products\ProductTaxonomyConfirmationExportService;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class ProductTaxonomyConfirmationExportController extends Controller
{
    public function __construct(
        private readonly ProductTaxonomyConfirmationExportService $export,
    ) {}

    public function __invoke(ProductTaxonomyVerificationIndexRequest $request): Response
    {
        $filename = sprintf('taxonomy-confirmation-%s.csv', now()->format('Y-m-d'));
        $tmp = fopen('php://temp', 'w+b');
        if ($tmp === false) {
            throw new RuntimeException('Failed to create taxonomy confirmation export stream.');
        }

        fwrite($tmp, "\xEF\xBB\xBF");
        fputcsv($tmp, $this->export->header());

        $exportedCount = 0;
        foreach ($this->export->rows($request->reviewFilters()) as $row) {
            fputcsv($tmp, $row);
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
}
