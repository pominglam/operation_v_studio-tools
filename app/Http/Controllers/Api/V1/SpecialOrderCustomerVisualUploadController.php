<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SpecialOrderVisualUploadRequest;
use App\Http\Resources\Api\V1\SpecialOrderResource;
use App\Services\SpecialOrders\SpecialOrderVisualUploadService;
use Illuminate\Http\UploadedFile;

final class SpecialOrderCustomerVisualUploadController extends Controller
{
    public function __construct(
        private readonly SpecialOrderVisualUploadService $uploads,
    ) {}

    public function __invoke(string $id, SpecialOrderVisualUploadRequest $request): SpecialOrderResource
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $order = $this->uploads->upload($id, SpecialOrderVisualUploadService::KIND_CUSTOMER, $file);

        return SpecialOrderResource::make($order);
    }
}
