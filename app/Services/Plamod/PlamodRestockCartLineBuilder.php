<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Enums\PlamodRestockSkuDecisionStatus;

final class PlamodRestockCartLineBuilder
{
    public function __construct(
        private readonly PlamodRestockProposalService $proposal,
    ) {}

    /**
     * @param  array<int, string>|null  $skuFilter  When set, only include these SKUs (must each qualify individually).
     * @return array<int, array{sku: string, qty: int, product_name: string, source: 'existing'|'new'}>
     */
    public function buildLines(?array $skuFilter = null, bool $includeZeroIncludedNew = false): array
    {
        $proposal = $this->proposal->build(hideDismissed: true, onlyIncludedNew: false);
        $lines = [];

        foreach ($proposal['existing'] as $line) {
            $qty = (int) ($line['proposed_qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $lines[] = [
                'sku' => (string) ($line['sku'] ?? ''),
                'qty' => $qty,
                'product_name' => (string) ($line['product_name'] ?? $line['sku'] ?? ''),
                'source' => 'existing',
            ];
        }

        foreach ($proposal['new_products'] as $line) {
            if (($line['status'] ?? '') !== PlamodRestockSkuDecisionStatus::Included->value) {
                continue;
            }

            $qty = (int) ($line['order_qty'] ?? 0);
            if ($qty < 0 || ($qty === 0 && ! $includeZeroIncludedNew)) {
                continue;
            }

            $lines[] = [
                'sku' => (string) ($line['sku'] ?? ''),
                'qty' => $qty,
                'product_name' => (string) ($line['product_name'] ?? $line['sku'] ?? ''),
                'source' => 'new',
            ];
        }

        $lines = array_values(array_filter(
            $lines,
            static fn (array $line): bool => $line['sku'] !== ''
                && ($line['qty'] > 0 || ($includeZeroIncludedNew && $line['source'] === 'new')),
        ));

        if ($skuFilter === null) {
            return $lines;
        }

        $allowed = array_fill_keys(array_map(static fn (string $sku): string => trim($sku), $skuFilter), true);

        return array_values(array_filter(
            $lines,
            static fn (array $line): bool => isset($allowed[$line['sku']]),
        ));
    }
}
