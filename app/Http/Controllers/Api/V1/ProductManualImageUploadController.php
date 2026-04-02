<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductManualImageUploadRequest;
use App\Services\Products\ProductManualImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

final class ProductManualImageUploadController extends Controller
{
    public function __invoke(string $id, ProductManualImageUploadRequest $request, ProductManualImageUploadService $service): JsonResponse
    {
        /** @var array<int, UploadedFile> $files */
        $files = $request->file('files', []);

        $out = $service->upload($id, $files);

        return response()->json([
            'ok' => true,
            'data' => $out,
        ], 201);
    }
}
