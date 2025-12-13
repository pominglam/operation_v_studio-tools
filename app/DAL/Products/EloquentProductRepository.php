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

    public function paginate(int $perPage): LengthAwarePaginator
    {
        return Product::query()
            ->orderBy('sku')
            ->paginate(perPage: $perPage);
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
            throw (new ModelNotFoundException())->setModel(Product::class, [$uuid]);
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


