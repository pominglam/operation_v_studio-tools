<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SpecialOrderResource;
use App\Services\SpecialOrders\SpecialOrderVisualUploadService;

final class SpecialOrderVisualDeleteController extends Controller
{
    public function __construct(
        private readonly SpecialOrderVisualUploadService $visuals,
    ) {}

    public function __invoke(string $id, string $kind): SpecialOrderResource
    {
        if (! in_array($kind, ['customer', 'product'], true)) {
            abort(404);
        }

        $order = $this->visuals->delete($id, $kind);

        return SpecialOrderResource::make($order);
    }
}
