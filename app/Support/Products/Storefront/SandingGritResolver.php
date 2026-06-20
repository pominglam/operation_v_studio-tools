<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

final class SandingGritResolver
{
    /**
     * @return array<int, string> Bucket slugs: coarse, medium, fine, polish
     */
    public function bucketsFromText(string $text): array
    {
        $grits = $this->extractGritValues($text);
        $buckets = [];

        foreach ($grits as $grit) {
            $bucket = $this->bucketForGrit($grit);
            if ($bucket !== null) {
                $buckets[$bucket] = true;
            }
        }

        $order = ['coarse', 'medium', 'fine', 'polish'];
        $out = [];
        foreach ($order as $bucket) {
            if (isset($buckets[$bucket])) {
                $out[] = $bucket;
            }
        }

        return $out;
    }

    /**
     * @return array<int, int>
     */
    private function extractGritValues(string $text): array
    {
        if (! preg_match_all('/\b(\d{3,5})\b/', $text, $matches)) {
            return [];
        }

        $grits = [];
        foreach ($matches[1] as $match) {
            $value = (int) $match;
            if ($value >= 320) {
                $grits[$value] = $value;
            }
        }

        return array_values($grits);
    }

    private function bucketForGrit(int $grit): ?string
    {
        if ($grit >= 1500) {
            return 'polish';
        }

        if ($grit >= 1000) {
            return 'fine';
        }

        if ($grit >= 600) {
            return 'medium';
        }

        if ($grit >= 320) {
            return 'coarse';
        }

        return null;
    }
}
