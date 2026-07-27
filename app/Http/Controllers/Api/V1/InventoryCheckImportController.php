<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InventoryCheckImportRequest;
use App\Services\Products\Exceptions\InvalidProductImportFileException;
use App\Services\Products\InventoryCheckImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

final class InventoryCheckImportController extends Controller
{
    public function __construct(
        private readonly InventoryCheckImportService $importer,
    ) {}

    public function __invoke(InventoryCheckImportRequest $request): JsonResponse
    {
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return response()->json([
                'message' => 'No file uploaded.',
            ], 422);
        }

        try {
            $notes = $request->validated('notes');
            $notes = is_string($notes) || $notes === null ? $notes : null;
            $result = $this->importer->import($file, $notes);

            return response()->json($result);
        } catch (InvalidProductImportFileException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
