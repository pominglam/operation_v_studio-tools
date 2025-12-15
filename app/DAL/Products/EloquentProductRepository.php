<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\DTOs\Products\ProductImportRowDTO;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final class EloquentProductRepository implements ProductRepository
{
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
            update: ['barcode', 'description', 'type', 'price', 'order_qty', 'filled_qty', 'extended', 'updated_at'],
        );

        return count($rows);
    }

    /**
     * @param  array<int, string>  $types
     */
    public function paginate(int $perPage, ?string $search = null, array $types = [], ?string $sortBy = null, string $sortDir = 'asc'): LengthAwarePaginator
    {
        $sortDir = strtolower($sortDir) === 'desc' ? 'desc' : 'asc';
        $sortBy = $sortBy !== null ? trim($sortBy) : null;

        $sortMap = [
            'sku' => 'sku',
            'barcode' => 'barcode',
            'description' => 'description',
            'type' => 'type',
            'price' => 'price',
            'order' => 'order_qty',
            'filled' => 'filled_qty',
            'extended' => 'extended',
            'updated_at' => 'updated_at',
            'created_at' => 'created_at',
        ];

        $sortColumn = $sortBy !== null && array_key_exists($sortBy, $sortMap) ? $sortMap[$sortBy] : 'sku';

        $q = Product::query();

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

        return $q->orderBy($sortColumn, $sortDir)->paginate(perPage: $perPage);
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

    public function flushAll(): void
    {
        Product::query()->truncate();
    }
}
