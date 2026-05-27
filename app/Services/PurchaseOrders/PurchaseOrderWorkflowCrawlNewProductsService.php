<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\Services\Products\ProductsBulkRenameAssetsService;
use App\Services\Products\ProductsRecrawlSelectedService;

final class PurchaseOrderWorkflowCrawlNewProductsService
{
    /**
     * @var array<int, string>
     */
    private const DEFAULT_SOURCES = [
        'bandai',
        'hlj',
        'gundamplanet',
        'newtype',
        'gundamhangar',
        'plamod',
        'competitor_price_research',
    ];

    public function __construct(
        private readonly PurchaseOrderProductScopeService $scope,
        private readonly ProductsRecrawlSelectedService $recrawl,
        private readonly ProductsBulkRenameAssetsService $renameAssets,
    ) {}

    /**
     * @return array{
     *   recrawl_queued: int,
     *   recrawl_batch_id: string,
     *   rename_queued: int,
     *   rename_batch_id: string
     * }
     */
    public function crawlNewProducts(string $purchaseOrderUuid): array
    {
        $uuids = $this->scope->productUuidsForPo($purchaseOrderUuid, true);
        if ($uuids === []) {
            return [
                'recrawl_queued' => 0,
                'recrawl_batch_id' => '',
                'rename_queued' => 0,
                'rename_batch_id' => '',
            ];
        }

        $recrawl = $this->recrawl->recrawlSelected($uuids, self::DEFAULT_SOURCES);
        $rename = $this->renameAssets->queue($uuids);

        return [
            'recrawl_queued' => $recrawl->queued,
            'recrawl_batch_id' => $recrawl->batchId,
            'rename_queued' => $rename->queued,
            'rename_batch_id' => $rename->batchId,
        ];
    }
}
