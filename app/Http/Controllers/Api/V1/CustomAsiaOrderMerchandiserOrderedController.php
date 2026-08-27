<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CustomAsiaOrderResource;
use App\Services\CustomOrders\CustomAsiaOrderMilestoneService;

final class CustomAsiaOrderMerchandiserOrderedController extends Controller
{
    public function __construct(
        private readonly CustomAsiaOrderMilestoneService $milestones,
    ) {}

    public function __invoke(string $id): CustomAsiaOrderResource
    {
        return CustomAsiaOrderResource::make($this->milestones->markMerchandiserOrdered($id));
    }
}
