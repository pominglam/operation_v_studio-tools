<?php

declare(strict_types=1);

namespace App\Jobs\SpecialOrders;

use App\Services\SpecialOrders\SpecialOrderCompetitorPricesRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RefreshSpecialOrderCompetitorPricesJob implements ShouldQueue
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

    public function handle(SpecialOrderCompetitorPricesRefreshService $refresh): void
    {
        $refresh->executeRefresh($this->orderUuid, $this->scope);
    }
}
