<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductInventoryImportRequest;
use App\Services\Products\Exceptions\InvalidProductImportFileException;
use App\Services\Products\ProductInventoryImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

final class ProductInventoryImportController extends Controller
{
    public function __construct(
        private readonly ProductInventoryImportService $importer,
    ) {}

    public function __invoke(ProductInventoryImportRequest $request): JsonResponse
    {
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return response()->json([
                'message' => 'No file uploaded.',
            ], 422);
        }

        try {
            $result = $this->importer->import($file);

            return response()->json($result);
        } catch (InvalidProductImportFileException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}


