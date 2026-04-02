<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductHandleImportRequest;
use App\Services\Products\Exceptions\InvalidProductImportFileException;
use App\Services\Products\ProductHandleImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

final class ProductHandleImportController extends Controller
{
    public function __construct(
        private readonly ProductHandleImportService $importer,
    ) {}

    public function __invoke(ProductHandleImportRequest $request): JsonResponse
    {
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return response()->json(['message' => 'No file uploaded.'], 422);
        }

        try {
            /** @var string|null $poUuid */
            $poUuid = $request->validated('purchase_order_uuid');
            $poUuid = is_string($poUuid) ? trim($poUuid) : null;
            $poUuid = $poUuid !== '' ? $poUuid : null;

            return response()->json($this->importer->import($file, $poUuid));
        } catch (InvalidProductImportFileException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}


