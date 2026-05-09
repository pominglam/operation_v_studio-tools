<?php

declare(strict_types=1);

namespace App\Services\Products\Argama;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class ArgamaHtmlParser
{
    public const string BASE_URL = 'https://argamahobby.com';

    /**
     * @return array<int, array{url: string, title: string}>
     */
    public function extractSearchCandidatesFromSearchHtml(string $html): array
    {
        $doc = $this->loadHtml($html);
        $xpath = new DOMXPath($doc);

        /** @var array<int, array{url: string, title: string}> $out */
        $out = [];
        $seen = [];

        /** @var \DOMNodeList<DOMElement> $links */
        $links = $xpath->query('//a[@href]') ?? new \DOMNodeList;
        foreach ($links as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $url = $this->toAbsoluteUrl($href);
            if ($url === null || ! str_contains($url, '/products/')) {
                continue;
            }
            if (isset($seen[$url])) {
                continue;
            }

            $title = $this->candidateTitleFromLink($a);
            if ($title === '') {
                continue;
            }

            $seen[$url] = true;
            $out[] = ['url' => $url, 'title' => $title];
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    public function extractImageUrlsFromPdpHtml(string $html): array
    {
        /** @var array<int, string> $urls */
        $urls = [];
        $seen = [];

        $register = function (string $raw) use (&$urls, &$seen): void {
            $u = $this->normalizeRawUrl($raw);
            if ($u === null) {
                return;
            }
            if (! $this->isProductImageUrl($u)) {
                return;
            }
            $u = $this->stripShopifySizeSuffix($u);
            $key = $this->dedupKey($u);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $urls[] = $u;
        };

        $doc = $this->loadHtml($html);
        $xpath = new DOMXPath($doc);

        $imgAttrs = ['src', 'data-src', 'data-original', 'data-zoom', 'data-image', 'href'];
        /** @var \DOMNodeList<DOMElement> $imgs */
        $imgs = $xpath->query('//img | //a[@href] | //source | //meta[contains(@property, "image") or contains(@name, "image")]') ?? new \DOMNodeList;
        foreach ($imgs as $node) {
            foreach ($imgAttrs as $attr) {
                $value = trim((string) $node->getAttribute($attr));
                if ($value !== '') {
                    $register($value);
                }
            }
            $content = trim((string) $node->getAttribute('content'));
            if ($content !== '') {
                $register($content);
            }
            foreach (['srcset', 'data-srcset'] as $attr) {
                $set = trim((string) $node->getAttribute($attr));
                if ($set === '') {
                    continue;
                }
                foreach (explode(',', $set) as $piece) {
                    $candidate = trim((string) preg_replace('/\s+\S+$/', '', trim($piece)));
                    if ($candidate !== '') {
                        $register($candidate);
                    }
                }
            }
        }

        // Fallback: scan raw HTML for any /cdn/shop/products/ URL we might have missed
        // (e.g. inline JSON/script tags). Keep tightly scoped to product images only.
        $patterns = [
            '/https?:\\\\?\/\\\\?\/[^"\'\s<>()]*\/cdn\/shop\/products\/[^"\'\s<>()]+/i',
            '/\/\/[^"\'\s<>()]*\/cdn\/shop\/products\/[^"\'\s<>()]+/i',
        ];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $html, $matches);
            foreach (($matches[0] ?? []) as $raw) {
                $register((string) $raw);
            }
        }

        return $urls;
    }

    /**
     * @param  array<int, array{url: string, title: string}>  $candidates
     * @return array{url: string, title: string}|null
     */
    public function pickBestCandidate(array $candidates, string $query): ?array
    {
        if ($candidates === []) {
            return null;
        }

        $queryTokens = $this->tokens($query);
        if ($queryTokens === []) {
            return $candidates[0] ?? null;
        }

        $best = null;
        $bestScore = -1;
        foreach ($candidates as $candidate) {
            $titleTokens = $this->tokens($candidate['title']);
            $score = 0;
            foreach ($queryTokens as $token) {
                if (in_array($token, $titleTokens, true)) {
                    $score += 2;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best ?? ($candidates[0] ?? null);
    }

    public function withWidth(string $url, int $width): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query['width'] = max(1, $width);

        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');
        if ($host === '' || $path === '') {
            return $url;
        }

        $rebuilt = $scheme.'://'.$host.$path;
        $q = http_build_query($query, arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);
        if ($q !== '') {
            $rebuilt .= '?'.$q;
        }
        if (isset($parts['fragment']) && (string) $parts['fragment'] !== '') {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt;
    }

    private function normalizeRawUrl(string $raw): ?string
    {
        $u = trim(str_replace(['\\/', '\\u0026', '&amp;'], ['/', '&', '&'], $raw));
        $u = trim($u, "\"' ");
        if ($u === '') {
            return null;
        }

        if (str_starts_with($u, '//')) {
            return 'https:'.$u;
        }
        if (str_starts_with($u, '/')) {
            return self::BASE_URL.$u;
        }
        if (str_starts_with($u, 'http://') || str_starts_with($u, 'https://')) {
            return $u;
        }

        return self::BASE_URL.'/'.ltrim($u, '/');
    }

    private function isProductImageUrl(string $url): bool
    {
        if (! str_contains($url, '/cdn/shop/products/') && ! str_contains($url, '/cdn/shop/files/')) {
            return false;
        }
        // Reject share/pin URLs that embed the image as a query param (e.g. Pinterest).
        if (str_contains($url, '&media=') || str_contains($url, '?media=')) {
            return false;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $filename = strtolower(pathinfo($path, PATHINFO_FILENAME));
        if (str_contains($filename, 'favicon') || str_contains($filename, 'logo')) {
            return false;
        }

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }

    private function stripShopifySizeSuffix(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path === '') {
            return $url;
        }

        $dir = rtrim((string) (str_contains($path, '/') ? substr($path, 0, strrpos($path, '/') + 1) : ''), '');
        $file = (string) (str_contains($path, '/') ? substr($path, strrpos($path, '/') + 1) : $path);
        $ext = (string) (str_contains($file, '.') ? substr($file, strrpos($file, '.')) : '');
        $base = (string) (str_contains($file, '.') ? substr($file, 0, strrpos($file, '.')) : $file);

        $base = preg_replace('/_(?:\d+x\d*|x\d+)(?:_crop_(?:center|top|bottom|left|right))?$/i', '', $base) ?? $base;

        $newPath = $dir.$base.$ext;
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return $url;
        }
        $rebuilt = $scheme.'://'.$host.$newPath;

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        // Drop query params that only describe size/crop — we'll re-apply width=1000 later.
        unset($query['width'], $query['height'], $query['crop']);
        if ($query !== []) {
            $rebuilt .= '?'.http_build_query($query, arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);
        }

        return $rebuilt;
    }

    private function dedupKey(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return strtolower($url);
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = strtolower((string) ($parts['path'] ?? ''));

        return $host.$path;
    }

    private function loadHtml(string $html): DOMDocument
    {
        $doc = new DOMDocument;
        $prev = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        return $doc;
    }

    private function toAbsoluteUrl(string $url): ?string
    {
        $u = trim($url);
        if ($u === '') {
            return null;
        }
        if (str_starts_with($u, '//')) {
            return 'https:'.$u;
        }
        if (str_starts_with($u, 'http://') || str_starts_with($u, 'https://')) {
            return $u;
        }
        if (str_starts_with($u, '/')) {
            return self::BASE_URL.$u;
        }

        return self::BASE_URL.'/'.ltrim($u, '/');
    }

    private function candidateTitleFromLink(DOMElement $a): string
    {
        $title = trim((string) ($a->getAttribute('aria-label') ?: $a->getAttribute('title')));
        if ($title !== '') {
            return $title;
        }

        $text = trim((string) ($a->textContent ?? ''));
        if ($text !== '') {
            return preg_replace('/\s+/u', ' ', $text) ?? $text;
        }

        foreach ($a->getElementsByTagName('img') as $img) {
            if (! $img instanceof DOMElement) {
                continue;
            }
            $alt = trim((string) $img->getAttribute('alt'));
            if ($alt !== '') {
                return $alt;
            }
        }

        return '';
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

        return array_values(array_unique(array_filter(explode(' ', $s), static fn (string $v): bool => mb_strlen($v) >= 2)));
    }
}
