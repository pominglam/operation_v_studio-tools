<?php

declare(strict_types=1);

namespace App\Services\Products\Hlj;

use Illuminate\Support\Str;

final class HljHtmlParser
{
    public function extractPdpUrlFromSearchHtml(string $html): ?string
    {
        $urls = $this->extractPdpUrlsFromSearchHtml($html);

        return $urls[0] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function extractPdpUrlsFromSearchHtml(string $html): array
    {
        // HLJ PDP URLs commonly end with a vendor code like "-ban901788" or "-banh603920-up".
        if (preg_match_all('/href=["\']((?:https:\\/\\/www\\.hlj\\.com)?\\/[^"\']+-[a-z]{2,6}\\d{5,}(?:-[a-z]{2})?)(?:\\?[^"\']*)?["\']/i', $html, $m) < 1) {
            return [];
        }

        /** @var array<int, string> $urls */
        $urls = array_map(static function (string $u): string {
            $u = preg_replace('/\\?.*$/', '', $u) ?? $u;
            if (Str::startsWith($u, '/')) {
                return 'https://www.hlj.com'.$u;
            }

            return $u;
        }, $m[1] ?? []);

        return array_values(array_unique(array_filter($urls)));
    }

    public function extractJanCodeFromPdpHtml(string $html): ?string
    {
        // Common PDP detail label: "JAN Code: 4573102603920"
        if (preg_match('/\\bJAN\\s*Code\\s*:\\s*([0-9]{8,14})\\b/i', $html, $m) === 1) {
            $v = trim((string) ($m[1] ?? ''));

            return $v !== '' ? $v : null;
        }

        // Sometimes rendered as "JAN: 4573102603920"
        if (preg_match('/\\bJAN\\s*:\\s*([0-9]{8,14})\\b/i', $html, $m) === 1) {
            $v = trim((string) ($m[1] ?? ''));

            return $v !== '' ? $v : null;
        }

        // HLJ often includes GTIN in JSON-LD (gtin13/gtin14/etc).
        if (preg_match_all('/<script[^>]+type=["\']application\\/ld\\+json["\'][^>]*>(.*?)<\\/script>/is', $html, $m) >= 1) {
            foreach (($m[1] ?? []) as $json) {
                $decoded = json_decode((string) $json, true);
                if (! is_array($decoded)) {
                    continue;
                }

                $product = $this->findProductNode($decoded);
                if (! is_array($product)) {
                    continue;
                }

                foreach (['gtin13', 'gtin14', 'gtin12', 'gtin8', 'gtin'] as $k) {
                    $v = $product[$k] ?? null;
                    if (is_string($v) && preg_match('/^\\d{8,14}$/', trim($v)) === 1) {
                        return trim($v);
                    }
                }
            }
        }

        return null;
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
     * @return array<int, string>
     */
    public function extractImageUrls(string $html, ?string $expectedProductCode = null): array
    {
        $urls = [];

        // 0) Product image gallery (most reliable; avoids banners/icons).
        $gallery = $this->extractImagesFromGallery($html, $expectedProductCode);
        foreach ($gallery as $u) {
            $urls[] = $u;
        }

        // 1) JSON-LD Product.image is often reliable.
        foreach ($this->extractImagesFromLdJson($html) as $u) {
            $urls[] = $u;
        }

        // 2) OG/Twitter image meta tags.
        foreach ([
            '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i',
        ] as $re) {
            if (preg_match($re, $html, $m) === 1) {
                $u = trim(html_entity_decode((string) ($m[1] ?? ''), ENT_QUOTES | ENT_HTML5));
                if ($u !== '') {
                    $urls[] = $u;
                }
            }
        }

        // 2.5) HLJ often embeds product image URLs in inline scripts / data attributes (even when fotorama DOM is JS-driven).
        // This is restricted to known product-image path prefixes to avoid pulling in UI artwork.
        foreach ($this->extractProductImagePathsFromHtml($html) as $u) {
            $urls[] = $u;
        }

        // 3) Limited scan for product images in attributes (data-zoom-image etc).
        // This helps when a page omits JSON-LD or gallery extraction fails, while avoiding UI artwork.
        if ($gallery === []) {
            if (preg_match_all('/<(?:img|source)\\b[^>]+(?:src|data-src|data-zoom-image|data-large-image)=["\']([^"\']+)["\'][^>]*>/i', $html, $m) > 0) {
                foreach (($m[1] ?? []) as $u) {
                    $u = trim(html_entity_decode((string) $u, ENT_QUOTES | ENT_HTML5));
                    if ($u === '') {
                        continue;
                    }
                    $normalized = $this->normalizeUrl($u);
                    if ($normalized === null) {
                        continue;
                    }
                    if (! $this->looksLikeImageUrl($normalized)) {
                        continue;
                    }
                    if (! $this->looksLikeProductImagePath($normalized)) {
                        continue;
                    }
                    $urls[] = $normalized;
                }
            }
        }

        $urls = array_values(array_unique(array_filter(array_map([$this, 'normalizeUrl'], $urls))));
        $urls = array_values(array_filter($urls, [$this, 'looksLikeImageUrl']));

        // If we found HLJ product image paths, restrict to those to avoid unrelated images (banners, UI artwork).
        $urls = $this->preferProductImagePaths($urls);
        $urls = $this->preferExpectedProductCodeWhenPossible($urls, $expectedProductCode);
        $urls = $this->excludeKnownNonProductImages($urls);

        return array_slice($urls, 0, 30);
    }

    /**
     * Extract the HLJ vendor/product code suffix from a PDP URL slug (e.g. "bans60760", "banh603920-up", "bann22236", "hbj60934").
     * This is used to aggressively filter out non-product images that can still live under /productimages/.
     */
    public function productCodeFromPdpUrl(string $pdpUrl): ?string
    {
        $path = parse_url($pdpUrl, PHP_URL_PATH);
        $path = is_string($path) ? trim($path) : '';
        if ($path === '') {
            return null;
        }

        $slug = trim(basename($path), '/');
        if ($slug === '') {
            return null;
        }

        if (preg_match('/(?:^|-)((?:ban[a-z]{0,2}|hbj)\\d+(?:-up)?)(?:$)/i', $slug, $m) === 1) {
            $v = strtolower((string) ($m[1] ?? ''));

            return $v !== '' ? $v : null;
        }

        return null;
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
     * @return array<int, string>
     */
    private function extractImagesFromLdJson(string $html): array
    {
        if (preg_match_all('/<script[^>]+type=["\']application\\/ld\\+json["\'][^>]*>(.*?)<\\/script>/is', $html, $m) < 1) {
            return [];
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

            $img = $product['image'] ?? null;
            $out = [];
            if (is_string($img)) {
                $out[] = trim($img);
            } elseif (is_array($img)) {
                foreach ($img as $v) {
                    if (is_string($v) && trim($v) !== '') {
                        $out[] = trim($v);
                    }
                }
            }

            $out = array_values(array_unique(array_filter(array_map([$this, 'normalizeUrl'], $out))));
            $out = array_values(array_filter($out, [$this, 'looksLikeImageUrl']));

            if ($out !== []) {
                return $out;
            }
        }

        return [];
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
                    if ($found !== null) {
                        return $found;
                    }
                } else {
                    foreach ($v as $vv) {
                        if (is_array($vv) && $this->isAssoc($vv)) {
                            $found = $this->findProductNode($vv);
                            if ($found !== null) {
                                return $found;
                            }
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

    /**
     * @return array<int, string>
     */
    private function extractImagesFromGallery(string $html, ?string $expectedProductCode = null): array
    {
        // HLJ pages commonly render a fotorama within a container like:
        // <div class="product-images-fotorama-container"> ... <img class="fotorama__img" ...>
        $out = [];

        $prev = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument;
            $dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);

            // HLJ sometimes includes multiple carousels (shipping/payment logos, etc). We select the best candidate:
            // the one that yields the most product-image URLs after filtering.
            $candidates = [];
            $containers = $xpath->query(
                "//div[contains(concat(' ', normalize-space(@class), ' '), ' product-images-fotorama-container ')]
                 | //div[contains(concat(' ', normalize-space(@class), ' '), ' fotorama ') and @data-nav='thumbs']"
            );

            if ($containers !== false) {
                foreach ($containers as $container) {
                    if (! $container instanceof \DOMElement) {
                        continue;
                    }
                    $urls = $this->extractImageUrlsFromContainer($xpath, $container);
                    if ($urls === []) {
                        continue;
                    }

                    $urls = array_values(array_unique(array_filter(array_map([$this, 'normalizeUrl'], $urls))));
                    $urls = array_values(array_filter($urls, [$this, 'looksLikeImageUrl']));
                    $urls = $this->preferProductImagePaths($urls);
                    $urls = $this->preferExpectedProductCodeWhenPossible($urls, $expectedProductCode);
                    $urls = $this->excludeKnownNonProductImages($urls);

                    if ($urls !== []) {
                        $candidates[] = $urls;
                    }
                }
            }

            // Choose the container with the most usable product images.
            usort($candidates, static fn (array $a, array $b): int => count($b) <=> count($a));
            $out = $candidates[0] ?? [];
        } catch (\Throwable) {
            // Best-effort only; fall back to other extractors.
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        $out = array_values(array_unique(array_filter(array_map([$this, 'normalizeUrl'], $out))));
        $out = array_values(array_filter($out, [$this, 'looksLikeImageUrl']));

        return $out;
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    private function preferExpectedProductCodeWhenPossible(array $urls, ?string $expectedProductCode): array
    {
        $code = is_string($expectedProductCode) ? strtolower(trim($expectedProductCode)) : '';
        if ($code === '') {
            return $urls;
        }

        $variants = array_values(array_unique(array_filter([
            $code,
            preg_replace('/-up$/', '', $code) ?: null,
        ])));

        $matches = array_values(array_filter($urls, static function (string $u) use ($variants): bool {
            $path = parse_url($u, PHP_URL_PATH);
            $path = is_string($path) ? strtolower($path) : '';
            if ($path === '') {
                return false;
            }

            foreach ($variants as $v) {
                $v = strtolower((string) $v);
                if ($v === '') {
                    continue;
                }
                // Typical HLJ product image: /productimages/<vendor>/<code>_0.jpg
                // We require the code to appear as a path token prefix before "_" or ".".
                if (preg_match('/(?:^|\\/)'.preg_quote($v, '/').'(?:(?:_|\\.)|$)/i', $path) === 1) {
                    return true;
                }
            }

            return false;
        }));

        // Only restrict when we can actually find at least one URL that matches the expected code.
        // (Some pages only expose /media/catalog/product/ URLs without the code in the filename.)
        return $matches !== [] ? $matches : $urls;
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    private function excludeKnownNonProductImages(array $urls): array
    {
        $blockedTokens = [
            'fedex',
            'dhl',
            'ups',
            'usps',
            'ems',
            'paypal',
            'mastercard',
            'visa',
            'amex',
            'jcb',
            'shipping',
            'delivery',
            'courier',
            'logo',
        ];

        return array_values(array_filter($urls, static function (string $u) use ($blockedTokens): bool {
            $path = parse_url($u, PHP_URL_PATH);
            $path = is_string($path) ? strtolower($path) : '';
            if ($path === '') {
                return true;
            }

            // Common non-product asset buckets on HLJ.
            if (
                str_contains($path, '/wysiwyg/')
                || str_contains($path, '/skin/')
                || str_contains($path, '/icons/')
                || str_contains($path, '/icon/')
                || str_contains($path, '/shipping/')
                || str_contains($path, '/payment/')
                || str_contains($path, '/payments/')
            ) {
                return false;
            }

            $basename = strtolower((string) basename($path));
            foreach ($blockedTokens as $tok) {
                if (str_contains($basename, $tok)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @return array<int, string>
     */
    private function extractProductImagePathsFromHtml(string $html): array
    {
        $out = [];

        // Absolute URLs.
        if (preg_match_all(
            '/https?:\\/\\/www\\.hlj\\.com\\/(?:productimages|media\\/catalog\\/product)\\/[A-Za-z0-9_\\-\\/\\.]+\\.(?:jpe?g|png|webp|gif)(?:\\?[^"\\\'\\s\\)]*)?/i',
            $html,
            $m,
        ) > 0) {
            foreach (($m[0] ?? []) as $u) {
                $out[] = (string) $u;
            }
        }

        // Root-relative URLs.
        if (preg_match_all(
            '/\\/(?:productimages|media\\/catalog\\/product)\\/[A-Za-z0-9_\\-\\/\\.]+\\.(?:jpe?g|png|webp|gif)(?:\\?[^"\\\'\\s\\)]*)?/i',
            $html,
            $m2,
        ) > 0) {
            foreach (($m2[0] ?? []) as $u) {
                $out[] = 'https://www.hlj.com'.(string) $u;
            }
        }

        $out = array_values(array_unique(array_filter(array_map([$this, 'normalizeUrl'], $out))));
        $out = array_values(array_filter($out, [$this, 'looksLikeImageUrl']));

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function extractImageUrlsFromContainer(\DOMXPath $xpath, \DOMElement $container): array
    {
        $out = [];
        $nodes = $xpath->query('.//img', $container);
        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $img) {
            if (! $img instanceof \DOMElement) {
                continue;
            }
            foreach (['data-zoom-image', 'data-large-image', 'data-src', 'src'] as $attr) {
                $u = trim((string) $img->getAttribute($attr));
                if ($u !== '') {
                    $out[] = html_entity_decode($u, ENT_QUOTES | ENT_HTML5);
                }
            }
        }

        return $out;
    }

    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (Str::startsWith($url, '//')) {
            $url = 'https:'.$url;
        } elseif (Str::startsWith($url, '/')) {
            $url = 'https://www.hlj.com'.$url;
        }

        // HLJ sometimes appends versioning query params (e.g. ?v=123) to product images.
        // Those URLs can 404 server-side; strip query/fragment for known product image buckets.
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? strtolower($path) : '';
        if ($path !== '' && (str_contains($path, '/productimages/') || str_contains($path, '/media/catalog/product/'))) {
            $url = strtok($url, '?#') ?: $url;
        }

        return $url;
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    private function preferProductImagePaths(array $urls): array
    {
        $productImages = array_values(array_filter($urls, static function (string $u): bool {
            $path = parse_url($u, PHP_URL_PATH);
            $path = is_string($path) ? strtolower($path) : '';

            return str_contains($path, '/productimages/') || str_contains($path, '/media/catalog/product/');
        }));

        return $productImages !== [] ? $productImages : $urls;
    }

    private function looksLikeProductImagePath(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? strtolower($path) : '';
        if ($path === '') {
            return false;
        }

        return str_contains($path, '/productimages/') || str_contains($path, '/media/catalog/product/');
    }

    private function looksLikeImageUrl(string $url): bool
    {
        $u = trim($url);
        if ($u === '') {
            return false;
        }

        $path = parse_url($u, PHP_URL_PATH);
        $path = is_string($path) ? strtolower($path) : '';
        if ($path === '') {
            return false;
        }

        // Avoid icons/sprites; require a real image extension.
        return (bool) preg_match('/\\.(jpe?g|png|webp|gif)$/i', $path);
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
        if ($text === null) {
            return null;
        }
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // Convert blank-line separated blocks into paragraphs.
        $blocks = preg_split('/\\R\\s*\\R+/u', $text) ?: [];
        $blocks = array_values(array_filter(array_map('trim', $blocks)));
        if ($blocks === []) {
            return null;
        }

        $parts = array_map(static fn (string $b): string => '<p>'.e($b).'</p>', $blocks);

        return implode('', $parts);
    }

    private function sanitizeHtml(string $html): ?string
    {
        $html = trim($html);
        if ($html === '') {
            return null;
        }

        $html = $this->decodeEntitiesDeep($html) ?? '';
        $html = trim($html);
        if ($html === '') {
            return null;
        }

        // Keep a conservative tag allowlist; strip attributes by normalizing opening tags.
        $html = strip_tags($html, '<p><br><b><strong><em><i><ul><ol><li>');
        $html = preg_replace('/<(p|ul|ol|li)\\b[^>]*>/i', '<$1>', $html) ?? $html;
        $html = preg_replace('/<br\\b[^>]*>/i', '<br>', $html) ?? $html;

        $html = trim($html);

        return $html !== '' ? $html : null;
    }

    private function decodeEntitiesDeep(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Some sources double/triple-encode entities (e.g. "&amp;amp;nbsp;" or "&amp;amp;#39;").
        // Decode a few passes until stable.
        $prev = null;
        $cur = $value;
        for ($i = 0; $i < 3; $i++) {
            $prev = $cur;
            $cur = html_entity_decode($cur, ENT_QUOTES | ENT_HTML5);
            if ($cur === $prev) {
                break;
            }
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
