<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\StorePreorders\StorePreorderRepository;
use App\DTOs\StorePreorders\StorePreorderOpenResult;
use App\Models\PlamodPreorder;
use App\Models\Product;
use App\Models\StorePreorder;
use App\Services\Plamod\PlamodPreorderInterestService;
use App\Services\Plamod\PlamodPreorderLiveWindow;
use App\Services\StorePreorders\Exceptions\StorePreorderOpenException;
use App\Support\StorePreorders\StorePreorderClosingDate;
use App\Support\StorePreorders\StorePreorderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class StorePreorderOpenService
{
    public function __construct(
        private readonly StorePreorderRepository $offers,
        private readonly StorePreorderProductEnsureService $products,
        private readonly PlamodPreorderLiveWindow $liveWindow,
        private readonly StorePreorderOpenPublishService $publish,
        private readonly PlamodPreorderInterestService $interest,
    ) {}

    /**
     * @param  array<int, array{sku: string, deposit_percent: string, cap_qty: int|null, window_ends_on: string|null, selling_price: string|null}>  $items
     */
    public function open(array $items): StorePreorderOpenResult
    {
        $items = $this->normalizeItems($items);
        if ($items === []) {
            throw new StorePreorderOpenException('Select at least one Plamod kit.');
        }

        $skus = array_column($items, 'sku');

        $result = DB::transaction(function () use ($items, $skus): StorePreorderOpenResult {
            $plamodBySku = PlamodPreorder::query()
                ->active()
                ->whereIn('sku', $skus)
                ->get()
                ->keyBy(static fn (PlamodPreorder $row): string => trim((string) $row->sku));

            $this->assertAllSkusPresent($skus, $plamodBySku);
            $opened = $this->openRows($items, $plamodBySku);
            $this->interest->clear($skus);

            return $opened;
        });

        return $this->publish->afterOpen($result);
    }

    /**
     * @param  array<int, array{sku: string, deposit_percent: string, cap_qty: int|null, window_ends_on: ?Carbon, selling_price: string|null}>  $items
     * @param  \Illuminate\Support\Collection<string, PlamodPreorder>  $plamodBySku
     */
    private function openRows(array $items, $plamodBySku): StorePreorderOpenResult
    {
        $opened = 0;
        $reopened = 0;
        $skippedOpen = 0;
        $productsCreated = 0;
        $offers = collect();

        foreach ($items as $item) {
            /** @var PlamodPreorder $plamod */
            $plamod = $plamodBySku->get($item['sku']);
            $result = $this->openOne($plamod, $item);
            if ($result['skipped']) {
                $skippedOpen++;

                continue;
            }

            $offers->push($result['offer']);
            $productsCreated += $result['created'] ? 1 : 0;
            if ($result['reopened']) {
                $reopened++;
            } else {
                $opened++;
            }
        }

        return new StorePreorderOpenResult($offers, $opened, $reopened, $skippedOpen, $productsCreated);
    }

    /**
     * @param  array{sku: string, deposit_percent: string, cap_qty: int|null, window_ends_on: ?Carbon, selling_price: string|null}  $item
     * @return array{offer: StorePreorder, created: bool, reopened: bool, skipped: bool}
     */
    private function openOne(PlamodPreorder $plamod, array $item): array
    {
        $sku = trim((string) $plamod->sku);
        $existing = $this->offers->findByPlamodSku($sku);
        if ($existing instanceof StorePreorder && $existing->isOpen()) {
            return ['offer' => $existing, 'created' => false, 'reopened' => false, 'skipped' => true];
        }

        $this->liveWindow->assertCanOpen($plamod);

        $ensured = $this->products->ensureFromPlamod($plamod, $item['selling_price']);
        $this->markReadyForStorefront($ensured['product']);
        $windowEndsOn = $item['window_ends_on'] ?? StorePreorderClosingDate::defaultFromPlamodDue($plamod->po_due_date);
        $payload = [
            'product_id' => $ensured['product']->id,
            'plamod_sku' => $sku,
            'status' => StorePreorderStatus::OPEN,
            'deposit_percent' => $item['deposit_percent'],
            'cap_qty' => $item['cap_qty'],
            'selling_price_cad' => $this->products->sellingPriceFromPlamod($plamod, $item['selling_price']),
            'po_cost_cad' => $this->products->costBasis($plamod),
            'window_ends_on' => $windowEndsOn,
            'opened_at' => now(),
            'closed_at' => null,
        ];

        if ($existing instanceof StorePreorder) {
            return [
                'offer' => $this->offers->update($existing, $payload),
                'created' => $ensured['created'],
                'reopened' => true,
                'skipped' => false,
            ];
        }

        return [
            'offer' => $this->offers->create($payload),
            'created' => $ensured['created'],
            'reopened' => false,
            'skipped' => false,
        ];
    }

    /**
     * @param  array<int, array{sku: string, deposit_percent: string, cap_qty: int|null, window_ends_on: string|null, selling_price: string|null}>  $items
     * @return array<int, array{sku: string, deposit_percent: string, cap_qty: int|null, window_ends_on: ?Carbon, selling_price: string|null}>
     */
    private function normalizeItems(array $items): array
    {
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            $sku = trim((string) ($item['sku'] ?? ''));
            if ($sku === '' || isset($seen[$sku])) {
                continue;
            }
            $seen[$sku] = true;
            $out[] = [
                'sku' => $sku,
                'deposit_percent' => $this->normalizeDepositPercent((string) ($item['deposit_percent'] ?? '')),
                'cap_qty' => $item['cap_qty'] ?? null,
                'window_ends_on' => $this->normalizeWindow($item['window_ends_on'] ?? null),
                'selling_price' => $this->nullableTrim($item['selling_price'] ?? null),
            ];
        }

        return $out;
    }

    private function markReadyForStorefront(Product $product): void
    {
        if ($product->published_on_shopify) {
            return;
        }

        $product->published_on_shopify = true;
        $product->save();
    }

    private function normalizeDepositPercent(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || ! is_numeric($trimmed)) {
            throw new StorePreorderOpenException('Deposit percent is required for each kit.');
        }

        $amount = (float) $trimmed;
        if ($amount < 1 || $amount > 100) {
            throw new StorePreorderOpenException('Deposit percent must be between 1 and 100.');
        }

        return number_format($amount, 2, '.', '');
    }

    private function normalizeWindow(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $day = StorePreorderClosingDate::parseDay($value);
        if ($day === null) {
            throw new StorePreorderOpenException('Closing date is invalid.');
        }

        return $day;
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<int, string>  $skus
     * @param  \Illuminate\Support\Collection<string, PlamodPreorder>  $plamodBySku
     */
    private function assertAllSkusPresent(array $skus, $plamodBySku): void
    {
        $missing = [];
        foreach ($skus as $sku) {
            if (! $plamodBySku->has($sku)) {
                $missing[] = $sku;
            }
        }

        if ($missing !== []) {
            $sample = implode(', ', array_slice($missing, 0, 5));
            $suffix = count($missing) > 5 ? '…' : '';
            throw new StorePreorderOpenException('These SKUs are not on the Plamod pick list: '.$sample.$suffix);
        }
    }
}
