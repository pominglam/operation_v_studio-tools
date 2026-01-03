<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\InventoryChecks\InventoryCheckRepository;
use App\DAL\Products\ProductRepository;
use App\Models\InventoryCheck;
use App\Models\InventoryCheckItem;
use App\Models\Product;
use App\Services\Inventory\InventoryFifoDeductionService;
use App\Services\Products\Exceptions\InvalidProductImportFileException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class InventoryCheckImportService
{
    public const string COL_HANDLE = 'Handle';
    public const string COL_VENDOR = 'Vendor';
    public const string COL_SKU = 'SKU';
    public const string COL_TYPE = 'Type';
    public const string COL_PRODUCT_NAME = 'Product Name';
    public const string COL_ENGLISH_NAME = 'English name';
    public const string COL_AVAILABLE_AMOUNT = 'Available amount';
    public const string COL_QUANTITY_IN_STORE = 'Quantity in store';
    public const string COL_DIFFERENCE = 'Difference';
    public const string COL_NOTES = 'Notes';

    public function __construct(
        private readonly ProductRepository $products,
        private readonly InventoryCheckRepository $inventoryChecks,
        private readonly InventoryFifoDeductionService $fifo,
    ) {}

    /**
     * @return array{
     *   inventory_check: array{id:int, uuid:string},
     *   uploaded_file_path: string,
     *   rows_parsed: int,
     *   matched: int,
     *   applied: int,
     *   not_applied: int,
     *   unmatched: int,
     *   ambiguous: int,
     *   unmatched_rows: array<int, array{handle:string, vendor:string, sku:string, reason:string}>,
     *   ambiguous_rows: array<int, array{handle:string, vendor:string, sku:string, reason:string}>,
     *   not_applied_rows: array<int, array{handle:string, vendor:string, sku:string, reason:string}>,
     * }
     */
    public function import(UploadedFile $file): array
    {
        $uploadedFilePath = $this->storeUploadedFile($file);

        return DB::transaction(function () use ($file, $uploadedFilePath): array {
            $check = new InventoryCheck([
                'source' => 'inventory_export_barcoded',
                'uploaded_file_path' => $uploadedFilePath,
            ]);
            $this->inventoryChecks->create($check);

            $counts = [
                'rows_parsed' => 0,
                'matched' => 0,
                'applied' => 0,
                'not_applied' => 0,
                'unmatched' => 0,
                'ambiguous' => 0,
            ];

            $unmatchedRows = [];
            $ambiguousRows = [];
            $notAppliedRows = [];

            foreach ($this->iterateRows($file) as $row) {
                $counts['rows_parsed']++;

                $match = $this->matchProduct($row);
                $rowWarning = $this->rowWarning($row);
                if ($rowWarning !== null) {
                    // If the count is missing/invalid, do not persist "Difference" (it becomes meaningless).
                    $row['difference'] = null;
                }
                $item = new InventoryCheckItem([
                    'inventory_check_id' => $check->id,
                    'product_id' => $match['product']?->id,
                    'handle' => $row['handle'],
                    'vendor' => $row['vendor'],
                    'sku' => $row['sku'],
                    'type' => $row['type'],
                    'product_name' => $row['product_name'],
                    'english_name' => $row['english_name'],
                    'available_amount' => $row['available_amount'],
                    'quantity_in_store' => $row['quantity_in_store'],
                    'difference' => $row['difference'],
                    'notes' => $row['notes'],
                    'match_status' => $match['status'],
                    'match_error' => $match['error'] ?? $rowWarning,
                ]);

                if ($match['status'] === 'matched' && $match['product'] instanceof Product) {
                    $counts['matched']++;

                    if ($rowWarning !== null) {
                        // Warning: we still allow other product updates (e.g., Stedi English name),
                        // but we do not apply available_qty when Quantity in store is missing/invalid.
                        $counts['not_applied']++;
                        $notAppliedRows[] = [
                            'handle' => $row['handle'],
                            'vendor' => $row['vendor'],
                            'sku' => $row['sku'],
                            'reason' => $rowWarning,
                        ];
                    }

                    $apply = $this->applyUpdates($match['product'], $row, (string) $check->uuid);
                    if ($apply['changed']) {
                        $item->applied = true;
                        $item->applied_at = now();
                        $counts['applied']++;
                    }
                    if ($apply['fifo_underflow'] > 0) {
                        $msg = 'FIFO underflow: went negative by '.$apply['fifo_underflow'].'.';
                        $item->match_error = trim(($item->match_error ? $item->match_error.'; ' : '').$msg);
                    }
                } elseif ($match['status'] === 'ambiguous') {
                    $counts['ambiguous']++;
                    $ambiguousRows[] = [
                        'handle' => $row['handle'],
                        'vendor' => $row['vendor'],
                        'sku' => $row['sku'],
                        'reason' => $match['error'] ?? 'Ambiguous match.',
                    ];
                } else {
                    $counts['unmatched']++;
                    $unmatchedRows[] = [
                        'handle' => $row['handle'],
                        'vendor' => $row['vendor'],
                        'sku' => $row['sku'],
                        'reason' => $match['error'] ?? 'No match.',
                    ];
                }

                $this->inventoryChecks->createItem($item);
            }

            return [
                'inventory_check' => [
                    'id' => $check->id,
                    'uuid' => $check->uuid,
                ],
                'uploaded_file_path' => $uploadedFilePath,
                'rows_parsed' => $counts['rows_parsed'],
                'matched' => $counts['matched'],
                'applied' => $counts['applied'],
                'not_applied' => $counts['not_applied'],
                'unmatched' => $counts['unmatched'],
                'ambiguous' => $counts['ambiguous'],
                'unmatched_rows' => $unmatchedRows,
                'ambiguous_rows' => $ambiguousRows,
                'not_applied_rows' => $notAppliedRows,
            ];
        });
    }

    private function storeUploadedFile(UploadedFile $file): string
    {
        $ts = now()->format('Ymd_His');
        $name = "inventory_check_{$ts}.csv";

        /** @var string $path */
        $path = $file->storeAs('imports/inventory-check', $name, 'local');

        return $path;
    }

    /**
     * @return \Generator<int, array{
     *   handle: string,
     *   vendor: string,
     *   sku: string,
     *   type: string,
     *   product_name: string,
     *   english_name: string,
     *   available_amount: int|null,
     *   quantity_in_store: int|null,
     *   difference: int|null,
     *   notes: string,
     * }>
     */
    private function iterateRows(UploadedFile $file): \Generator
    {
        $path = $file->getRealPath();
        if ($path === false) {
            throw new InvalidProductImportFileException('Uploaded file is not readable.');
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new InvalidProductImportFileException('Uploaded file is not readable.');
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                throw new InvalidProductImportFileException('CSV is empty.');
            }

            $map = $this->headerMap($header);
            $this->assertRequiredColumns($map);

            while (($row = fgetcsv($handle)) !== false) {
                if ($this->isBlankRow($row)) {
                    continue;
                }

                $sku = $this->stringAt($row, $map[self::COL_SKU]);
                $vendor = $this->stringAt($row, $map[self::COL_VENDOR]);
                if ($sku === '') {
                    continue;
                }

                yield [
                    'handle' => $this->fixEncodingIfNeeded(array_key_exists(self::COL_HANDLE, $map) ? $this->stringAt($row, $map[self::COL_HANDLE]) : ''),
                    'vendor' => $this->fixEncodingIfNeeded($vendor),
                    'sku' => $this->fixEncodingIfNeeded($sku),
                    'type' => $this->fixEncodingIfNeeded(array_key_exists(self::COL_TYPE, $map) ? $this->stringAt($row, $map[self::COL_TYPE]) : ''),
                    'product_name' => $this->fixEncodingIfNeeded(array_key_exists(self::COL_PRODUCT_NAME, $map) ? $this->stringAt($row, $map[self::COL_PRODUCT_NAME]) : ''),
                    'english_name' => $this->fixEncodingIfNeeded(array_key_exists(self::COL_ENGLISH_NAME, $map) ? $this->stringAt($row, $map[self::COL_ENGLISH_NAME]) : ''),
                    'available_amount' => array_key_exists(self::COL_AVAILABLE_AMOUNT, $map) ? $this->intOrNullAt($row, $map[self::COL_AVAILABLE_AMOUNT]) : null,
                    'quantity_in_store_raw' => $this->fixEncodingIfNeeded($this->stringAt($row, $map[self::COL_QUANTITY_IN_STORE])),
                    'quantity_in_store' => $this->nonNegativeIntOrNullAt($row, $map[self::COL_QUANTITY_IN_STORE]),
                    'difference' => array_key_exists(self::COL_DIFFERENCE, $map) ? $this->intAllowNegativeOrNullAt($row, $map[self::COL_DIFFERENCE]) : null,
                    'notes' => $this->fixEncodingIfNeeded(array_key_exists(self::COL_NOTES, $map) ? $this->stringAt($row, $map[self::COL_NOTES]) : ''),
                ];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array{handle:string,vendor:string,sku:string}  $row
     * @return array{status:'matched'|'unmatched'|'ambiguous', product:Product|null, error:string|null}
     */
    private function matchProduct(array $row): array
    {
        $handle = trim($row['handle']);
        if ($handle !== '') {
            $matches = $this->products->findByHandle($handle);
            if ($matches->count() === 1) {
                /** @var Product $p */
                $p = $matches->first();
                return ['status' => 'matched', 'product' => $p, 'error' => null];
            }
            if ($matches->count() > 1) {
                return ['status' => 'ambiguous', 'product' => null, 'error' => 'Multiple products share the same handle.'];
            }
            return ['status' => 'unmatched', 'product' => null, 'error' => 'No product found for handle.'];
        }

        $sku = trim($row['sku']);
        $vendor = trim($row['vendor']);
        if ($vendor === '') {
            return ['status' => 'unmatched', 'product' => null, 'error' => 'Missing Vendor (handle is blank).'];
        }

        $matches = $this->products->findBySkuAndVendor($sku, $vendor);
        if ($matches->count() === 1) {
            /** @var Product $p */
            $p = $matches->first();
            return ['status' => 'matched', 'product' => $p, 'error' => null];
        }
        if ($matches->count() > 1) {
            return ['status' => 'ambiguous', 'product' => null, 'error' => 'Multiple products match SKU + Vendor.'];
        }

        return ['status' => 'unmatched', 'product' => null, 'error' => 'No product found for SKU + Vendor.'];
    }

    /**
     * @param  array{
     *   handle:string,
     *   vendor:string,
     *   sku:string,
     *   english_name:string,
     *   quantity_in_store:int|null,
     *   quantity_in_store_raw:string
     * }  $row
     */
    private function applyUpdates(Product $product, array $row, string $inventoryCheckUuid): array
    {
        $changed = false;
        $underflow = 0;

        if ($row['quantity_in_store'] !== null && $row['quantity_in_store'] >= 0) {
            $previous = (int) ($product->available_qty ?? 0);
            $next = (int) $row['quantity_in_store'];
            $delta = $previous - $next;
            if ($delta > 0) {
                $result = $this->fifo->deductForInventoryCheck((int) $product->id, $delta, $inventoryCheckUuid);
                $underflow = (int) ($result['underflow'] ?? 0);
            }
            if ($product->available_qty !== $row['quantity_in_store']) {
                $product->available_qty = $row['quantity_in_store'];
                $changed = true;
            }
        }

        $englishName = trim($row['english_name']);
        $isStedi = strcasecmp((string) ($product->vendor ?? ''), 'Stedi') === 0 || strcasecmp((string) ($row['vendor'] ?? ''), 'Stedi') === 0;
        if ($isStedi && $englishName !== '' && $product->description !== $englishName) {
            $product->description = $englishName;
            $changed = true;
        }

        if ($changed) {
            $this->products->save($product);
        }

        return ['changed' => $changed, 'fifo_underflow' => $underflow];
    }

    /**
     * @param  array{
     *   quantity_in_store:int|null,
     *   quantity_in_store_raw:string
     * }  $row
     */
    private function rowWarning(array $row): ?string
    {
        $raw = trim((string) ($row['quantity_in_store_raw'] ?? ''));
        if ($raw === '') {
            return 'Missing Quantity in store (available not updated).';
        }
        if ($row['quantity_in_store'] === null) {
            return 'Invalid Quantity in store (available not updated).';
        }
        return null;
    }

    /**
     * @param  array<int, string>  $header
     * @return array<string, int>
     */
    private function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $i => $name) {
            $key = trim((string) $name);
            if ($i === 0) {
                $key = ltrim($key, "\xEF\xBB\xBF");
            }
            if ($key === '') {
                continue;
            }
            $map[$key] = $i;
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $map
     */
    private function assertRequiredColumns(array $map): void
    {
        foreach ([self::COL_VENDOR, self::COL_SKU, self::COL_QUANTITY_IN_STORE] as $required) {
            if (! array_key_exists($required, $map)) {
                throw new InvalidProductImportFileException('Missing required column: '.$required);
            }
        }
    }

    /**
     * @param  array<int, string>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function stringAt(array $row, int $idx): string
    {
        if (! array_key_exists($idx, $row)) {
            return '';
        }

        return trim((string) $row[$idx]);
    }

    /**
     * @param  array<int, string>  $row
     */
    private function nonNegativeIntOrNullAt(array $row, int $idx): ?int
    {
        $raw = $this->stringAt($row, $idx);
        if ($raw === '') {
            return null;
        }

        if (! preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
            return null;
        }

        $val = (int) round((float) $raw);
        if ($val < 0) {
            return null;
        }

        return $val;
    }

    /**
     * For inventory difference, negatives are valid (counted < available).
     *
     * @param  array<int, string>  $row
     */
    private function intAllowNegativeOrNullAt(array $row, int $idx): ?int
    {
        $raw = $this->stringAt($row, $idx);
        if ($raw === '') {
            return null;
        }

        if (! preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
            return null;
        }

        return (int) round((float) $raw);
    }

    private function fixEncodingIfNeeded(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Heuristic: common mojibake markers when UTF-8 bytes were double-encoded / mis-decoded.
        if (! preg_match('/(Ã.|Â.|â..|ï..|�)/u', $value)) {
            return $value;
        }

        // utf8_encode(utf8_decode()) often reverses the common "PokÃ©mon" / garbled CJK cases.
        $fixed = utf8_encode(utf8_decode($value));
        $fixed = trim($fixed);
        if ($fixed === '' || $fixed === $value) {
            return $value;
        }

        $markersBefore = preg_match_all('/(Ã.|Â.|â..|ï..|�)/u', $value) ?: 0;
        $markersAfter = preg_match_all('/(Ã.|Â.|â..|ï..|�)/u', $fixed) ?: 0;

        // Prefer the transformed value if it reduces mojibake markers OR yields CJK characters.
        $hasHanAfter = preg_match('/\p{Han}/u', $fixed) === 1;
        $hasHanBefore = preg_match('/\p{Han}/u', $value) === 1;

        if ($markersAfter < $markersBefore || ($hasHanAfter && ! $hasHanBefore)) {
            return $fixed;
        }

        return $value;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function intOrNullAt(array $row, int $idx): ?int
    {
        // Backwards compatible helper for non-negative ints (used for available_amount snapshot).
        return $this->nonNegativeIntOrNullAt($row, $idx);
    }
}




