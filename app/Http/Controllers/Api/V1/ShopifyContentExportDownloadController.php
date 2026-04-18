<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ShopifyContentExportDownloadController extends Controller
{
    public function __invoke(string $exportId): StreamedResponse
    {
        $exportId = trim($exportId);
        if ($exportId === '') {
            abort(404);
        }

        $path = "exports/shopify_content/{$exportId}.csv";
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            abort(404);
        }

        $filename = sprintf('shopify-products-with-content-%s.csv', now()->format('Y-m-d'));

        return $disk->download($path, $filename);
    }
}
