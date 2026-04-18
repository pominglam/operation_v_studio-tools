<?php

declare(strict_types=1);

namespace App\Services\Products\Newtype;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class NewtypeHtmlParser
{
    public const string BASE_URL = 'https://newtype.us';

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

            $abs = $this->toAbsoluteUrl($href);
            if ($abs === null) {
                continue;
            }
            if (! preg_match('#https?://newtype\\.us/p/[^/]+/h/[^\\s\\?]+#i', $abs)) {
                continue;
            }

            $title = $this->candidateTitleFromLink($a);
            if ($title === '') {
                continue;
            }

            if (isset($seen[$abs])) {
                continue;
            }
            $seen[$abs] = true;
            $out[] = ['url' => $abs, 'title' => $title];
        }

        return $out;
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

        $qTokens = $this->tokens($query);
        if ($qTokens === []) {
            return $candidates[0] ?? null;
        }

        $best = null;
        $bestScore = -1;

        foreach ($candidates as $c) {
            $tTokens = $this->tokens($c['title']);
            $titleJoined = implode(' ', $tTokens);
            $score = 0;
            foreach ($qTokens as $t) {
                if (in_array($t, $tTokens, true)) {
                    $score += 2;
                } elseif ($t !== '' && $titleJoined !== '' && str_contains($titleJoined, $t)) {
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
     * Extract image URLs from a Newtype PDP HTML.
     * Scope strictly to the gallery container:
     * `<div class="pt-square relative w-full overflow-hidden"> ... </div>`
     *
     * @return array<int, string>
     */
    public function extractImageUrlsFromPdpHtml(string $html): array
    {
        $doc = $this->loadHtml($html);
        $xpath = new DOMXPath($doc);

        /** @var array<int, string> $out */
        $out = [];
        $seen = [];

        // Newtype "box art" appears outside the gallery container on some PDPs.
        // Always include it and promote it to the front (user expectation).
        $boxArtUrls = $this->extractBoxArtUrls($xpath);

        /** @var \DOMNodeList<DOMElement> $containers */
        $containers = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' pt-square ') and contains(concat(' ', normalize-space(@class), ' '), ' overflow-hidden ')]") ?? new \DOMNodeList;
        if ($containers->length === 0) {
            // Still return box art if present.
            foreach ($boxArtUrls as $u) {
                $this->promoteUrlToFront($out, $seen, $u);
            }

            return $out;
        }

        /** @var DOMElement $container */
        $container = $containers->item(0);
        if (! $container instanceof DOMElement) {
            return [];
        }

        // 1) Prefer explicit <img> tags (box art often uses <img alt="box art">)
        /** @var \DOMNodeList<DOMElement> $imgs */
        $imgs = $xpath->query('.//img', $container) ?? new \DOMNodeList;
        foreach ($imgs as $img) {
            $srcset = trim((string) $img->getAttribute('srcset'));
            if ($srcset !== '') {
                $best = $this->bestUrlFromSrcset($srcset);
                if ($best !== null) {
                    $this->pushUrl($out, $seen, $this->toAbsoluteUrl($best) ?? $best);

                    continue;
                }
            }
            foreach (['data-src', 'src'] as $attr) {
                $u = trim((string) $img->getAttribute($attr));
                if ($u !== '') {
                    $this->pushUrl($out, $seen, $this->toAbsoluteUrl($u) ?? $u);
                    break;
                }
            }
        }

        // 2) Background-image URLs in inline styles (the gallery uses absolute positioned divs).
        /** @var \DOMNodeList<DOMElement> $styled */
        $styled = $xpath->query('.//*[@style]', $container) ?? new \DOMNodeList;
        foreach ($styled as $el) {
            $style = (string) $el->getAttribute('style');
            if (! str_contains($style, 'background-image')) {
                continue;
            }
            $urls = $this->backgroundImageUrlsFromStyle($style);
            foreach ($urls as $u) {
                $this->pushUrl($out, $seen, $this->toAbsoluteUrl($u) ?? $u);
            }
        }

        foreach ($boxArtUrls as $u) {
            $this->promoteUrlToFront($out, $seen, $u);
        }

        return $out;
    }

    /**
     * @return array{title: ?string, description_html: ?string, scale: ?string, line: ?string, brand: ?string, series: ?string}
     */
    public function extractDescriptionAndFactsFromPdpHtml(string $html): array
    {
        $title = $this->metaContent($html, 'property', 'og:title')
            ?? $this->metaContent($html, 'name', 'title')
            ?? $this->titleTag($html);

        $descHtml = $this->jsonLdDescription($html);
        if ($descHtml === null) {
            $metaDesc = $this->metaContent($html, 'name', 'description');
            $descHtml = is_string($metaDesc) && trim($metaDesc) !== '' ? '<p>'.e(trim($metaDesc)).'</p>' : null;
        }

        $facts = $this->factsFromTable($html);

        return [
            'title' => $title,
            'description_html' => $descHtml,
            'scale' => $facts['scale'] ?? null,
            'line' => $facts['line'] ?? null,
            'brand' => $facts['brand'] ?? null,
            'series' => $facts['series'] ?? null,
        ];
    }

    private function factsFromTable(string $html): array
    {
        $doc = $this->loadHtml($html);
        $xpath = new DOMXPath($doc);

        /** @var array<string, string> $out */
        $out = [];

        /** @var \DOMNodeList<DOMElement> $rows */
        $rows = $xpath->query('//table//tr') ?? new \DOMNodeList;
        foreach ($rows as $tr) {
            /** @var \DOMNodeList<DOMElement> $tds */
            $tds = $xpath->query('./td', $tr) ?? new \DOMNodeList;
            if ($tds->length < 2) {
                continue;
            }

            $k = trim(preg_replace('/\s+/u', ' ', (string) ($tds->item(0)?->textContent ?? '')) ?? '');
            $v = trim(preg_replace('/\s+/u', ' ', (string) ($tds->item(1)?->textContent ?? '')) ?? '');
            if ($k === '' || $v === '') {
                continue;
            }

            $key = strtolower($k);
            if (str_contains($key, 'scale')) {
                $out['scale'] = $v;
            } elseif ($key === 'line') {
                $out['line'] = $v;
            } elseif (str_contains($key, 'brand')) {
                $out['brand'] = $v;
            } elseif (str_contains($key, 'series')) {
                $out['series'] = $v;
            }
        }

        return $out;
    }

    private function jsonLdDescription(string $html): ?string
    {
        if (! preg_match_all('#<script[^>]+type=[\'"]application/ld\\+json[\'"][^>]*>(.*?)</script>#is', $html, $m)) {
            return null;
        }
        foreach (($m[1] ?? []) as $raw) {
            if (! is_string($raw)) {
                continue;
            }
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $payload = json_decode($raw, true);
            if (! is_array($payload)) {
                continue;
            }
            $desc = $payload['description'] ?? null;
            if (is_string($desc) && trim($desc) !== '') {
                return trim($desc);
            }
        }

        return null;
    }

    private function metaContent(string $html, string $attr, string $value): ?string
    {
        if (! preg_match('#<meta[^>]+'.preg_quote($attr, '#').'\\s*=\\s*\"'.preg_quote($value, '#').'\"[^>]+content\\s*=\\s*\"([^\"]+)\"#i', $html, $m)) {
            return null;
        }
        $c = html_entity_decode((string) ($m[1] ?? ''), ENT_QUOTES | ENT_HTML5);
        $c = trim($c);

        return $c !== '' ? $c : null;
    }

    private function titleTag(string $html): ?string
    {
        if (! preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            return null;
        }
        $t = trim(html_entity_decode((string) ($m[1] ?? ''), ENT_QUOTES | ENT_HTML5));
        $t = preg_replace('/\\s+/u', ' ', $t) ?? $t;

        return $t !== '' ? $t : null;
    }

    private function backgroundImageUrlsFromStyle(string $style): array
    {
        $out = [];
        if (preg_match_all('#background-image\\s*:\\s*url\\(([^)]+)\\)#i', $style, $m)) {
            foreach (($m[1] ?? []) as $raw) {
                if (! is_string($raw)) {
                    continue;
                }
                $u = trim($raw, " \t\n\r\0\x0B\"'");
                if ($u !== '') {
                    $out[] = $u;
                }
            }
        }

        return $out;
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
        $text = preg_replace('/\\s+/u', ' ', $text) ?? $text;

        return $text !== '' ? $text : '';
    }

    private function bestUrlFromSrcset(string $srcset): ?string
    {
        $raw = trim($srcset);
        if ($raw === '') {
            return null;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($parts === []) {
            return null;
        }
        $last = $parts[count($parts) - 1] ?? null;
        if (! is_string($last)) {
            return null;
        }
        $url = trim(preg_split('/\\s+/', $last)[0] ?? '');

        return $url !== '' ? $url : null;
    }

    /**
     * @return array<int, string>
     */
    private function extractBoxArtUrls(DOMXPath $xpath): array
    {
        /** @var array<int, string> $out */
        $out = [];

        /** @var \DOMNodeList<DOMElement> $imgs */
        $imgs = $xpath->query(
            "//img[@alt and translate(normalize-space(@alt), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') = 'box art']"
        ) ?? new \DOMNodeList;

        foreach ($imgs as $img) {
            $srcset = trim((string) $img->getAttribute('srcset'));
            if ($srcset !== '') {
                $best = $this->bestUrlFromSrcset($srcset);
                if ($best !== null) {
                    $out[] = $this->toAbsoluteUrl($best) ?? $best;

                    continue;
                }
            }
            foreach (['data-src', 'src'] as $attr) {
                $u = trim((string) $img->getAttribute($attr));
                if ($u !== '') {
                    $out[] = $this->toAbsoluteUrl($u) ?? $u;
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($out, static fn (string $u): bool => trim($u) !== '')));
    }

    /**
     * Promote URL to front while preserving de-duplication.
     *
     * @param  array<int, string>  $out
     * @param  array<string, bool>  $seen
     */
    private function promoteUrlToFront(array &$out, array &$seen, string $url): void
    {
        $u = trim($url);
        if ($u === '') {
            return;
        }

        if (isset($seen[$u])) {
            $idx = array_search($u, $out, true);
            if ($idx === false) {
                return;
            }
            unset($out[$idx]);
            array_unshift($out, $u);
            $out = array_values($out);

            return;
        }

        $seen[$u] = true;
        array_unshift($out, $u);
    }

    /**
     * @param  array<int, string>  $out
     * @param  array<string, bool>  $seen
     */
    private function pushUrl(array &$out, array &$seen, string $url): void
    {
        $u = trim($url);
        if ($u === '') {
            return;
        }
        if (isset($seen[$u])) {
            return;
        }
        $seen[$u] = true;
        $out[] = $u;
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $s): array
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? $s;
        $s = trim(preg_replace('/\\s+/u', ' ', $s) ?? $s);
        if ($s === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $s), static fn (string $t): bool => $t !== ''));
    }
}
