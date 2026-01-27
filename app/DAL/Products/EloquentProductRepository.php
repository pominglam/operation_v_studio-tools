<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\DTOs\Products\ProductImportRowDTO;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Facades\DB;
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
            'grade' => 'grade',
            'series' => 'series',
            'scale' => 'scale',
            'vendor' => 'vendor',
            'latest_landed_unit_cost' => 'latest_landed_unit_cost',
            'order' => 'order_qty',
            'filled' => 'filled_qty',
            'available' => 'available_qty',
            'extended' => 'extended',
            'po_total_cost' => 'po_total_cost',
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
                'latest_unit_cost' => $row->latestUnitCost,
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
            update: ['barcode', 'description', 'type', 'vendor', 'latest_unit_cost', 'order_qty', 'filled_qty', 'extended', 'updated_at'],
        );

        return count($rows);
    }

    /**
     * @param  array<int, string>  $types
     * @param  array<int, string>  $vendors
     * @param  array<int, string>  $missing
     */
    public function paginate(int $perPage, ?string $search = null, array $types = [], array $vendors = [], array $missing = [], ?string $sortBy = null, string $sortDir = 'asc', ?string $purchaseOrderUuid = null): LengthAwarePaginator
    {
        [$sortColumn, $sortDir] = $this->resolveSort($sortBy, $sortDir);

        $q = Product::query()
            ->with(['sellingPrice', 'hljExternalContent', 'plamodExternalContent'])
            ->withCount(['plamodImageAssets as plamod_image_assets_count']);

        $q->addSelect([
            // Total cost across all PO lines for this product (sum of unit_cost * qty).
            // Uses received qty when present (>0), otherwise ordered qty.
            DB::raw("(
                select sum(
                    coalesce(poi.unit_cost, 0) *
                    (case
                        when coalesce(poi.qty_received, 0) > 0 then poi.qty_received
                        else coalesce(poi.qty_ordered, 0)
                    end)
                )
                from purchase_order_items poi
                where poi.product_id = products.id and poi.unit_cost is not null
            ) as po_total_cost"),
        ]);

        $purchaseOrderUuid = $purchaseOrderUuid !== null ? trim($purchaseOrderUuid) : null;
        if ($purchaseOrderUuid !== null && $purchaseOrderUuid !== '') {
            $q->whereExists(function ($sub) use ($purchaseOrderUuid): void {
                $sub->select(DB::raw('1'))
                    ->from('purchase_order_items as poi')
                    ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
                    ->whereColumn('poi.product_id', 'products.id')
                    ->where('po.uuid', '=', $purchaseOrderUuid);
            });
        }
        $this->applyListQueryFilters($q, $search, $types, $vendors);
        $this->applyMissingFilters($q, $missing);

        return $q->orderBy($sortColumn, $sortDir)->paginate(perPage: $perPage);
    }

    public function cursorForMissingInfo(?string $search = null, array $types = [], array $vendors = [], array $missing = []): LazyCollection
    {
        $q = Product::query()
            ->with(['sellingPrice', 'hljExternalContent', 'plamodExternalContent'])
            ->withCount(['plamodImageAssets as plamod_image_assets_count']);

        $this->applyListQueryFilters($q, $search, $types, $vendors);
        $this->applyMissingFilters($q, $missing);

        // Keep ordering stable for cursor iteration.
        return $q->orderBy('products.id')->lazyById(chunkSize: 200);
    }

    /**
     * @param  array<int, string>  $missing
     */
    private function applyMissingFilters($q, array $missing): void
    {
        $missing = array_values(array_unique(array_filter(array_map('trim', $missing), static fn (string $v): bool => $v !== '')));
        if ($missing === []) return;

        // Special filter: return only "complete" products (no missing info).
        // If present, it takes precedence over other missing flags.
        if (in_array('ok', $missing, true)) {
            $q->where(function ($sub): void {
                $sub->whereNotNull('barcode')->where('barcode', '<>', '');
            });

            $q->where(function ($sub): void {
                $sub->whereNotNull('handle')->where('handle', '<>', '');
            });

            $q->whereHas('sellingPrice', function ($q2): void {
                $q2->whereNotNull('selling_price')->where('selling_price', '<>', '');
            });

            $q->whereHas('plamodImageAssets');

            $q->where(function ($sub): void {
                $sub->whereHas('hljExternalContent', function ($q2): void {
                    $q2->whereNotNull('description_html')->where('description_html', '<>', '');
                })->orWhereHas('plamodExternalContent', function ($q2): void {
                    $q2->whereNotNull('description_html')->where('description_html', '<>', '');
                });
            });

            return;
        }

        foreach ($missing as $flag) {
            if ($flag === 'barcode') {
                $q->where(function ($sub): void {
                    $sub->whereNull('barcode')->orWhere('barcode', '=', '');
                });
                continue;
            }

            if ($flag === 'handle') {
                $q->where(function ($sub): void {
                    $sub->whereNull('handle')->orWhere('handle', '=', '');
                });
                continue;
            }

            if ($flag === 'selling_price') {
                $q->leftJoin('product_selling_prices as sps', 'sps.product_id', '=', 'products.id')
                    ->where(function ($sub): void {
                        $sub->whereNull('sps.selling_price')->orWhere('sps.selling_price', '=', '');
                    })
                    ->select('products.*');
                continue;
            }

            if ($flag === 'pdp_images') {
                $q->whereDoesntHave('plamodImageAssets');
                continue;
            }

            if ($flag === 'pdp_description') {
                $q->where(function ($sub): void {
                    $sub
                        ->whereDoesntHave('hljExternalContent', function ($q2): void {
                            $q2->whereNotNull('description_html')->where('description_html', '<>', '');
                        })
                        ->whereDoesntHave('plamodExternalContent', function ($q2): void {
                            $q2->whereNotNull('description_html')->where('description_html', '<>', '');
                        });
                });
                continue;
            }
        }
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
     * @param  array<int, string>  $uuids
     */
    public function listByUuidsForExport(array $uuids, ?string $sortBy = null, string $sortDir = 'asc'): Collection
    {
        [$sortColumn, $sortDir] = $this->resolveSort($sortBy, $sortDir);

        $uuids = array_values(array_unique(array_filter(array_map('trim', $uuids), static fn (string $v): bool => $v !== '')));
        if ($uuids === []) {
            return collect();
        }

        return Product::query()
            ->with(['sellingPrice'])
            ->whereIn('uuid', $uuids)
            ->orderBy($sortColumn, $sortDir)
            ->get();
    }

    /**
     * @param  array<int, string>  $uuids
     */
    public function listMissingBarcodeByUuidsForExport(array $uuids, ?string $sortBy = null, string $sortDir = 'asc'): Collection
    {
        [$sortColumn, $sortDir] = $this->resolveSort($sortBy, $sortDir);

        $uuids = array_values(array_unique(array_filter(array_map('trim', $uuids), static fn (string $v): bool => $v !== '')));
        if ($uuids === []) {
            return collect();
        }

        return Product::query()
            ->whereIn('uuid', $uuids)
            ->where(function ($q): void {
                $q->whereNull('barcode')
                    ->orWhere('barcode', '=', '');
            })
            ->orderBy($sortColumn, $sortDir)
            ->get();
    }

    /**
     * @param  array<int, string>  $uuids
     */
    public function listBarcodedByUuidsForExportSorted(array $uuids): Collection
    {
        $uuids = array_values(array_unique(array_filter(array_map('trim', $uuids), static fn (string $v): bool => $v !== '')));
        if ($uuids === []) {
            return collect();
        }

        return Product::query()
            ->with(['sellingPrice'])
            ->whereIn('uuid', $uuids)
            ->whereNotNull('barcode')
            ->where('barcode', '<>', '')
            ->orderByRaw('COALESCE(vendor, "") asc')
            ->orderByRaw('COALESCE(type, "") asc')
            ->orderBy('sku', 'asc')
            ->get();
    }

    public function listBarcodedForExportSorted(): Collection
    {
        return Product::query()
            ->with(['sellingPrice'])
            ->whereNotNull('barcode')
            ->where('barcode', '<>', '')
            ->orderByRaw('COALESCE(vendor, "") asc')
            ->orderByRaw('COALESCE(type, "") asc')
            ->orderBy('sku', 'asc')
            ->get();
    }

    public function listForShopifyContentExport(): Collection
    {
        return Product::query()
            ->with([
                'sellingPrice',
                'hljExternalContent',
                'plamodExternalContent',
                'externalContents',
                'plamodImageAssets',
            ])
            ->whereHas('sellingPrice', function ($q): void {
                $q->whereNotNull('selling_price')->where('selling_price', '<>', '');
            })
            ->orderBy('sku', 'asc')
            ->get();
    }

    /**
     * @param  array<int, string>  $uuids
     * @return Collection<int, Product>
     */
    public function listForShopifyContentExportByUuids(array $uuids): Collection
    {
        $uuids = array_values(array_unique(array_filter(array_map('trim', $uuids), static fn (string $v): bool => $v !== '')));
        if ($uuids === []) {
            return collect();
        }

        return Product::query()
            ->with([
                'sellingPrice',
                'hljExternalContent',
                'plamodExternalContent',
                'externalContents',
                'plamodImageAssets',
            ])
            ->whereIn('uuid', $uuids)
            ->whereHas('sellingPrice', function ($q): void {
                $q->whereNotNull('selling_price')->where('selling_price', '<>', '');
            })
            ->orderBy('sku', 'asc')
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

        $types = array_map(static fn (?string $t): string => trim((string) $t), $types);
        $types = array_values(array_unique(array_filter($types, static fn (string $t): bool => $t !== '')));
        sort($types);

        return $types;
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

        $vendors = array_map(static fn (?string $v): string => trim((string) $v), $vendors);
        $vendors = array_values(array_unique(array_filter($vendors, static fn (string $v): bool => $v !== '')));
        sort($vendors);

        return $vendors;
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

    public function findByUuids(array $uuids): Collection
    {
        $uuids = array_values(array_unique(array_filter(array_map('trim', $uuids), static fn (string $v): bool => $v !== '')));
        if ($uuids === []) {
            return collect();
        }

        return Product::query()
            ->whereIn('uuid', $uuids)
            ->get();
    }

    public function findByHandle(string $handle): Collection
    {
        $handle = trim($handle);
        if ($handle === '') {
            return collect();
        }

        return Product::query()
            ->whereNotNull('handle')
            ->where('handle', '=', $handle)
            ->get();
    }

    public function findBySkuAndVendor(string $sku, string $vendor): Collection
    {
        $sku = trim($sku);
        $vendor = trim($vendor);

        if ($sku === '' || $vendor === '') {
            return collect();
        }

        return Product::query()
            ->where('sku', '=', $sku)
            ->whereNotNull('vendor')
            ->where('vendor', '=', $vendor)
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
