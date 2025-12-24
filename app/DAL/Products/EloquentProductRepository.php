<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\DTOs\Products\ProductImportRowDTO;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class EloquentProductRepository implements ProductRepository
{
    /**
     * @param  array<int, string>  $types
     */
    private function applyListQueryFilters($q, ?string $search, array $types, array $vendors = []): void
    {
        $search = $search !== null ? trim($search) : null;
        if ($search !== null && $search !== '') {
            $q->where(function ($sub) use ($search): void {
                $sub->where('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $types = array_values(array_filter(array_map('trim', $types), static fn (string $t): bool => $t !== ''));
        if ($types !== []) {
            $q->whereIn('type', $types);
        }

        $vendors = array_values(array_filter(array_map('trim', $vendors), static fn (string $v): bool => $v !== ''));
        if ($vendors !== []) {
            $q->whereIn('vendor', $vendors);
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveSort(?string $sortBy, string $sortDir): array
    {
        $sortDir = strtolower($sortDir) === 'desc' ? 'desc' : 'asc';
        $sortBy = $sortBy !== null ? trim($sortBy) : null;

        $sortMap = [
            'sku' => 'sku',
            'barcode' => 'barcode',
            'description' => 'description',
            'type' => 'type',
            'vendor' => 'vendor',
            'price' => 'price',
            'order' => 'order_qty',
            'filled' => 'filled_qty',
            'available' => 'available_qty',
            'extended' => 'extended',
            'updated_at' => 'updated_at',
            'created_at' => 'created_at',
        ];

        $sortColumn = $sortBy !== null && array_key_exists($sortBy, $sortMap) ? $sortMap[$sortBy] : 'sku';

        return [$sortColumn, $sortDir];
    }

    public function upsertImportedRows(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $payload = array_map(static function (ProductImportRowDTO $row): array {
            return [
                'uuid' => (string) Str::uuid(),
                'sku' => $row->sku,
                'barcode' => $row->barcode,
                'description' => $row->description,
                'type' => $row->type,
                'vendor' => $row->vendor,
                'price' => $row->price,
                'order_qty' => $row->orderQty,
                'filled_qty' => $row->filledQty,
                'extended' => $row->extended,
                'updated_at' => now(),
                'created_at' => now(),
            ];
        }, $rows);

        Product::query()->upsert(
            $payload,
            uniqueBy: ['sku'],
            update: ['barcode', 'description', 'type', 'vendor', 'price', 'order_qty', 'filled_qty', 'extended', 'updated_at'],
        );

        return count($rows);
    }

    /**
     * @param  array<int, string>  $types
     * @param  array<int, string>  $vendors
     */
    public function paginate(int $perPage, ?string $search = null, array $types = [], array $vendors = [], ?string $sortBy = null, string $sortDir = 'asc'): LengthAwarePaginator
    {
        [$sortColumn, $sortDir] = $this->resolveSort($sortBy, $sortDir);

        $q = Product::query();
        $this->applyListQueryFilters($q, $search, $types, $vendors);

        return $q->orderBy($sortColumn, $sortDir)->paginate(perPage: $perPage);
    }

    /**
     * @param  array<int, string>  $types
     * @return Collection<int, Product>
     */
    public function listForExport(?string $search = null, array $types = [], ?string $sortBy = null, string $sortDir = 'asc'): Collection
    {
        [$sortColumn, $sortDir] = $this->resolveSort($sortBy, $sortDir);

        $q = Product::query()->with(['sellingPrice']);
        $this->applyListQueryFilters($q, $search, $types);

        return $q->orderBy($sortColumn, $sortDir)->get();
    }

    public function listMissingSellingPriceForExport(?string $sortBy = null, string $sortDir = 'asc'): Collection
    {
        [$sortColumn, $sortDir] = $this->resolveSort($sortBy, $sortDir);

        return Product::query()
            ->with(['sellingPrice'])
            ->leftJoin('product_selling_prices as sps', 'sps.product_id', '=', 'products.id')
            ->where(function ($q): void {
                $q->whereNull('sps.selling_price')
                    ->orWhere('sps.selling_price', '=', '');
            })
            ->select('products.*')
            ->orderBy($sortColumn, $sortDir)
            ->get();
    }

    public function listMissingBarcodeForExport(?string $sortBy = null, string $sortDir = 'asc'): Collection
    {
        [$sortColumn, $sortDir] = $this->resolveSort($sortBy, $sortDir);

        return Product::query()
            ->with(['sellingPrice'])
            ->where(function ($q): void {
                $q->whereNull('barcode')
                    ->orWhere('barcode', '=', '');
            })
            ->orderBy($sortColumn, $sortDir)
            ->get();
    }

    /**
     * @return Collection<int, Product>
     */
    public function listMissingType(): Collection
    {
        return Product::query()
            ->where(function ($q): void {
                $q->whereNull('type')
                    ->orWhere('type', '=', '');
            })
            ->get();
    }

    /**
     * @return Collection<int, Product>
     */
    public function listAll(): Collection
    {
        return Product::query()->get();
    }

    /**
     * @return array<int, string>
     */
    public function distinctTypes(): array
    {
        /** @var array<int, string|null> $types */
        $types = Product::query()
            ->select('type')
            ->whereNotNull('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->all();

        return array_values(array_filter($types, static fn (?string $t): bool => $t !== null && trim($t) !== ''));
    }

    public function distinctVendors(): array
    {
        /** @var array<int, string|null> $vendors */
        $vendors = Product::query()
            ->select('vendor')
            ->whereNotNull('vendor')
            ->distinct()
            ->orderBy('vendor')
            ->pluck('vendor')
            ->all();

        return array_values(array_filter($vendors, static fn (?string $v): bool => $v !== null && trim($v) !== ''));
    }

    public function findBySkus(array $skus): Collection
    {
        $skus = array_values(array_filter(array_map('trim', $skus), static fn (string $v): bool => $v !== ''));
        if ($skus === []) {
            return collect();
        }

        return Product::query()
            ->whereIn('sku', $skus)
            ->get();
    }

    public function findByBarcodes(array $barcodes): Collection
    {
        $barcodes = array_values(array_filter(array_map('trim', $barcodes), static fn (string $v): bool => $v !== ''));
        if ($barcodes === []) {
            return collect();
        }

        return Product::query()
            ->whereNotNull('barcode')
            ->whereIn('barcode', $barcodes)
            ->get();
    }

    public function create(Product $product): Product
    {
        return $this->save($product);
    }

    public function findByUuidOrFail(string $uuid): Product
    {
        /** @var Product|null $product */
        $product = Product::query()->where('uuid', $uuid)->first();
        if ($product === null) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$uuid]);
        }

        return $product;
    }

    public function save(Product $product): Product
    {
        $product->save();

        return $product;
    }

    public function deleteByUuids(array $uuids): int
    {
        if ($uuids === []) {
            return 0;
        }

        return Product::query()
            ->whereIn('uuid', $uuids)
            ->delete();
    }

    public function updateByUuids(array $uuids, array $updates): int
    {
        if ($uuids === [] || $updates === []) {
            return 0;
        }

        return Product::query()
            ->whereIn('uuid', $uuids)
            ->update($updates);
    }

    public function flushAll(): void
    {
        Product::query()->truncate();
    }
}
