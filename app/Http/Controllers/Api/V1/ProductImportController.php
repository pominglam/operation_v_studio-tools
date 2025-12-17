<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductImportRequest;
use App\Services\Products\Exceptions\InvalidProductImportFileException;
use App\Services\Products\ProductImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

final class ProductImportController extends Controller
{
    public function __construct(
        private readonly ProductImportService $importer,
    ) {}

    public function __invoke(ProductImportRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            if (! $file instanceof UploadedFile) {
                return response()->json([
                    'message' => 'No file uploaded.',
                ], 422);
            }

            /** @var string $format */
            $format = $request->validated('format') ?? 'plamod';

            $count = $this->importer->import($file, $format);

            return response()->json([
                'imported' => $count,
            ]);
        } catch (InvalidProductImportFileException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
