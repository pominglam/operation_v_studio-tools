<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\DTOs\Storefront\ModelKitStorefrontIndexDocument;
use App\DTOs\Storefront\ModelKitStorefrontIndexRow;
use App\Models\Product;
use App\Models\StorePreorder;
use App\Support\Products\ProductHoldQty;
use App\Support\Products\Storefront\ModelKitStorefrontTagResolver;
use App\Support\Products\Storefront\StorefrontTag;
use App\Support\StorePreorders\StorePreorderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class ModelKitStorefrontIndexBuilderService
{
    public function __construct(
        private readonly ModelKitStorefrontTagResolver $tags,
        private readonly ModelKitStorefrontIndexTagMapper $mapper,
        private readonly ModelKitStorefrontIndexImageResolver $images,
    ) {}

    public function build(): ModelKitStorefrontIndexDocument
    {
        $products = $this->candidateProducts();
        $imagesBySku = $this->images->urlsBySku(
            $products->map(static fn (Product $product): string => (string) $product->sku)->all(),
        );
        $preorderFlags = $this->preorderFlagsByProductId($products);

        $rows = [];
        foreach ($products as $product) {
            $row = $this->rowForProduct(
                $product,
                $imagesBySku[(string) $product->sku] ?? '',
                $preorderFlags[(int) $product->id] ?? ['closed' => false, 'offer' => false],
            );
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        usort($rows, $this->compareRows(...));

        return new ModelKitStorefrontIndexDocument(
            generatedAt: Carbon::now('America/Toronto')->toIso8601String(),
            products: $rows,
        );
    }

    /**
     * @return Collection<int, Product>
     */
    private function candidateProducts(): Collection
    {
        return Product::query()
            ->with('sellingPrice')
            ->whereNull('archived_at')
            ->where('published_on_shopify', '=', true)
            ->whereNotNull('handle')
            ->where('handle', '!=', '')
            ->select('products.*')
            ->selectRaw($this->latestPoReceivedDateSubquery().' as latest_po_received_date')
            ->orderBy('products.id')
            ->get();
    }

    private function latestPoReceivedDateSubquery(): string
    {
        return '(
            select max(po.received_date)
            from purchase_order_items poi
            inner join purchase_orders po on po.id = poi.purchase_order_id
            where poi.product_id = products.id
              and po.received_date is not null
        )';
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<int, array{closed: bool, offer: bool}>
     */
    private function preorderFlagsByProductId(Collection $products): array
    {
        $ids = $products->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $today = Carbon::now('America/Toronto')->toDateString();
        $flags = [];
        StorePreorder::query()
            ->whereIn('product_id', $ids)
            ->get(['product_id', 'status', 'window_ends_on'])
            ->each(function (StorePreorder $offer) use ($today, &$flags): void {
                $id = (int) $offer->product_id;
                $ended = $offer->window_ends_on !== null && $offer->window_ends_on->toDateString() < $today;
                $existing = $flags[$id] ?? ['closed' => false, 'offer' => false];
                $flags[$id] = [
                    'offer' => true,
                    'closed' => $existing['closed'] || $offer->status === StorePreorderStatus::CLOSED || $ended,
                ];
            });

        return $flags;
    }

    /**
     * @param  array{closed: bool, offer: bool}  $preorder
     */
    private function rowForProduct(Product $product, string $image, array $preorder): ?ModelKitStorefrontIndexRow
    {
        $handle = trim((string) $product->handle);
        if ($handle === '') {
            return null;
        }

        $tags = $this->tags->tagsForProduct($product);
        if (! in_array(StorefrontTag::MK_DEPT_MODEL_KITS, $tags, true)) {
            return null;
        }

        $mapped = $this->mapper->map($tags);
        $arrived = $this->arrivedDate($product);
        $state = ModelKitStorefrontIndexPurchaseState::from(
            $preorder,
            ProductHoldQty::sellableForProduct($product),
        );

        return new ModelKitStorefrontIndexRow(
            handle: $handle,
            title: trim((string) $product->description),
            image: $image,
            priceCents: $this->priceCents($product),
            available: $state['available'],
            latestArrival: (bool) $product->latest_arrival,
            arrived: $arrived,
            grade: $mapped['grade'],
            sublines: $mapped['sublines'],
            series: $mapped['series'],
            lines: $mapped['lines'],
            preorderClosed: $state['preorderClosed'],
            openPreorder: $state['openPreorder'],
        );
    }

    private function arrivedDate(Product $product): string
    {
        $raw = $product->getAttribute('latest_po_received_date');
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        $value = trim((string) $raw);

        return $value !== '' ? substr($value, 0, 10) : '';
    }

    private function priceCents(Product $product): int
    {
        $raw = $product->sellingPrice?->selling_price;
        if ($raw === null || trim((string) $raw) === '' || ! is_numeric($raw)) {
            return 0;
        }

        return (int) round(((float) $raw) * 100);
    }

    private function compareRows(ModelKitStorefrontIndexRow $left, ModelKitStorefrontIndexRow $right): int
    {
        $arrived = strcmp($right->arrived, $left->arrived);
        if ($arrived !== 0) {
            return $arrived;
        }

        return strcmp($left->handle, $right->handle);
    }
}
