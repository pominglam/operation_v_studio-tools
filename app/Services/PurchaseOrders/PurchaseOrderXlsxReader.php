<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\Services\PurchaseOrders\Exceptions\PurchaseOrderImportException;
use ZipArchive;

final class PurchaseOrderXlsxReader
{
    /**
     * @return array<int, array<int, string>>
     */
    public function read(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new PurchaseOrderImportException('XLSX import requires the PHP zip extension.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new PurchaseOrderImportException('Could not read XLSX file.');
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) {
                throw new PurchaseOrderImportException('XLSX file is missing worksheet data.');
            }

            return $this->parseSheetRows($sheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            return [];
        }

        $doc->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        /** @var array<int, string> $out */
        $out = [];
        $items = $doc->xpath('//m:si') ?: [];
        foreach ($items as $si) {
            $si->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $parts = $si->xpath('.//m:t') ?: [];
            $text = '';
            foreach ($parts as $part) {
                $text .= (string) $part;
            }
            $out[] = $text;
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array<int, array<int, string>>
     */
    private function parseSheetRows(string $sheetXml, array $sharedStrings): array
    {
        $doc = simplexml_load_string($sheetXml);
        if ($doc === false) {
            throw new PurchaseOrderImportException('Could not parse XLSX worksheet.');
        }

        $doc->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rowNodes = $doc->xpath('//m:sheetData/m:row') ?: [];

        /** @var array<int, array<int, string>> $rows */
        $rows = [];
        foreach ($rowNodes as $row) {
            $row->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            /** @var array<int, string> $cellsByCol */
            $cellsByCol = [];
            $maxCol = -1;
            foreach ($row->xpath('m:c') ?: [] as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                $colIndex = $this->columnIndexFromCellRef($ref);
                if ($colIndex < 0) {
                    continue;
                }
                $value = $this->cellValue($cell, $sharedStrings);
                $cellsByCol[$colIndex] = $value;
                $maxCol = max($maxCol, $colIndex);
            }

            if ($maxCol < 0) {
                $rows[] = [];

                continue;
            }

            $line = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $line[] = $cellsByCol[$i] ?? '';
            }
            $rows[] = $line;
        }

        return $rows;
    }

    private function columnIndexFromCellRef(string $ref): int
    {
        if ($ref === '' || ! preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) {
            return -1;
        }

        $letters = $m[1];
        $index = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     */
    private function cellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $type = (string) ($cell['t'] ?? '');
        $valueNode = $cell->xpath('m:v');
        if ($valueNode === false || $valueNode === [] || ! isset($valueNode[0])) {
            return '';
        }

        $raw = (string) $valueNode[0];
        if ($type === 's') {
            $idx = (int) $raw;

            return $sharedStrings[$idx] ?? '';
        }

        if (is_numeric($raw)) {
            return $this->formatNumericCellValue($raw);
        }

        return trim($raw);
    }

    private function formatNumericCellValue(string $raw): string
    {
        if (! is_numeric($raw)) {
            return trim($raw);
        }

        if (extension_loaded('bcmath')) {
            /** @var string $normalized */
            $normalized = bcadd($raw, '0', 6);

            if (! str_contains($normalized, '.')) {
                return $normalized;
            }

            $normalized = rtrim(rtrim($normalized, '0'), '.');

            return $normalized === '' ? '0' : $normalized;
        }

        return rtrim(rtrim(number_format((float) $raw, 6, '.', ''), '0'), '.') ?: '0';
    }
}
