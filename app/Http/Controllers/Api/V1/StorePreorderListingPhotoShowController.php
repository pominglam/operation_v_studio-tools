<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\StorePreorders\Listing\StorePreorderListingPhotoStagingService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class StorePreorderListingPhotoShowController extends Controller
{
    public function __construct(
        private readonly StorePreorderListingPhotoStagingService $photos,
    ) {}

    public function __invoke(string $id): BinaryFileResponse|Response
    {
        $staged = $this->photos->read($id);
        if ($staged === null) {
            abort(404);
        }

        return response()->file($staged['path'], [
            'Content-Type' => $staged['mime'],
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
