<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SpecialOrderResource;
use App\Services\SpecialOrders\SpecialOrderMilestoneService;

final class SpecialOrderBalanceReceivedController extends Controller
{
    public function __construct(
        private readonly SpecialOrderMilestoneService $milestones,
    ) {}

    public function __invoke(string $id): SpecialOrderResource
    {
        return SpecialOrderResource::make($this->milestones->markBalanceReceived($id));
    }
}
