<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\Models\Product;
use App\Models\StorePreorder;
use App\Support\StorePreorders\StorePreorderStatus;

final class StorePreorderShopifyPushOverride
{
    public const string LINE_TITLE_SUFFIX = ' (PO)';

    public const string ORDER_TAG = 'preorder';

    public function __construct(
        private readonly StorePreorder $offer,
    ) {}

    public static function forProduct(Product $product): ?self
    {
        return self::mapOpenByProductIds([(int) $product->id])[(int) $product->id] ?? null;
    }

    public static function forAnyProduct(Product $product): ?self
    {
        $id = (int) $product->id;
        if ($id <= 0) {
            return null;
        }

        $offer = StorePreorder::query()->where('product_id', '=', $id)->first();

        return $offer instanceof StorePreorder ? new self($offer) : null;
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, self>
     */
    public static function mapOpenByProductIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(
            $productIds,
            static fn (int $id): bool => $id > 0,
        )));
        if ($productIds === []) {
            return [];
        }

        $offers = StorePreorder::query()
            ->whereIn('product_id', $productIds)
            ->where('status', StorePreorderStatus::OPEN)
            ->get();

        $map = [];
        foreach ($offers as $offer) {
            $map[(int) $offer->product_id] = new self($offer);
        }

        return $map;
    }

    public static function withLineTitleSuffix(string $title): string
    {
        $title = trim($title);
        if ($title === '' || str_ends_with($title, self::LINE_TITLE_SUFFIX)) {
            return $title;
        }

        return $title.self::LINE_TITLE_SUFFIX;
    }

    public function shopifyTitle(string $base): string
    {
        return self::withLineTitleSuffix($base);
    }

    /**
     * @param  array<string, mixed>  $variant
     */
    public function applyInventory(array &$variant, string $locationGid): void
    {
        $variant['inventoryPolicy'] = $this->isUnlimited() ? 'CONTINUE' : 'DENY';
        $variant['inventoryItem'] = ['tracked' => ! $this->isUnlimited()];
        if ($this->isUnlimited() || $locationGid === '') {
            return;
        }

        $variant['inventoryQuantities'] = [
            [
                'locationId' => $locationGid,
                'name' => 'available',
                'quantity' => $this->quantity(),
            ],
        ];
    }

    public function quantity(): int
    {
        if ($this->isWindowClosed()) {
            return 0;
        }

        return $this->offer->remainingCapQty() ?? 0;
    }

    public function isUnlimited(): bool
    {
        return ! $this->isWindowClosed() && $this->offer->remainingCapQty() === null;
    }

    public function price(): string
    {
        $full = (float) ($this->offer->selling_price_cad ?? 0);
        $percent = (float) $this->offer->deposit_percent;
        $deposit = round($full * ($percent / 100), 2);

        return number_format(max(0, $deposit), 2, '.', '');
    }

    public function fullPrice(): string
    {
        return number_format((float) ($this->offer->selling_price_cad ?? 0), 2, '.', '');
    }

    public function remaining(): string
    {
        return number_format(max(0, (float) $this->fullPrice() - (float) $this->price()), 2, '.', '');
    }

    public function closesOn(): ?string
    {
        $day = $this->offer->window_ends_on;

        return $day?->toDateString();
    }

    private function isWindowClosed(): bool
    {
        $day = $this->closesOn();
        if ($day === null) {
            return false;
        }

        return $day < now()->toDateString();
    }

    /**
     * @return list<array{namespace: string, key: string, type: string, value: string}>
     */
    public function metafields(): array
    {
        $fields = [
            $this->metafield('price', $this->fullPrice()),
            $this->metafield('deposit', $this->price()),
            $this->metafield('remaining', $this->remaining()),
            $this->metafield('deposit_percent', $this->depositPercentLabel()),
        ];
        $this->appendDateMetafield($fields, 'closes_on', $this->closesOn());
        $this->appendDateMetafield($fields, 'eta_on', $this->etaOn());
        $fields[] = $this->metafield('status', (string) $this->offer->status);

        return $fields;
    }

    public function etaOn(): ?string
    {
        $stored = $this->offer->eta_date;
        if ($stored !== null) {
            return $stored->toDateString();
        }

        return (new StorePreorderEtaLookup)->forSku((string) $this->offer->plamod_sku);
    }

    public function descriptionPrefixHtml(): string
    {
        return '';
    }

    private function depositPercentLabel(): string
    {
        return rtrim(rtrim(number_format((float) $this->offer->deposit_percent, 2, '.', ''), '0'), '.');
    }

    /**
     * @return array{namespace: string, key: string, type: string, value: string}
     */
    private function metafield(string $key, string $value): array
    {
        return [
            'namespace' => 'ovs_store_preorder',
            'key' => $key,
            'type' => 'single_line_text_field',
            'value' => $value,
        ];
    }

    /**
     * @param  list<array{namespace: string, key: string, type: string, value: string}>  $fields
     */
    private function appendDateMetafield(array &$fields, string $key, ?string $day): void
    {
        if ($day === null) {
            return;
        }

        $fields[] = [
            'namespace' => 'ovs_store_preorder',
            'key' => $key,
            'type' => 'date',
            'value' => $day,
        ];
    }
}
