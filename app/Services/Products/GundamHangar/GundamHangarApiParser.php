<?php

declare(strict_types=1);

namespace App\Services\Products\GundamHangar;

final class GundamHangarApiParser
{
    /**
     * @return array<int, array{
     *   title:string,
     *   slug:string,
     *   description_html:?string,
     *   featured_image:?string,
     *   image_number:int,
     *   attributes:array<string,string>
     * }>
     */
    public function extractSearchCandidatesFromJson(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }
        $rows = $decoded['data'] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($title === '' || $slug === '') {
                continue;
            }

            $attrs = [];
            $rawAttrs = $row['attributes'] ?? null;
            if (is_array($rawAttrs)) {
                foreach ($rawAttrs as $a) {
                    if (! is_array($a)) {
                        continue;
                    }
                    $name = trim((string) ($a['name'] ?? ''));
                    $value = trim((string) (($a['pivot']['value'] ?? null) ?? ''));
                    if ($name === '' || $value === '') {
                        continue;
                    }
                    $key = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $name));
                    $key = trim($key, '_');
                    if ($key === '') {
                        continue;
                    }
                    $attrs[$key] = $value;
                }
            }

            $imageNumber = (int) ($row['image_number'] ?? 0);
            if ($imageNumber < 0) {
                $imageNumber = 0;
            }

            $description = trim((string) ($row['description'] ?? ''));
            $out[] = [
                'title' => $title,
                'slug' => $slug,
                'description_html' => $description !== '' ? $description : null,
                'featured_image' => ($row['featured_image'] ?? null) ? trim((string) $row['featured_image']) : null,
                'image_number' => $imageNumber,
                'attributes' => $attrs,
            ];
        }

        return $out;
    }

    /**
     * @return array{
     *   title:string,
     *   slug:string,
     *   description_html:?string,
     *   attributes:array<string,string>,
     *   image_urls:array<int,string>
     * }|null
     */
    public function extractProductDetailFromJson(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return null;
        }

        $rows = $decoded['data'] ?? null;
        if (! is_array($rows) || ! isset($rows[0]) || ! is_array($rows[0])) {
            return null;
        }
        $row = $rows[0];

        $title = trim((string) ($row['title'] ?? ''));
        $slug = trim((string) ($row['slug'] ?? ''));
        if ($title === '' || $slug === '') {
            return null;
        }

        $attrs = [];
        $rawAttrs = $row['attributes'] ?? null;
        if (is_array($rawAttrs)) {
            foreach ($rawAttrs as $a) {
                if (! is_array($a)) {
                    continue;
                }
                $name = trim((string) ($a['name'] ?? ''));
                $value = trim((string) (($a['pivot']['value'] ?? null) ?? ''));
                if ($name === '' || $value === '') {
                    continue;
                }
                $key = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $name));
                $key = trim($key, '_');
                if ($key === '') {
                    continue;
                }
                $attrs[$key] = $value;
            }
        }

        $description = trim((string) ($row['description'] ?? ''));
        $images = $decoded['images'] ?? [];
        $imageUrls = [];
        if (is_array($images)) {
            foreach ($images as $img) {
                $u = trim((string) $img);
                if ($u !== '') {
                    $imageUrls[] = $u;
                }
            }
        }

        return [
            'title' => $title,
            'slug' => $slug,
            'description_html' => $description !== '' ? $description : null,
            'attributes' => $attrs,
            'image_urls' => array_values(array_unique($imageUrls)),
        ];
    }

    /**
     * @param  array<int, array{
     *   title:string,
     *   slug:string,
     *   description_html:?string,
     *   featured_image:?string,
     *   image_number:int,
     *   attributes:array<string,string>
     * }>  $candidates
     * @return array{
     *   title:string,
     *   slug:string,
     *   description_html:?string,
     *   featured_image:?string,
     *   image_number:int,
     *   attributes:array<string,string>
     * }|null
     */
    public function pickBestCandidate(array $candidates, string $query): ?array
    {
        if ($candidates === []) {
            return null;
        }
        $qTokens = $this->tokens($query);
        if ($qTokens === []) {
            return $candidates[0] ?? null;
        }

        $best = null;
        $bestScore = -1;
        foreach ($candidates as $c) {
            $tTokens = $this->tokens($c['title']);
            $joined = implode(' ', $tTokens);
            $score = 0;
            foreach ($qTokens as $t) {
                if (in_array($t, $tTokens, true)) {
                    $score += 2;
                } elseif ($joined !== '' && str_contains($joined, $t)) {
                    $score += 1;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $c;
            }
        }

        return $best ?? ($candidates[0] ?? null);
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $s): array
    {
        $s = mb_strtolower(trim($s));
        if ($s === '') {
            return [];
        }
        $s = preg_replace('/\b\d+\s*\/\s*\d+\b/u', ' ', $s) ?? $s;
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        if ($s === '') {
            return [];
        }

        return array_values(array_unique(array_filter(explode(' ', $s), static fn (string $t): bool => $t !== '')));
    }
}
