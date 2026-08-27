<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CustomAsiaOrderVisualUploadRequest;
use App\Http\Resources\Api\V1\CustomAsiaOrderResource;
use App\Services\CustomOrders\CustomAsiaOrderVisualUploadService;
use Illuminate\Http\UploadedFile;

final class CustomAsiaOrderCustomerVisualUploadController extends Controller
{
    public function __construct(
        private readonly CustomAsiaOrderVisualUploadService $uploads,
    ) {}

    public function __invoke(string $id, CustomAsiaOrderVisualUploadRequest $request): CustomAsiaOrderResource
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $order = $this->uploads->upload($id, CustomAsiaOrderVisualUploadService::KIND_CUSTOMER, $file);

        return CustomAsiaOrderResource::make($order);
    }
}
