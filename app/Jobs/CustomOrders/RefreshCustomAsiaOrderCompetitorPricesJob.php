<?php

declare(strict_types=1);

namespace App\Jobs\CustomOrders;

use App\Services\CustomOrders\CustomAsiaOrderCompetitorPricesRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RefreshCustomAsiaOrderCompetitorPricesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public string $orderUuid,
        public string $scope,
    ) {}

    public function handle(CustomAsiaOrderCompetitorPricesRefreshService $refresh): void
    {
        $refresh->executeRefresh($this->orderUuid, $this->scope);
    }
}
