<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\DTOs\Products\ProductImportRowDTO;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

final class EloquentProductRepository implements ProductRepository
{
    private const MAIN_TYPE_EMPTY_SENTINEL = '__empty__';

    private function resolveBarcodeCollationForTempTable(): string
    {
        try {
            $driver = DB::connection()->getDriverName();
            if ($driver !== 'mysql') {
                return 'utf8mb4_unicode_ci';
            }

            /** @var array<int, object> $rows */
            $rows = DB::select(
                'SELECT COLLATION_NAME AS collation
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?',
                ['products', 'barcode'],
            );

            $first = $rows[0] ?? null;
            $collation = $first !== null ? (string) ($first->collation ?? '') : '';
            $collation = trim($collation);

            return $collation !== '' ? $collation : 'utf8mb4_unicode_ci';
        } catch (\Throwable) {
            return 'utf8mb4_unicode_ci';
        }
    }

    /**
     * @param  array<int, string>  $mainTypes
     * @param  array<int, string>  $types
     * @param  array<int, string>  $searchTerms
     */
    private function applyListQueryFilters($q, ?string $search, array $mainTypes, array $types, array $vendors = [], array $searchTerms = []): void
    {
        $search = $search !== null ? trim($search) : null;
        $searchTerms = array_values(array_unique(array_filter(array_map('trim', $searchTerms), static fn (string $v): bool => $v !== '')));
        if ($searchTerms !== []) {
            $q->where(function ($outer) use ($searchTerms): void {
                foreach ($searchTerms as $term) {
                    $outer->orWhere(function ($sub) use ($term): void {
                        $sub->where('sku', 'like', "%{$term}%")
                            ->orWhere('barcode', 'like', "%{$term}%")
                            ->orWhere('description', 'like', "%{$term}%");
                    });
                }
            });
        } elseif ($search !== null && $search !== '') {
            $q->where(function ($sub) use ($search): void {
                $sub->where('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $mainTypes = array_values(array_unique(array_filter(array_map(
            static fn (string $t): string => trim($t),
            $mainTypes,
        ), static fn (string $t): bool => $t !== '')));

        $wantEmptyMainType = false;
        $mainTypesForWhereIn = [];
        foreach ($mainTypes as $mt) {
            if (strtolower($mt) === self::MAIN_TYPE_EMPTY_SENTINEL) {
                $wantEmptyMainType = true;
                continue;
            }
            $mainTypesForWhereIn[] = $mt;
        }

        if ($wantEmptyMainType && $mainTypesForWhereIn !== []) {
            $q->where(function ($sub) use ($mainTypesForWhereIn): void {
                $sub->whereIn('main_type', $mainTypesForWhereIn)
                    ->orWhereNull('main_type')
                    ->orWhere('main_type', '=', '');
            });
        } elseif ($wantEmptyMainType) {
            $q->where(function ($sub): void {
                $sub->whereNull('main_type')
                    ->orWhere('main_type', '=', '');
            });
        } elseif ($mainTypesForWhereIn !== []) {
            $q->whereIn('main_type', $mainTypesForWhereIn);
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
            'main_type' => 'main_type',
            'type' => 'type',
            'grade' => 'grade',
            'series' => 'series',
            'scale' => 'scale',
            'vendor' => 'vendor',
            'latest_landed_unit_cost' => 'latest_landed_unit_cost',
            'selling_price' => '__selling_price',
            'total_ordered' => 'total_ordered_qty',
            'total_sold' => 'total_sold_qty',
            'order' => 'order_qty',
            'filled' => 'filled_qty',
            'available' => 'available_qty',
            'maintain' => 'maintain_qty',
            'not_arrived' => 'inbound_open_po_qty',
            'reorder' => 'reorder_qty',
            'extended' => 'extended',
            'po_total_cost' => 'po_total_cost',
            'updated_at' => 'updated_at',
            'created_at' => 'created_at',
        ];

        $sortColumn = $sortBy !== null && array_key_exists($sortBy, $sortMap) ? $sortMap[$sortBy] : 'sku';

        return [$sortColumn, $sortDir];
    }

    private function inboundOpenPoQtyExpression(): string
    {
        return '(
            select coalesce(sum(
                case when coalesce(poi.qty_ordered, 0) > 0 then coalesce(poi.qty_ordered, 0) else 0 end
            ), 0)
            from purchase_order_items poi
            inner join purchase_orders po on po.id = poi.purchase_order_id
            where poi.product_id = products.id
              and po.received_date is null
        )';
    }

    private function reorderQtyExpression(string $inboundExpr): string
    {
        $delta = "coalesce(products.maintain_qty, 0) - coalesce(products.available_qty, 0) - ({$inboundExpr})";

        return "case when ({$delta}) > 0 then ({$delta}) else 0 end";
    }

    private function totalOrderedReceivedQtyExpression(): string
    {
        return '(
            select coalesce(sum(coalesce(poi.qty_received, 0)), 0)
            from purchase_order_items poi
            inner join purchase_orders po on po.id = poi.purchase_order_id
            where poi.product_id = products.id
              and po.received_date is not null
        )';
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

    public function overrideAvailableQtyFromBarcodeCounts(array $barcodeCounts, bool $resetMissingToZero = true): array
    {
        /** @var array<int, array{barcode:string, qty:int}> $barcodeCounts */
        $rows = [];
        foreach ($barcodeCounts as $row) {
            $barcode = is_array($row) ? (string) ($row['barcode'] ?? '') : '';
            $barcode = trim($barcode);
            if ($barcode === '') {
                continue;
            }
            $qty = is_array($row) && array_key_exists('qty', $row) && is_numeric($row['qty']) ? (int) $row['qty'] : 0;
            if ($qty <= 0) {
                continue;
            }
            $rows[] = ['barcode' => $barcode, 'qty' => $qty];
        }

        $collation = $this->resolveBarcodeCollationForTempTable();

        return DB::transaction(function () use ($rows, $collation, $resetMissingToZero): array {
            $reset = 0;
            if ($resetMissingToZero) {
                $reset = Product::query()->update(['available_qty' => 0]);
            }
            if ($rows === []) {
                return ['reset' => $reset, 'updated' => 0];
            }

            $driver = DB::connection()->getDriverName();

            // SQLite (tests) doesn't support UPDATE ... JOIN; do per-barcode updates.
            if ($driver === 'sqlite') {
                $updated = 0;
                foreach ($rows as $r) {
                    $barcode = $r['barcode'];
                    $qty = (int) $r['qty'];
                    $updated += (int) Product::query()
                        ->where('barcode', '=', $barcode)
                        ->update(['available_qty' => $qty]);
                }

                return ['reset' => $reset, 'updated' => $updated];
            }

            DB::statement(
                'CREATE TEMPORARY TABLE tmp_inventory_override (
                    barcode VARCHAR(64) CHARACTER SET utf8mb4 COLLATE '.$collation.' PRIMARY KEY,
                    qty INT NOT NULL
                )',
            );

            try {
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('tmp_inventory_override')->insert($chunk);
                }

                $updated = (int) DB::update(
                    'UPDATE products p
                     INNER JOIN tmp_inventory_override t ON p.barcode = t.barcode
                     SET p.available_qty = t.qty',
                );

                return ['reset' => $reset, 'updated' => $updated];
            } finally {
                DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_inventory_override');
            }
        });
    }

    public function overrideAvailableQtyFromBarcodeCountsForProductIds(array $restrictToProductIds, array $barcodeCounts, bool $resetMissingToZero = true): array
    {
        $restrictToProductIds = array_values(array_unique(array_filter(array_map(
            static fn ($v): int => (int) $v,
            $restrictToProductIds,
        ), static fn (int $v): bool => $v > 0)));

        /** @var array<int, array{barcode:string, qty:int}> $barcodeCounts */
        $rows = [];
        foreach ($barcodeCounts as $row) {
            $barcode = is_array($row) ? (string) ($row['barcode'] ?? '') : '';
            $barcode = trim($barcode);
            if ($barcode === '') {
                continue;
            }
            $qty = is_array($row) && array_key_exists('qty', $row) && is_numeric($row['qty']) ? (int) $row['qty'] : 0;
            if ($qty <= 0) {
                continue;
            }
            $rows[] = ['barcode' => $barcode, 'qty' => $qty];
        }

        $collation = $this->resolveBarcodeCollationForTempTable();

        return DB::transaction(function () use ($restrictToProductIds, $rows, $collation, $resetMissingToZero): array {
            if ($restrictToProductIds === []) {
                return ['reset' => 0, 'updated' => 0];
            }

            $reset = 0;
            if ($resetMissingToZero) {
                $reset = (int) Product::query()
                    ->whereIn('id', $restrictToProductIds)
                    ->update(['available_qty' => 0]);
            }

            if ($rows === []) {
                return ['reset' => $reset, 'updated' => 0];
            }

            $driver = DB::connection()->getDriverName();

            // SQLite (tests) doesn't support UPDATE ... JOIN; do per-barcode updates.
            if ($driver === 'sqlite') {
                $updated = 0;
                foreach ($rows as $r) {
                    $barcode = $r['barcode'];
                    $qty = (int) $r['qty'];
                    $updated += (int) Product::query()
                        ->whereIn('id', $restrictToProductIds)
                        ->where('barcode', '=', $barcode)
                        ->update(['available_qty' => $qty]);
                }

                return ['reset' => $reset, 'updated' => $updated];
            }

            DB::statement(
                'CREATE TEMPORARY TABLE tmp_inventory_override (
                    barcode VARCHAR(64) CHARACTER SET utf8mb4 COLLATE '.$collation.' PRIMARY KEY,
                    qty INT NOT NULL
                )',
            );

            try {
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('tmp_inventory_override')->insert($chunk);
                }

                $ids = implode(',', array_map('intval', $restrictToProductIds));
                $ids = trim($ids);
                if ($ids === '') {
                    return ['reset' => $reset, 'updated' => 0];
                }

                $updated = (int) DB::update(
                    'UPDATE products p
                     INNER JOIN tmp_inventory_override t ON p.barcode = t.barcode
                     SET p.available_qty = t.qty
                     WHERE p.id IN ('.$ids.')',
                );

                return ['reset' => $reset, 'updated' => $updated];
            } finally {
                DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_inventory_override');
            }
        });
    }

    /**
     * @param  array<int, string>  $mainTypes
     * @param  array<int, string>  $types
     * @param  array<int, string>  $vendors
     * @param  array<int, string>  $missing
     * @param  array<int, string>  $searchTerms
     */
    public function paginate(int $perPage, ?string $search = null, array $mainTypes = [], array $types = [], array $vendors = [], array $missing = [], ?string $sortBy = null, string $sortDir = 'asc', array $purchaseOrderUuids = [], array $searchTerms = [], bool $includeArchived = false, ?string $poProductNovelty = null, ?string $ready = null, ?int $available = null, ?int $notArrived = null, ?int $reorder = null, bool $reorderGtOne = false): LengthAwarePaginator
    {
        [$sortColumn, $sortDir] = $this->resolveSort($sortBy, $sortDir);

        $q = Product::query()
            ->with(['sellingPrice', 'hljExternalContent', 'plamodExternalContent'])
            ->withCount(['imageAssets as plamod_image_assets_count']);
        $inboundOpenPoQtyExpr = $this->inboundOpenPoQtyExpression();
        $reorderQtyExpr = $this->reorderQtyExpression($inboundOpenPoQtyExpr);
        $totalOrderedReceivedQtyExpr = $this->totalOrderedReceivedQtyExpression();

        if (! $includeArchived) {
            $q->notArchived();
        }

        $q->addSelect([
            // Used by ProductResource to avoid N+1 checks and to align missing PDP logic
            // with the intuitive rule: any source with a non-empty description counts.
            DB::raw("EXISTS(
                select 1 from product_external_contents pec
                where pec.product_id = products.id
                  and pec.description_html is not null
                  and pec.description_html <> ''
            ) as pdp_has_description"),
            // Total cost across all PO lines for this product (sum of unit_cost * qty).
            // Uses received qty when present (>0), otherwise ordered qty.
            DB::raw('(
                select sum(
                    coalesce(poi.unit_cost, 0) *
                    (case
                        when coalesce(poi.qty_received, 0) > 0 then poi.qty_received
                        else coalesce(poi.qty_ordered, 0)
                    end)
                )
                from purchase_order_items poi
                where poi.product_id = products.id and poi.unit_cost is not null
            ) as po_total_cost'),
            DB::raw("{$inboundOpenPoQtyExpr} as inbound_open_po_qty"),
            DB::raw("{$reorderQtyExpr} as reorder_qty"),
            DB::raw("{$totalOrderedReceivedQtyExpr} as total_ordered_qty"),
            DB::raw("({$totalOrderedReceivedQtyExpr} - coalesce(products.available_qty, 0)) as total_sold_qty"),
        ]);

        $purchaseOrderUuids = array_values(array_unique(array_filter(array_map(
            static fn (string $v): string => trim($v),
            $purchaseOrderUuids,
        ), static fn (string $v): bool => $v !== '')));
        if ($purchaseOrderUuids !== []) {
            $q->whereExists(function ($sub) use ($purchaseOrderUuids): void {
                $sub->select(DB::raw('1'))
                    ->from('purchase_order_items as poi')
                    ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
                    ->whereColumn('poi.product_id', 'products.id')
                    ->whereIn('po.uuid', $purchaseOrderUuids);
            });
            $this->applyPurchaseOrderNoveltyFilter($q, $purchaseOrderUuids, $poProductNovelty);
        }
        $this->applyListQueryFilters($q, $search, $mainTypes, $types, $vendors, $searchTerms);
        $this->applyMissingFilters($q, $missing);
        $this->applyReadyFilter($q, $ready);
        if ($available !== null) {
            $q->whereRaw('coalesce(products.available_qty, 0) = ?', [$available]);
        }
        if ($notArrived !== null) {
            $q->whereRaw("{$inboundOpenPoQtyExpr} = ?", [$notArrived]);
        }
        if ($reorder !== null) {
            $q->whereRaw("{$reorderQtyExpr} = ?", [$reorder]);
        }
        if ($reorderGtOne) {
            $q->whereRaw("{$reorderQtyExpr} > 1");
        }

        if ($sortColumn === '__selling_price') {
            $expr = "(select nullif(trim(sps.selling_price), '') from product_selling_prices sps where sps.product_id = products.id limit 1)";
            $q->orderByRaw("{$expr} is null asc");
            $q->orderByRaw("cast({$expr} as decimal(10,2)) {$sortDir}");
            $q->orderBy('sku', 'asc');

            return $q->paginate(perPage: $perPage);
        }

        return $q->orderBy($sortColumn, $sortDir)->paginate(perPage: $perPage);
    }

    private function applyReadyFilter($q, ?string $ready): void
    {
        $ready = strtolower(trim((string) $ready));
        if ($ready === 'ready') {
            $q->where('is_ready', '=', true);

            return;
        }

        if ($ready === 'not_ready') {
            $q->where('is_ready', '=', false);
        }
    }

    /**
     * @param  array<int, string>  $purchaseOrderUuids
     */
    private function applyPurchaseOrderNoveltyFilter($q, array $purchaseOrderUuids, ?string $poProductNovelty): void
    {
        $novelty = strtolower(trim((string) $poProductNovelty));
        if (! in_array($novelty, ['new', 'existing'], true)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($purchaseOrderUuids), '?'));
        $overallMinPoIdSql = '(select min(poi_all.purchase_order_id) from purchase_order_items poi_all where poi_all.product_id = products.id)';
        $selectedMinPoIdSql = "(select min(poi_sel.purchase_order_id)
            from purchase_order_items poi_sel
            join purchase_orders po_sel on po_sel.id = poi_sel.purchase_order_id
            where poi_sel.product_id = products.id
              and po_sel.uuid in ({$placeholders}))";

        if ($novelty === 'new') {
            $q->whereRaw("{$overallMinPoIdSql} = {$selectedMinPoIdSql}", $purchaseOrderUuids);

            return;
        }

        $q->whereRaw("{$overallMinPoIdSql} < {$selectedMinPoIdSql}", $purchaseOrderUuids);
    }

    public function cursorForMissingInfo(?string $search = null, array $types = [], array $vendors = [], array $missing = []): LazyCollection
    {
        $q = Product::query()
            ->with(['sellingPrice', 'hljExternalContent', 'plamodExternalContent'])
            ->withCount(['imageAssets as plamod_image_assets_count']);

        $q->notArchived();

        $q->addSelect([
            DB::raw("EXISTS(
                select 1 from product_external_contents pec
                where pec.product_id = products.id
                  and pec.description_html is not null
                  and pec.description_html <> ''
            ) as pdp_has_description"),
        ]);

        $this->applyListQueryFilters($q, $search, [], $types, $vendors);
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
        if ($missing === []) {
            return;
        }

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

            $q->whereHas('imageAssets');

            $q->where(function ($sub): void {
                $sub->whereHas('externalContents', function ($q2): void {
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
                $q->whereDoesntHave('imageAssets');

                continue;
            }

            if ($flag === 'pdp_description') {
                $q->where(function ($sub): void {
                    $sub
                        ->whereDoesntHave('externalContents', function ($q2): void {
                            $q2->whereNotNull('description_html')->where('description_html', '<>', '');
                        });
                });

                continue;
            }

            if ($flag === 'not_ready') {
                $q->where('is_ready', '=', false);

                continue;
            }

            if ($flag === 'available_zero') {
                $q->where('available_qty', '=', 0);

                continue;
            }

            if ($flag === 'maintain_empty') {
                $q->whereNull('maintain_qty');

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

        $q = Product::query()->with(['sellingPrice', 'hljExternalContent', 'plamodExternalContent', 'externalContents']);
        $this->applyListQueryFilters($q, $search, [], $types);

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
            ->with(['sellingPrice', 'hljExternalContent', 'plamodExternalContent', 'externalContents'])
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
                'shopifyImageAssets',
            ])
            ->notArchived()
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
                'shopifyImageAssets',
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

    /**
     * @return array<int, string>
     */
    public function distinctMainTypes(): array
    {
        if (! Schema::hasColumn('products', 'main_type')) {
            return [];
        }

        /** @var array<int, string|null> $types */
        $types = Product::query()
            ->select('main_type')
            ->whereNotNull('main_type')
            ->distinct()
            ->orderBy('main_type')
            ->pluck('main_type')
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

    public function distinctGrades(): array
    {
        /** @var array<int, string|null> $grades */
        $grades = Product::query()
            ->select('grade')
            ->whereNotNull('grade')
            ->distinct()
            ->orderBy('grade')
            ->pluck('grade')
            ->all();

        $grades = array_map(static fn (?string $v): string => trim((string) $v), $grades);
        $grades = array_values(array_unique(array_filter($grades, static fn (string $v): bool => $v !== '')));
        sort($grades);

        return $grades;
    }

    public function distinctScales(): array
    {
        /** @var array<int, string|null> $scales */
        $scales = Product::query()
            ->select('scale')
            ->whereNotNull('scale')
            ->distinct()
            ->orderBy('scale')
            ->pluck('scale')
            ->all();

        $scales = array_map(static fn (?string $v): string => trim((string) $v), $scales);
        $scales = array_values(array_unique(array_filter($scales, static fn (string $v): bool => $v !== '')));
        sort($scales);

        return $scales;
    }

    public function distinctSeries(): array
    {
        /** @var array<int, string|null> $series */
        $series = Product::query()
            ->select('series')
            ->whereNotNull('series')
            ->distinct()
            ->orderBy('series')
            ->pluck('series')
            ->all();

        $series = array_map(static fn (?string $v): string => trim((string) $v), $series);
        $series = array_values(array_unique(array_filter($series, static fn (string $v): bool => $v !== '')));
        sort($series);

        return $series;
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
        $uuids = array_values(array_unique(array_filter(array_map('trim', $uuids), static fn (string $v): bool => $v !== '')));
        if ($uuids === []) {
            return 0;
        }

        return (int) DB::transaction(function () use ($uuids): int {
            /** @var \Illuminate\Support\Collection<int, array{id:int,uuid:string}> $rows */
            $rows = Product::query()
                ->whereIn('uuid', $uuids)
                ->get(['id', 'uuid'])
                ->map(static fn (Product $p): array => ['id' => (int) $p->id, 'uuid' => (string) $p->uuid]);

            $productIds = $rows->pluck('id')->all();
            if ($productIds === []) {
                return 0;
            }

            // Safety guard: do not allow deleting products with purchase order or inventory history.
            // (These are operational records; deleting them is too risky.)
            $hasPurchaseOrderItems = DB::table('purchase_order_items')->whereIn('product_id', $productIds)->exists();
            $hasInventoryLots = DB::table('inventory_lots')->whereIn('product_id', $productIds)->exists();
            $hasInventoryMovements = DB::table('inventory_movements')->whereIn('product_id', $productIds)->exists();

            if ($hasPurchaseOrderItems || $hasInventoryLots || $hasInventoryMovements) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(
                    'Cannot delete products that have purchase order or inventory history. Archive them instead.',
                );
            }

            // Detach or delete dependent records that have FK restrictions.
            DB::table('product_selling_prices')->whereIn('product_id', $productIds)->delete();
            DB::table('product_external_assets')->whereIn('product_id', $productIds)->delete();
            DB::table('product_external_contents')->whereIn('product_id', $productIds)->delete();

            DB::table('product_price_quotes')->whereIn('product_id', $productIds)->delete();
            DB::table('price_research_quote_reports')->whereIn('product_id', $productIds)->delete();
            DB::table('price_research_run_logs')->whereIn('product_id', $productIds)->delete();

            // Keep inventory check history, but unlink from deleted products.
            DB::table('inventory_check_items')->whereIn('product_id', $productIds)->update(['product_id' => null]);

            return Product::query()->whereIn('uuid', $uuids)->delete();
        });
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
