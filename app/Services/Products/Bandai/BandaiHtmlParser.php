<?php

declare(strict_types=1);

namespace App\Services\Products\Bandai;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

final class BandaiHtmlParser
{
    private const string BASE_URL = 'https://global.bandai-hobby.net';
    private const string CMS_API_BASE = 'https://cmsapi-global-frontend.bandai-hobby.net';
    // Observed stable token used by Bandai's public CMS API calls.
    // If this ever changes, we can replace it with an env/config value.
    private const string DEFAULT_CMS_API_TOKEN = '146e08ad68c5c0ab70b0406691f8a88cf8c48915ba8e4e39d726ac3f3539e524';

    public function extractCmsApiTokenFromSearchHtml(string $html): ?string
    {
        // The search page loads results via cmsapi endpoints that include a long hex token.
        // We'll scrape it from the HTML (best-effort; if it changes, we can still fall back to
        // direct PDP URLs when known).
        if (preg_match('/token=([a-f0-9]{32,128})/i', $html, $m) === 1) {
            return (string) $m[1];
        }

        // Fallback: Bandai currently uses a stable token on the public site.
        return self::DEFAULT_CMS_API_TOKEN;
    }

    /**
     * @return string|null absolute PDP url
     */
    public function productListApiUrl(string $token, string $query, int $limit, int $start): ?string
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $limit = max(1, min($limit, 50));
        $start = max(0, $start);

        $dataJson = json_encode(['title' => $query], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($dataJson)) {
            return null;
        }

        // Note: The API expects the `data` query param to be JSON.
        $qs = http_build_query([
            'ip' => 'hobby',
            'site' => 'en-usa',
            'token' => $token,
            'limit' => $limit,
            'start' => $start,
            'data' => $dataJson,
        ]);

        return self::CMS_API_BASE.'/site/api/hobby/Product/list?'.$qs;
    }

    /**
     * @return array<int, array{url: string, title: string}>
     */
    public function extractPdpCandidatesFromSearchHtml(string $html): array
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

            $abs = $this->toAbsoluteUrl($href);
            if ($abs === null || ! str_contains($abs, '/en-us/item/')) {
                continue;
            }
            if (! preg_match('#/en-us/item/\d{2}_\d+/#', $abs)) {
                continue;
            }

            $title = trim($a->textContent ?? '');
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
            $score = 0;
            foreach ($qTokens as $t) {
                if (in_array($t, $tTokens, true)) {
                    $score += 2;
                } elseif (str_contains(implode(' ', $tTokens), $t)) {
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
     * @return array{
     *   title: string|null,
     *   description_html: string|null,
     *   image_urls: array<int, string>,
     *   grade: string|null,
     *   series: string|null,
     *   yen_price: int|null,
     *   launch_date: CarbonImmutable|null,
     *   age_text: string|null
     * }
     */
    public function parsePdp(string $html): array
    {
        $doc = $this->loadHtml($html);
        $xpath = new DOMXPath($doc);

        $title = $this->firstText($xpath, '//h1');
        // Grade badge appears as "MG [MASTER GRADE]" in breadcrumbs and in the badge list.
        $gradeText = $this->firstText($xpath, '//a[contains(@href,"/en-us/brand/")]');
        $grade = $this->extractGrade($gradeText !== '' ? $gradeText : $title);

        $series = $this->firstText($xpath, '//a[contains(@href,"/en-us/series/")]');

        $spec = $this->extractSpecRows($xpath);
        $yenPrice = $this->parseYenPrice($spec['Price'] ?? null);
        $launchDate = $this->parseLaunchDate($spec['Launch date'] ?? null);
        $age = $this->cleanText($spec['Age'] ?? null);

        $descHtml = $this->extractProductsInfoHtml($xpath);
        $imgUrls = $this->extractProductImageUrls($xpath, $title);

        return [
            'title' => $title !== '' ? $title : null,
            'description_html' => $descHtml,
            'image_urls' => $imgUrls,
            'grade' => $grade,
            'series' => $series !== '' ? $series : null,
            'yen_price' => $yenPrice,
            'launch_date' => $launchDate,
            'age_text' => $age !== '' ? $age : null,
        ];
    }

    private function extractProductsInfoHtml(DOMXPath $xpath): ?string
    {
        /** @var \DOMNodeList<DOMElement> $heads */
        // Case-insensitive match: PRODUCTS INFO
        $heads = $xpath->query(
            '//*[self::h2 or self::h3][translate(normalize-space(), "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ")="PRODUCTS INFO"]'
        ) ?? new \DOMNodeList();
        foreach ($heads as $h) {
            $container = $this->findNextElementSibling($h);
            if ($container === null) {
                continue;
            }

            $html = $this->innerHtml($container);
            $html = trim($html);
            if ($html !== '') {
                return $html;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function extractProductImageUrls(DOMXPath $xpath, ?string $title): array
    {
        /** @var array<int, string> $out */
        $out = [];
        $seen = [];

        $wantTitle = is_string($title) ? trim($title) : '';
        $wantTitleUpper = $wantTitle !== '' ? mb_strtoupper($wantTitle) : '';

        // Bandai PDP gallery is under:
        // - div.pg-products__sliderThumbnailWrap (thumbs)
        // - div.pg-products__sliderMainWrap (main)
        // Images we want are the ones in these sliders for this PDP.
        $galleryRootXpath = '//main//*[contains(@class,"pg-products__sliderThumbnailWrap") or contains(@class,"pg-products__sliderMainWrap")]';

        // Prefer <a href="FULL_IMAGE"><img alt="(product title)"></a> within the gallery.
        if ($wantTitleUpper !== '') {
            /** @var \DOMNodeList<DOMElement> $links */
            $links = $xpath->query($galleryRootXpath.'//a[@href][.//img[@alt]]') ?? new \DOMNodeList();
            foreach ($links as $a) {
                $href = trim((string) $a->getAttribute('href'));
                if ($href === '') {
                    continue;
                }

                $img = $xpath->query('.//img[@alt]', $a)?->item(0);
                $alt = $img instanceof DOMElement ? trim((string) $img->getAttribute('alt')) : '';
                if ($alt === '' || ! str_contains(mb_strtoupper($alt), $wantTitleUpper)) {
                    continue;
                }

                $abs = $this->toAbsoluteUrl($href) ?? $href;
                if (! preg_match('/\.(jpg|jpeg|png|webp)(?:\?|$)/i', $abs)) {
                    continue;
                }
                if (str_contains($abs, '/images/common/')) {
                    continue;
                }

                if (isset($seen[$abs])) {
                    continue;
                }
                $seen[$abs] = true;
                $out[] = $abs;
            }
        }

        // Also capture <img src> inside the gallery (some slides may not use hrefs consistently).
        if ($wantTitleUpper !== '') {
            /** @var \DOMNodeList<DOMElement> $imgs */
            $imgs = $xpath->query($galleryRootXpath.'//img[@alt][@src or @data-src]') ?? new \DOMNodeList();
            foreach ($imgs as $img) {
                $alt = trim((string) $img->getAttribute('alt'));
                if ($alt === '' || ! str_contains(mb_strtoupper($alt), $wantTitleUpper)) {
                    continue;
                }

                $src = trim((string) $img->getAttribute('src'));
                if ($src === '') {
                    $src = trim((string) $img->getAttribute('data-src'));
                }
                if ($src === '' || str_starts_with($src, 'data:')) {
                    continue;
                }

                $abs = $this->toAbsoluteUrl($src) ?? $src;
                if (! preg_match('/\.(jpg|jpeg|png|webp)(?:\?|$)/i', $abs)) {
                    continue;
                }
                if (str_contains($abs, '/images/common/')) {
                    continue;
                }
                // Avoid ingesting local/thumb placeholders; only accept full asset hosts.
                if (
                    (str_contains($abs, 'cloudfront.net/')
                        && (str_contains($abs, '/hobby/en-usa/product/') || str_contains($abs, '/hobby/jp/product/')))
                    || str_contains($abs, 'bandai-hobby.net/images/')
                ) {
                    // ok
                } else {
                    continue;
                }

                if (isset($seen[$abs])) {
                    continue;
                }
                $seen[$abs] = true;
                $out[] = $abs;
            }
        }

        // Fallback: if we couldn't match via alt/title, use a stricter host+path heuristic.
        if ($out === []) {
            /** @var \DOMNodeList<DOMElement> $links */
            $links = $xpath->query('//main//a[@href]') ?? new \DOMNodeList();
            foreach ($links as $a) {
                $href = trim((string) $a->getAttribute('href'));
                if ($href === '') {
                    continue;
                }
                $abs = $this->toAbsoluteUrl($href) ?? $href;

                if (! preg_match('/\.(jpg|jpeg|png|webp)(?:\?|$)/i', $abs)) {
                    continue;
                }
                if (str_contains($abs, '/images/common/')) {
                    continue;
                }
                if (
                    str_contains($abs, 'cloudfront.net/')
                    && (str_contains($abs, '/hobby/en-usa/product/') || str_contains($abs, '/hobby/jp/product/'))
                ) {
                    // ok
                } else {
                    continue;
                }

                if (isset($seen[$abs])) {
                    continue;
                }
                $seen[$abs] = true;
                $out[] = $abs;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function extractSpecRows(DOMXPath $xpath): array
    {
        $out = [];

        /** @var \DOMNodeList<DOMElement> $dts */
        $dts = $xpath->query('//dt') ?? new \DOMNodeList();
        foreach ($dts as $dt) {
            $k = $this->cleanText($dt->textContent);
            if ($k === '') {
                continue;
            }

            $dd = $this->findNextElementSibling($dt);
            if ($dd === null || mb_strtolower($dd->tagName) !== 'dd') {
                continue;
            }

            $v = $this->cleanText($dd->textContent);
            if ($v === '') {
                continue;
            }

            $out[$k] = $v;
        }

        return $out;
    }

    private function parseYenPrice(?string $raw): ?int
    {
        $raw = $this->cleanText($raw);
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        return (int) $digits;
    }

    private function parseLaunchDate(?string $raw): ?CarbonImmutable
    {
        $raw = $this->cleanText($raw);
        if ($raw === '') {
            return null;
        }

        // Example: "Nov 22, 2025 (Sat)" → "Nov 22, 2025"
        $raw = preg_replace('/\s*\([^)]+\)\s*$/', '', $raw) ?? $raw;
        $raw = trim($raw);

        // Try strict "M d, Y" format.
        $dt = \DateTimeImmutable::createFromFormat('M d, Y', $raw);
        if ($dt instanceof \DateTimeImmutable) {
            return CarbonImmutable::instance($dt)->startOfDay();
        }

        return null;
    }

    private function extractGrade(?string $text): ?string
    {
        $text = $this->cleanText($text);
        if ($text === '') {
            return null;
        }

        // Example: "MG [MASTER GRADE]" -> MG
        if (preg_match('/\b(MG|HG|RG|PG|MEGA)\b/i', $text, $m) === 1) {
            return mb_strtoupper((string) $m[1]);
        }

        return null;
    }

    private function cleanText(?string $text): string
    {
        $text = is_string($text) ? $text : '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        return $text;
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $text): array
    {
        $text = mb_strtoupper($this->cleanText($text));
        $text = preg_replace('/[^A-Z0-9]+/u', ' ', $text) ?? $text;
        $parts = array_values(array_filter(explode(' ', $text), static fn (string $p): bool => $p !== ''));
        $stop = ['THE', 'A', 'AN', 'OF', 'AND'];

        return array_values(array_filter($parts, static fn (string $p): bool => ! in_array($p, $stop, true)));
    }

    private function toAbsoluteUrl(string $hrefOrSrc): ?string
    {
        $u = trim($hrefOrSrc);
        if ($u === '') {
            return null;
        }
        if (str_starts_with($u, 'http://') || str_starts_with($u, 'https://')) {
            return $u;
        }
        if (str_starts_with($u, '//')) {
            return 'https:'.$u;
        }
        if (str_starts_with($u, '/')) {
            return self::BASE_URL.$u;
        }

        return null;
    }

    private function loadHtml(string $html): DOMDocument
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();

        return $doc;
    }

    private function firstText(DOMXPath $xpath, string $query): string
    {
        $node = $xpath->query($query)?->item(0);
        if (! $node instanceof DOMNode) {
            return '';
        }

        return $this->cleanText($node->textContent);
    }

    private function findNextElementSibling(DOMNode $node): ?DOMElement
    {
        $cur = $node->nextSibling;
        while ($cur !== null) {
            if ($cur instanceof DOMElement) {
                return $cur;
            }
            $cur = $cur->nextSibling;
        }
        return null;
    }

    private function innerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }
        return $html;
    }
}

