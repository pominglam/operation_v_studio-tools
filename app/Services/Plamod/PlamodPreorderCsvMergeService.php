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
                $fileHeader = fgetcsv($handle);
                if (! is_array($fileHeader)) {
                    continue;
                }

                if ($header === null) {
                    $header = $fileHeader;
                }

                $skuIndex = $this->skuIndex($fileHeader);
                if ($skuIndex === null) {
                    continue;
                }

                while (($row = fgetcsv($handle)) !== false) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $sku = trim((string) ($row[$skuIndex] ?? ''));
                    if ($sku === '') {
                        continue;
                    }

                    $rowsBySku[$sku] = $row;
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
            fputcsv($out, $header);
            foreach ($rowsBySku as $row) {
                fputcsv($out, $row);
            }
        } finally {
            fclose($out);
        }

        return $destinationStoragePath;
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
