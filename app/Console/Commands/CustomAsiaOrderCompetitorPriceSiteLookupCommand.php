<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CustomOrders\CustomAsiaOrderCompetitorPriceLookupService;
use Illuminate\Console\Command;
use JsonException;

final class CustomAsiaOrderCompetitorPriceSiteLookupCommand extends Command
{
    protected $signature = 'custom-asia-orders:competitor-price-site {site_key : Stable site key}';

    protected $description = 'Lookup one competitor price for a product name (used by parallel refresh workers)';

    public function handle(CustomAsiaOrderCompetitorPriceLookupService $lookup): int
    {
        $productName = trim((string) getenv('COMPETITOR_LOOKUP_PRODUCT_NAME'));
        $siteKey = trim((string) $this->argument('site_key'));

        if ($productName === '' || $siteKey === '') {
            $this->error('Product name and site key are required.');

            return self::FAILURE;
        }

        try {
            $this->line(json_encode(
                $lookup->lookupSingleSiteByProductName($productName, $siteKey),
                JSON_THROW_ON_ERROR,
            ));
        } catch (JsonException) {
            $this->error('Could not encode lookup result.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
