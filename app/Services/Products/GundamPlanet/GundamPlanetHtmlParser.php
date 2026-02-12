<?php

declare(strict_types=1);

namespace App\Services\Products\GundamPlanet;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class GundamPlanetHtmlParser
{
    public const string BASE_URL = 'https://www.gundamplanet.com';

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
        $links = $xpath->query('//a[@href]') ?? new \DOMNodeList();
        foreach ($links as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            // Only consider PDP links.
            $abs = $this->toAbsoluteUrl($href);
            if ($abs === null || ! str_contains($abs, '/products/')) {
                continue;
            }

            // Avoid obvious non-product pages.
            if (str_contains($abs, '/blogs/') || str_contains($abs, '/collections/') || str_contains($abs, '/pages/')) {
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
     * Extract image URLs from a GundamPlanet PDP HTML, *strictly* within the `<product-gallery>` element.
     * Per requirements: do not use any fallback scanning outside this element.
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

        /** @var \DOMNodeList<DOMElement> $galleries */
        $galleries = $xpath->query('//product-gallery') ?? new \DOMNodeList();
        foreach ($galleries as $gallery) {
            // Images
            /** @var \DOMNodeList<DOMElement> $imgs */
            $imgs = $xpath->query('.//img', $gallery) ?? new \DOMNodeList();
            foreach ($imgs as $img) {
                // If this <img> is a <picture> fallback and the <picture> already has <source srcset>,
                // do not also collect the fallback src/data-src to prevent duplicate variants.
                $parent = $img->parentNode;
                if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'picture') {
                    $hasSource = $xpath->query('.//source[@srcset]', $parent)?->length ?? 0;
                    if ($hasSource > 0) {
                        // Prefer the <picture><source srcset="..."> best URL at this position.
                        /** @var \DOMNodeList<DOMElement> $picSources */
                        $picSources = $xpath->query('.//source[@srcset]', $parent) ?? new \DOMNodeList();
                        foreach ($picSources as $s) {
                            $srcset = trim((string) $s->getAttribute('srcset'));
                            if ($srcset === '') continue;
                            $best = $this->bestUrlFromSrcset($srcset);
                            if ($best !== null) {
                                $this->pushUrl($out, $seen, $this->toAbsoluteUrl($best) ?? $best);
                                break;
                            }
                        }
                        continue;
                    }
                }

                $srcset = trim((string) $img->getAttribute('srcset'));
                if ($srcset !== '') {
                    $best = $this->bestUrlFromSrcset($srcset);
                    if ($best !== null) {
                        $this->pushUrl($out, $seen, $this->toAbsoluteUrl($best) ?? $best);
                        continue;
                    }
                }

                foreach (['data-src', 'data-zoom-image', 'data-large-image', 'src'] as $attr) {
                    $u = trim((string) $img->getAttribute($attr));
                    if ($u !== '') {
                        $this->pushUrl($out, $seen, $this->toAbsoluteUrl($u) ?? $u);
                        break;
                    }
                }
            }

            // Some themes link full-size assets via <a href="...">
            /** @var \DOMNodeList<DOMElement> $anchors */
            $anchors = $xpath->query('.//a[@href]', $gallery) ?? new \DOMNodeList();
            foreach ($anchors as $a) {
                $href = trim((string) $a->getAttribute('href'));
                if ($href === '') continue;
                $abs = $this->toAbsoluteUrl($href) ?? $href;
                if (! preg_match('/\\.(png|jpe?g|webp|gif)(?:\\?|#|$)/i', $abs)) {
                    continue;
                }
                $this->pushUrl($out, $seen, $abs);
            }
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

    private function loadHtml(string $html): DOMDocument
    {
        $doc = new DOMDocument();
        // Prevent warnings for broken HTML while keeping parsing best-effort.
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
        if ($u === '') return null;
        if (str_starts_with($u, '//')) {
            return 'https:'.$u;
        }
        if (str_starts_with($u, 'http://') || str_starts_with($u, 'https://')) {
            return $u;
        }
        if (str_starts_with($u, '/')) {
            return self::BASE_URL.$u;
        }
        // Best-effort: treat as relative to the host root.
        return self::BASE_URL.'/'.ltrim($u, '/');
    }

    private function candidateTitleFromLink(DOMElement $a): string
    {
        $title = trim((string) ($a->getAttribute('aria-label') ?: $a->getAttribute('title')));
        if ($title !== '') return $title;

        $text = trim((string) ($a->textContent ?? ''));
        if ($text !== '') return preg_replace('/\\s+/u', ' ', $text) ?? $text;

        // Fallback to nested image alt (still within link).
        foreach ($a->getElementsByTagName('img') as $img) {
            if (! $img instanceof DOMElement) continue;
            $alt = trim((string) $img->getAttribute('alt'));
            if ($alt !== '') return $alt;
        }

        return '';
    }

    private function bestUrlFromSrcset(string $srcset): ?string
    {
        $raw = trim($srcset);
        if ($raw === '') return null;

        $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($parts === []) return null;

        // Choose the last candidate (often highest resolution).
        $last = $parts[count($parts) - 1] ?? null;
        if (! is_string($last)) return null;

        // srcset entries are like: "url 400w" or "url 2x"
        $url = trim(preg_split('/\\s+/', $last)[0] ?? '');
        return $url !== '' ? $url : null;
    }

    /**
     * @param  array<int, string>  $out
     * @param  array<string, bool>  $seen
     */
    private function pushUrl(array &$out, array &$seen, string $url): void
    {
        $u = trim($url);
        if ($u === '') return;
        if (isset($seen[$u])) return;
        $seen[$u] = true;
        $out[] = $u;
    }

    /**
     * Tokenize for coarse matching (similar to Bandai parser).
     *
     * @return array<int, string>
     */
    private function tokens(string $text): array
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') return [];

        $t = preg_replace('/\\b\\d+\\s*\\/\\s*\\d+\\b/u', ' ', $t) ?? $t; // remove 1/144 etc
        $t = preg_replace('/[^\\p{L}\\p{N}\\s]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/\\s+/u', ' ', $t) ?? $t;
        $t = trim($t);
        if ($t === '') return [];

        $tokens = explode(' ', $t);
        $tokens = array_values(array_filter($tokens, static fn (string $x): bool => $x !== '' && mb_strlen($x) >= 2));

        return array_values(array_unique($tokens));
    }
}

