<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Support\Products\Storefront\StorefrontTag;

final class ModelKitStorefrontIndexTagMapper
{
    /**
     * @param  array<int, string>  $tags
     * @return array{grade: string, sublines: string, series: string, lines: string}
     */
    public function map(array $tags): array
    {
        $grade = '';
        $sublines = [];
        $series = [];
        $lines = [];

        foreach ($tags as $tag) {
            $this->appendTag($tag, $grade, $sublines, $series, $lines);
        }

        return [
            'grade' => $grade,
            'sublines' => implode(',', array_values(array_unique($sublines))),
            'series' => implode(',', array_values(array_unique($series))),
            'lines' => implode(',', array_values(array_unique($lines))),
        ];
    }

    /**
     * @param  array<int, string>  $sublines
     * @param  array<int, string>  $series
     * @param  array<int, string>  $lines
     */
    private function appendTag(string $tag, string &$grade, array &$sublines, array &$series, array &$lines): void
    {
        if (str_starts_with($tag, StorefrontTag::MK_GRADE_PREFIX)) {
            $grade = substr($tag, strlen(StorefrontTag::MK_GRADE_PREFIX));

            return;
        }

        if (str_starts_with($tag, StorefrontTag::MK_SUBLINE_PREFIX)) {
            $sublines[] = substr($tag, strlen(StorefrontTag::MK_SUBLINE_PREFIX));

            return;
        }

        if (str_starts_with($tag, StorefrontTag::MK_SERIES_PREFIX)) {
            $series[] = substr($tag, strlen(StorefrontTag::MK_SERIES_PREFIX));

            return;
        }

        if (str_starts_with($tag, 'mk:line:')) {
            $lines[] = substr($tag, strlen('mk:line:'));
        }
    }
}
