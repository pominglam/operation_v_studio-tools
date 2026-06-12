<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Services\Shopify\Admin\Orders\ShopifyOrderDemandEligibility;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'shopify:export-order-lines-csv')]
final class ShopifyExportOrderLinesCsvCommand extends Command
{
    private const string EXPORT_QUERY = <<<'GQL'
query OrdersSalesExport($first: Int!, $after: String, $query: String) {
  orders(first: $first, after: $after, sortKey: CREATED_AT, reverse: false, query: $query) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      name
      createdAt
      cancelledAt
      displayFinancialStatus
      customer {
        displayName
        email
      }
      sourceName
      retailLocation {
        name
      }
      channelInformation {
        channelDefinition {
          channelName
          handle
        }
      }
      lineItems(first: 100) {
        nodes {
          title
          sku
          quantity
          originalUnitPriceSet {
            shopMoney { amount currencyCode }
          }
          discountedTotalSet {
            shopMoney { amount currencyCode }
          }
        }
      }
    }
  }
}
GQL;

    protected $signature = 'shopify:export-order-lines-csv {--from=} {--until=} {--output=}';

    protected $description = 'Export Shopify order line items to CSV for a date range (live Admin API).';

    public function handle(
        ShopifyAdminGraphQlClientInterface $client,
        ShopifyOrderDemandEligibility $eligibility,
    ): int {
        $from = (string) ($this->option('from') ?: '2026-06-01');
        $until = (string) ($this->option('until') ?: '2026-07-01');
        $output = (string) ($this->option('output') ?: storage_path("app/private/exports/order-lines-{$from}-until-{$until}.csv"));

        if (! is_dir(dirname($output))) {
            mkdir(dirname($output), 0775, true);
        }

        $handle = fopen($output, 'wb');
        if ($handle === false) {
            $this->error("Could not open output file: {$output}");

            return self::FAILURE;
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            'product_name',
            'sku',
            'order_name',
            'date',
            'time',
            'quantity',
            'customer',
            'sold_by',
            'unit_price',
            'line_revenue',
            'currency',
        ]);

        $queryFilter = "created_at:>={$from} created_at:<{$until}";
        $cursor = null;
        $rowCount = 0;
        $orderCount = 0;

        $this->info("Fetching orders from Shopify ({$from} to {$until})…");

        while (true) {
            $resp = $client->query(self::EXPORT_QUERY, [
                'first' => 50,
                'after' => $cursor,
                'query' => $queryFilter,
            ]);

            $page = $resp['data']['orders'] ?? null;
            if (! is_array($page)) {
                fclose($handle);
                $this->error('Shopify response missing data.orders.');

                return self::FAILURE;
            }

            $nodes = $page['nodes'] ?? [];
            if (! is_array($nodes)) {
                fclose($handle);
                $this->error('Shopify response missing order nodes.');

                return self::FAILURE;
            }

            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                if (! $eligibility->isEligibleFromGraphQlNode($node)) {
                    continue;
                }

                $orderCount++;
                $customer = $this->customerLabel($node['customer'] ?? null);
                $soldBy = $this->soldByLabel($node);
                $createdAt = isset($node['createdAt']) && is_string($node['createdAt'])
                    ? Carbon::parse($node['createdAt'])->timezone('America/Toronto')
                    : null;
                $orderName = isset($node['name']) && is_string($node['name']) ? $node['name'] : '';

                $lineNodes = $node['lineItems']['nodes'] ?? [];
                if (! is_array($lineNodes)) {
                    continue;
                }

                foreach ($lineNodes as $line) {
                    if (! is_array($line)) {
                        continue;
                    }

                    $qty = isset($line['quantity']) ? (int) $line['quantity'] : 0;
                    if ($qty <= 0) {
                        continue;
                    }

                    $unitMoney = $line['originalUnitPriceSet']['shopMoney'] ?? null;
                    $lineMoney = $line['discountedTotalSet']['shopMoney'] ?? $unitMoney;
                    $unitPrice = is_array($unitMoney) ? (string) ($unitMoney['amount'] ?? '') : '';
                    $lineRevenue = is_array($lineMoney) ? (string) ($lineMoney['amount'] ?? '') : '';
                    $currency = is_array($lineMoney) ? (string) ($lineMoney['currencyCode'] ?? '') : '';

                    fputcsv($handle, [
                        isset($line['title']) && is_string($line['title']) ? $line['title'] : '',
                        isset($line['sku']) && is_string($line['sku']) ? $line['sku'] : '',
                        $orderName,
                        $createdAt?->format('Y-m-d') ?? '',
                        $createdAt?->format('H:i:s') ?? '',
                        (string) $qty,
                        $customer,
                        $soldBy,
                        $unitPrice,
                        $lineRevenue,
                        $currency,
                    ]);
                    $rowCount++;
                }
            }

            if (! ($page['pageInfo']['hasNextPage'] ?? false)) {
                break;
            }

            $cursor = $page['pageInfo']['endCursor'] ?? null;
            if (! is_string($cursor) || $cursor === '') {
                break;
            }
        }

        fclose($handle);

        $this->info("Wrote {$rowCount} line(s) from {$orderCount} order(s) to:");
        $this->line($output);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>|null  $customer
     */
    private function customerLabel(?array $customer): string
    {
        if ($customer === null) {
            return 'Guest';
        }

        $name = isset($customer['displayName']) && is_string($customer['displayName'])
            ? trim($customer['displayName'])
            : '';
        if ($name !== '') {
            return $name;
        }

        $email = isset($customer['email']) && is_string($customer['email'])
            ? trim($customer['email'])
            : '';

        return $email !== '' ? $email : 'Guest';
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function soldByLabel(array $node): string
    {
        $retailLocation = $node['retailLocation'] ?? null;
        $retailName = is_array($retailLocation) && isset($retailLocation['name']) && is_string($retailLocation['name'])
            ? trim($retailLocation['name'])
            : '';

        $channelDefinition = $node['channelInformation']['channelDefinition'] ?? null;
        $channelName = is_array($channelDefinition) && isset($channelDefinition['channelName']) && is_string($channelDefinition['channelName'])
            ? trim($channelDefinition['channelName'])
            : '';

        if ($retailName !== '') {
            return $channelName !== '' ? "{$retailName} ({$channelName})" : $retailName;
        }

        if ($channelName !== '') {
            return $channelName;
        }

        $sourceName = isset($node['sourceName']) && is_string($node['sourceName'])
            ? strtolower(trim($node['sourceName']))
            : '';

        return match ($sourceName) {
            'web' => 'Online Store',
            'pos' => 'Point of Sale',
            'quick_sale' => 'Quick Sale',
            default => isset($node['sourceName']) && is_string($node['sourceName']) ? trim($node['sourceName']) : '',
        };
    }
}
