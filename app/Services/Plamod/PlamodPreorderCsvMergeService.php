<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use Illuminate\Support\Facades\Storage;

final class PlamodPreorderCsvMergeService
{
    /**
     * @param  array<int, string>  $csvStoragePaths
     */
    public function mergeStoragePaths(array $csvStoragePaths, string $destinationStoragePath): string
    {
        $paths = array_values(array_filter(array_map('strval', $csvStoragePaths)));
        if ($paths === []) {
            throw new \InvalidArgumentException('At least one CSV path is required for merge.');
        }

        if (count($paths) === 1) {
            return $paths[0];
        }

        $disk = Storage::disk('local');
        /** @var array<int, string|null>|null $header */
        $header = null;
        /** @var array<string, array<int, string|null>> $rowsBySku */
        $rowsBySku = [];

        foreach ($paths as $path) {
            if (! $disk->exists($path)) {
                throw new \InvalidArgumentException("CSV not found at {$path}");
            }

            $handle = fopen($disk->path($path), 'rb');
            if ($handle === false) {
                throw new \RuntimeException("Could not open CSV at {$path}");
            }

            try {
                $fileHeader = fgetcsv($handle, escape: '\\');
                if (! is_array($fileHeader)) {
                    continue;
                }

                if ($header === null || count($fileHeader) > count($header)) {
                    $header = $fileHeader;
                }

                $skuIndex = $this->skuIndex($fileHeader);
                if ($skuIndex === null) {
                    continue;
                }

                $fileMap = $this->headerMap($fileHeader);

                while (($row = fgetcsv($handle, escape: '\\')) !== false) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $sku = trim((string) ($row[$skuIndex] ?? ''));
                    if (! PlamodPreorderSkuValidator::isValid($sku)) {
                        continue;
                    }

                    $rowsBySku[$sku] = $this->overlayRow(
                        $header,
                        $rowsBySku[$sku] ?? null,
                        $fileMap,
                        $row,
                    );
                }
            } finally {
                fclose($handle);
            }
        }

        if ($header === null || $rowsBySku === []) {
            throw new \RuntimeException('No preorder rows found to merge.');
        }

        $disk->makeDirectory(dirname($destinationStoragePath));

        $out = fopen($disk->path($destinationStoragePath), 'wb');
        if ($out === false) {
            throw new \RuntimeException("Could not write merged CSV to {$destinationStoragePath}");
        }

        try {
            fputcsv($out, $header, escape: '\\');
            foreach ($rowsBySku as $row) {
                fputcsv($out, $this->padRow($header, $row), escape: '\\');
            }
        } finally {
            fclose($out);
        }

        return $destinationStoragePath;
    }

    /**
     * @param  array<int, string|null>  $header
     * @param  array<int, string|null>|null  $existing
     * @param  array<string, int>  $fileMap
     * @param  array<int, string|null>  $row
     * @return array<int, string|null>
     */
    private function overlayRow(array $header, ?array $existing, array $fileMap, array $row): array
    {
        $canonicalMap = $this->headerMap($header);
        $merged = $existing ?? array_fill(0, count($header), null);

        foreach ($canonicalMap as $column => $canonicalIndex) {
            if (! isset($fileMap[$column])) {
                continue;
            }

            $cell = trim((string) ($row[$fileMap[$column]] ?? ''));
            if ($cell === '') {
                continue;
            }

            $merged[$canonicalIndex] = $row[$fileMap[$column]];
        }

        return $merged;
    }

    /**
     * @param  array<int, string|null>  $header
     * @param  array<int, string|null>  $row
     * @return array<int, string|null>
     */
    private function padRow(array $header, array $row): array
    {
        $padded = array_fill(0, count($header), null);
        foreach ($row as $index => $value) {
            if ($index < count($header)) {
                $padded[$index] = $value;
            }
        }

        return $padded;
    }

    /**
     * @param  array<int, string|null>  $header
     * @return array<string, int>
     */
    private function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $name) {
            if (! is_string($name)) {
                continue;
            }
            $map[trim($name)] = (int) $idx;
        }

        return $map;
    }

    /**
     * @param  array<int, string|null>  $header
     */
    private function skuIndex(array $header): ?int
    {
        foreach ($header as $idx => $name) {
            if (is_string($name) && strcasecmp(trim($name), 'SKU') === 0) {
                return (int) $idx;
            }
        }

        return null;
    }
}
