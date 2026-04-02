<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductInventoryQuantityOverrideImportRequest;
use App\Services\Products\Exceptions\InvalidProductImportFileException;
use App\Services\Products\ProductInventoryQuantityOverrideImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

final class ProductInventoryQuantityOverrideImportController extends Controller
{
    public function __construct(
        private readonly ProductInventoryQuantityOverrideImportService $importer,
    ) {}

    public function __invoke(ProductInventoryQuantityOverrideImportRequest $request): JsonResponse
    {
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return response()->json([
                'message' => 'No file uploaded.',
            ], 422);
        }

        try {
            /** @var string|null $poUuid */
            $poUuid = $request->validated('purchase_order_uuid');
            $poUuid = is_string($poUuid) ? trim($poUuid) : null;
            $poUuid = $poUuid !== '' ? $poUuid : null;

            $force = (bool) $request->validated('force', false);
            /** @var string $missingMode */
            $missingMode = (string) $request->validated('missing_products_mode', 'set_zero');
            $missingMode = trim($missingMode) !== '' ? trim($missingMode) : 'set_zero';

            $result = $this->importer->import($file, $poUuid, $force, $missingMode);

            $blocked = (bool) ($result['blocked'] ?? false);
            if ($blocked) {
                return response()->json([
                    'message' => 'Some items were not found in the database. No quantities were changed.',
                    ...$result,
                ], 422);
            }

            return response()->json($result);
        } catch (InvalidProductImportFileException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
