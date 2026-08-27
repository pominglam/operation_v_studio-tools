<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CustomAsiaOrderResource;
use App\Services\CustomOrders\CustomAsiaOrderVisualUploadService;

final class CustomAsiaOrderVisualDeleteController extends Controller
{
    public function __construct(
        private readonly CustomAsiaOrderVisualUploadService $visuals,
    ) {}

    public function __invoke(string $id, string $kind): CustomAsiaOrderResource
    {
        if (! in_array($kind, ['customer', 'product'], true)) {
            abort(404);
        }

        $order = $this->visuals->delete($id, $kind);

        return CustomAsiaOrderResource::make($order);
    }
}
