<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\ManualUploadDeletionDeniedException;
use App\Services\Products\ProductManualImageDeleteService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

final class ProductExternalAssetDestroyController extends Controller
{
    public function __invoke(int $id, ProductManualImageDeleteService $service): JsonResponse
    {
        try {
            $service->delete($id);
        } catch (ModelNotFoundException) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        } catch (ManualUploadDeletionDeniedException) {
            return response()->json(['ok' => false, 'error' => 'manual_upload_only'], 403);
        }

        return response()->json(['ok' => true]);
    }
}
