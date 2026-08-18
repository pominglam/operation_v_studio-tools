<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodPreorder;
use App\Models\PlamodPreorderOffer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class PlamodPreorderOfferResolver
{
    /**
     * @param  array<int, string>  $skus
     * @return array<string, array{committed_qty: int, shipments: array<int, array{offer_id: string|null, quantity: int, eta_date: string|null, eta_label: string|null, po_due_date: string|null}>}>
     */
    public function bySkus(array $skus): array
    {
        $skus = array_values(array_filter(array_map(
            static fn (mixed $sku): string => trim((string) $sku),
            $skus,
        ), static fn (string $sku): bool => $sku !== ''));

        if ($skus === []) {
            return [];
        }

        /** @var Collection<string, Collection<int, PlamodPreorderOffer>> $offersBySku */
        $offersBySku = PlamodPreorderOffer::query()
            ->whereIn('sku', $skus)
            ->where('quantity', '>', 0)
            ->orderBy('eta_date')
            ->orderBy('offer_id')
            ->get()
            ->groupBy(static fn (PlamodPreorderOffer $row): string => (string) $row->sku);

        /** @var Collection<string, PlamodPreorder> $preordersBySku */
        $preordersBySku = PlamodPreorder::query()
            ->whereIn('sku', $skus)
            ->where('quantity_preorder', '>', 0)
            ->get()
            ->keyBy(static fn (PlamodPreorder $row): string => (string) $row->sku);

        $resolved = [];
        foreach ($skus as $sku) {
            $offerRows = $offersBySku->get($sku);
            if ($offerRows instanceof Collection && $offerRows->isNotEmpty()) {
                $shipments = [];
                $committedQty = 0;
                foreach ($offerRows as $offer) {
                    $quantity = max(0, (int) $offer->quantity);
                    if ($quantity <= 0) {
                        continue;
                    }
                    $committedQty += $quantity;
                    $shipments[] = $this->shipmentRow(
                        $offer->offer_id,
                        $quantity,
                        $offer->eta_date?->toDateString(),
                        $offer->po_due_date?->toDateString(),
                    );
                }

                $resolved[$sku] = [
                    'committed_qty' => $committedQty,
                    'shipments' => $shipments,
                ];

                continue;
            }

            $preorder = $preordersBySku->get($sku);
            if (! $preorder instanceof PlamodPreorder) {
                $resolved[$sku] = [
                    'committed_qty' => 0,
                    'shipments' => [],
                ];

                continue;
            }

            $quantity = max(0, (int) ($preorder->quantity_preorder ?? 0));
            $shipments = [];
            if ($quantity > 0) {
                $shipments[] = $this->shipmentRow(
                    null,
                    $quantity,
                    $preorder->eta_date?->toDateString(),
                    $preorder->po_due_date?->toDateString(),
                );
            }

            $resolved[$sku] = [
                'committed_qty' => $quantity,
                'shipments' => $shipments,
            ];
        }

        return $resolved;
    }

    /**
     * @return array{offer_id: string|null, quantity: int, eta_date: string|null, eta_label: string|null, po_due_date: string|null}
     */
    private function shipmentRow(
        ?string $offerId,
        int $quantity,
        ?string $etaDate,
        ?string $poDueDate,
    ): array {
        return [
            'offer_id' => $offerId !== null && trim($offerId) !== '' ? trim($offerId) : null,
            'quantity' => $quantity,
            'eta_date' => $etaDate,
            'eta_label' => $this->etaLabel($etaDate),
            'po_due_date' => $poDueDate,
        ];
    }

    private function etaLabel(?string $etaDate): ?string
    {
        if ($etaDate === null || trim($etaDate) === '') {
            return null;
        }

        try {
            return Carbon::parse($etaDate)->format('M j');
        } catch (\Throwable) {
            return null;
        }
    }
}
