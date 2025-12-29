<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\InventoryCheckQueryService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InventoryCheckDownloadController extends Controller
{
    public function __construct(
        private readonly InventoryCheckQueryService $inventoryChecks,
    ) {}

    public function __invoke(string $id): StreamedResponse
    {
        $check = $this->inventoryChecks->findByUuidOrFail($id);
        $path = (string) ($check->uploaded_file_path ?? '');
        if (trim($path) === '' || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $filename = sprintf('inventory-check-%s.csv', $check->uuid);

        return Storage::disk('local')->download($path, $filename);
    }
}




