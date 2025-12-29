<?php

declare(strict_types=1);

namespace App\Services\Products\Hlj;

use Illuminate\Support\Str;

final class HljHtmlParser
{
    public function extractPdpUrlFromSearchHtml(string $html): ?string
    {
        // HLJ PDP URLs commonly end with a vendor code like "-ban901788".
        if (preg_match_all('/href=["\']((?:https:\\/\\/www\\.hlj\\.com)?\\/[^"\']+-[a-z]{2,4}\\d{5,})(?:\\?[^"\']*)?["\']/i', $html, $m) < 1) {
            return null;
        }

        /** @var array<int, string> $urls */
        $urls = array_map(static function (string $u): string {
            $u = preg_replace('/\\?.*$/', '', $u) ?? $u;
            if (Str::startsWith($u, '/')) {
                return 'https://www.hlj.com'.$u;
            }
            return $u;
        }, $m[1] ?? []);
        $urls = array_values(array_unique(array_filter($urls)));

        return $urls[0] ?? null;
    }

    /**
     * @return array{title: string|null, description_html: string|null}
     */
    public function extractTitleAndDescription(string $html): array
    {
        $title = $this->extractOgTitle($html);
        $descHtml = $this->extractFromProductDescriptionDiv($html);

        // Prefer the real PDP HTML description block when available, since it preserves formatting
        // (paragraphs, lists, line breaks). JSON-LD descriptions are usually plain text.
        if ($descHtml !== null) {
            // If we couldn't find a title via OG/title tags, fall back to JSON-LD for title only.
            if ($title === null) {
                $fromLdJson = $this->extractFromLdJson($html);
                $title = $fromLdJson['title'] ?? null;
            }

            return [
                'title' => $title,
                'description_html' => $descHtml,
            ];
        }

        $fromLdJson = $this->extractFromLdJson($html);
        if ($fromLdJson['description_html'] !== null || $fromLdJson['title'] !== null) {
            return $fromLdJson;
        }

        return [
            'title' => $title,
            'description_html' => $descHtml,
        ];
    }

    /**
     * @return array{title: string|null, description_html: string|null}
     */
    private function extractFromLdJson(string $html): array
    {
        if (preg_match_all('/<script[^>]+type=["\']application\\/ld\\+json["\'][^>]*>(.*?)<\\/script>/is', $html, $m) < 1) {
            return ['title' => null, 'description_html' => null];
        }

        foreach (($m[1] ?? []) as $json) {
            $decoded = json_decode((string) $json, true);
            if (! is_array($decoded)) {
                continue;
            }

            $product = $this->findProductNode($decoded);
            if (! is_array($product)) {
                continue;
            }

            $title = is_string($product['name'] ?? null) ? trim((string) $product['name']) : null;
            $desc = is_string($product['description'] ?? null) ? trim((string) $product['description']) : null;
            $desc = $this->decodeEntitiesDeep($desc);

            $descHtml = $this->normalizeDescriptionToHtml($desc);

            if ($title !== null || $descHtml !== null) {
                return ['title' => $title, 'description_html' => $descHtml];
            }
        }

        return ['title' => null, 'description_html' => null];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function findProductNode(array $node): ?array
    {
        $type = $node['@type'] ?? $node['type'] ?? null;
        $types = is_array($type) ? $type : [$type];
        foreach ($types as $t) {
            if (is_string($t) && Str::lower($t) === 'product') {
                return $node;
            }
        }

        foreach ($node as $v) {
            if (is_array($v)) {
                if ($this->isAssoc($v)) {
                    $found = $this->findProductNode($v);
                    if ($found !== null) return $found;
                } else {
                    foreach ($v as $vv) {
                        if (is_array($vv) && $this->isAssoc($vv)) {
                            $found = $this->findProductNode($vv);
                            if ($found !== null) return $found;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function extractOgTitle(string $html): ?string
    {
        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) === 1) {
            $t = html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5);
            $t = trim($t);
            return $t !== '' ? $t : null;
        }

        if (preg_match('/<title[^>]*>(.*?)<\\/title>/is', $html, $m) === 1) {
            $t = trim(strip_tags((string) $m[1]));
            return $t !== '' ? $t : null;
        }

        return null;
    }

    private function extractFromProductDescriptionDiv(string $html): ?string
    {
        if (preg_match('/<div\\s+class=["\']product-description["\']\\s*>(.*?)<\\/div>/is', $html, $m) !== 1) {
            return null;
        }

        $inner = (string) $m[1];
        // Remove heading.
        $inner = preg_replace('/<h3\\b[^>]*>\\s*Description\\s*<\\/h3>/i', '', $inner) ?? $inner;

        return $this->sanitizeHtml($inner);
    }

    private function normalizeDescriptionToHtml(?string $text): ?string
    {
        if ($text === null) return null;
        $text = trim($text);
        if ($text === '') return null;

        // Convert blank-line separated blocks into paragraphs.
        $blocks = preg_split("/\\R\\s*\\R+/u", $text) ?: [];
        $blocks = array_values(array_filter(array_map('trim', $blocks)));
        if ($blocks === []) return null;

        $parts = array_map(static fn (string $b): string => '<p>'.e($b).'</p>', $blocks);
        return implode('', $parts);
    }

    private function sanitizeHtml(string $html): ?string
    {
        $html = trim($html);
        if ($html === '') return null;

        $html = $this->decodeEntitiesDeep($html) ?? '';
        $html = trim($html);
        if ($html === '') return null;

        // Keep a conservative tag allowlist; strip attributes by normalizing opening tags.
        $html = strip_tags($html, '<p><br><b><strong><em><i><ul><ol><li>');
        $html = preg_replace('/<(p|ul|ol|li)\\b[^>]*>/i', '<$1>', $html) ?? $html;
        $html = preg_replace('/<br\\b[^>]*>/i', '<br>', $html) ?? $html;

        $html = trim($html);
        return $html !== '' ? $html : null;
    }

    private function decodeEntitiesDeep(?string $value): ?string
    {
        if ($value === null) return null;
        $value = trim($value);
        if ($value === '') return null;

        // Some sources double/triple-encode entities (e.g. "&amp;amp;nbsp;" or "&amp;amp;#39;").
        // Decode a few passes until stable.
        $prev = null;
        $cur = $value;
        for ($i = 0; $i < 3; $i++) {
            $prev = $cur;
            $cur = html_entity_decode($cur, ENT_QUOTES | ENT_HTML5);
            if ($cur === $prev) break;
        }

        $cur = trim($cur);
        return $cur !== '' ? $cur : null;
    }

    /**
     * @param  array<mixed>  $arr
     */
    private function isAssoc(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}


