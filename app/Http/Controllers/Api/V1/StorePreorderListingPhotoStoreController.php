<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePreorderListingPhotoStoreRequest;
use App\Services\StorePreorders\Listing\StorePreorderListingPhotoStagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

final class StorePreorderListingPhotoStoreController extends Controller
{
    public function __construct(
        private readonly StorePreorderListingPhotoStagingService $photos,
    ) {}

    public function __invoke(StorePreorderListingPhotoStoreRequest $request): JsonResponse
    {
        $files = $request->file('files', []);
        if (! is_array($files)) {
            $files = [];
        }

        $images = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $images[] = $this->photos->storeUpload($file);
            }
        }

        return response()->json(['data' => ['images' => $images]], 201);
    }
}
