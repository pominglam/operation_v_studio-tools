<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Support;

use Illuminate\Support\Str;

final class HtmlPriceParser
{
    /**
     * @return array{price: float|null, original_price: float|null, availability: string|null}
     */
    public function extractPriceAndAvailabilityFromHtml(string $html): array
    {
        $htmlForExtraction = $this->extractPrimaryProductScope($html) ?? $html;

        $price = null;
        $originalPrice = null;
        $availability = null;

        // Prefer JSON-LD Product offers, but do NOT early-return; some sites (notably Shopify themes)
        // put compare-at/original prices only in the HTML markup.
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $m)) {
            foreach ($m[1] as $json) {
                $decoded = json_decode($json, true);
                if (! is_array($decoded)) {
                    continue;
                }

                $found = $this->findOfferInLdJson($decoded);
                if ($found['price'] !== null) {
                    $price = $found['price'];
                }
                if ($found['original_price'] !== null) {
                    $originalPrice = $found['original_price'];
                }
                if ($found['availability'] !== null) {
                    $availability = $found['availability'];
                }

                if ($price !== null || $originalPrice !== null || $availability !== null) {
                    break;
                }
            }
        }

        // Panda Hobby (Shopify theme) fast-path: extract from known price + availability widgets.
        // This avoids generic heuristics when the theme provides structured, predictable markup.
        $panda = $this->extractPandaOfferFromHtml($htmlForExtraction);
        // If Panda provided both prices, we can safely take them, but still allow the generic
        // availability fallback if Panda-specific availability isn't available on this snippet.
        if ($panda['price'] !== null) {
            $price = $panda['price'];
        }
        if ($panda['original_price'] !== null) {
            $originalPrice = $panda['original_price'];
        }
        if ($availability === null && $panda['availability'] !== null) {
            $availability = $panda['availability'];
        }

        // PrestaShop / microdata fast-path (e.g. Canadian Gundam):
        // Prefer itemprop="price" content="23.29" inside an Offer node to avoid picking up cart totals like "$0.00".
        if ($price === null) {
            $micro = $this->extractMicrodataOfferFromHtml($htmlForExtraction);
            if ($micro['price'] !== null) {
                $price = $micro['price'];
            }
            if ($originalPrice === null && $micro['original_price'] !== null) {
                $originalPrice = $micro['original_price'];
            }
            if ($availability === null && $micro['availability'] !== null) {
                $availability = $micro['availability'];
            }
        }

        // Magento (HobbyWholesale) fast-path: extract from structured price/stock blocks inside product-info-main.
        if ($price === null) {
            $magento = $this->extractMagentoOfferFromHtml($htmlForExtraction);
            if ($magento['price'] !== null) {
                $price = $magento['price'];
            }
            if ($originalPrice === null && $magento['original_price'] !== null) {
                $originalPrice = $magento['original_price'];
            }
            if ($availability === null && $magento['availability'] !== null) {
                $availability = $magento['availability'];
            }
        }

        // If Panda didn't provide a price, proceed with generic extraction.
        if ($price === null) {
            // Fallback: prefer explicit CAD prices (e.g. "$21.99 CAD") to avoid picking up unrelated
            // amounts like shipping thresholds or other currencies.
            $cad = $this->extractCadAmountsLikelyForPrice($htmlForExtraction);
            if ($cad !== []) {
                $price = $cad[0];
            }
        } else {
            // Ensure $cad is defined for the sale inference below.
            $cad = $this->extractCadAmountsLikelyForPrice($htmlForExtraction);
        }

        $dollars = $this->extractDollarAmountsLikelyForPrice($htmlForExtraction);
        if ($price === null && $dollars !== []) {
            $price = $dollars[0];
        }

        // Fallback: only infer sale (min=current, max=original) when the page indicates a compare-at/list price.
        // Shopify themes vary; many use class names rather than <del>.
        $looksLikeSale = preg_match(
            '/(<del\\b|<s\\b|line-through|compare\\s*at|compare_at|was\\s*\\$|save\\s*\\d+%|price-item--regular|price-item--sale|compare-at-price|price__compare-at|data-compare-price|price__current--on-sale|current\\s+price|original\\s+price)/i',
            $htmlForExtraction,
        ) === 1;
        if ($looksLikeSale && (count($cad) >= 2 || count($dollars) >= 2)) {
            $vals = array_values(array_unique(count($cad) >= 2 ? $cad : $dollars));
            sort($vals); // ascending

            // If we already have a price (e.g. JSON-LD), never override it using page-wide heuristics.
            // Instead, try to infer only the original/compare-at as the nearest higher value.
            if ($price !== null) {
                if ($originalPrice === null) {
                    $higher = array_values(array_filter($vals, static fn (float $v): bool => $v > $price));
                    if ($higher !== []) {
                        $candidate = $higher[0]; // nearest above price
                        $originalPrice = $candidate;
                    }
                }
            } else {
                if (count($vals) >= 2) {
                    $min = $vals[0];
                    $max = $vals[count($vals) - 1];
                    if ($max > $min) {
                        $price = $min;
                        $originalPrice = $max;
                    }
                }
            }
        }

        // Availability fallback (only if Panda didn't already supply it)
        if ($availability === null) {
            if (preg_match('/\\bSold\\s+Out\\b/i', $htmlForExtraction)) {
                $availability = 'sold_out';
            } elseif (preg_match('/\\bOut\\s+of\\s+Stock\\b/i', $htmlForExtraction)) {
                $availability = 'sold_out';
            } elseif (preg_match('/\\bAdd\\s+to\\s+cart\\b/i', $htmlForExtraction)) {
                $availability = 'in_stock';
            } elseif (preg_match('/\\bIn\\s+Stock\\b/i', $htmlForExtraction)) {
                $availability = 'in_stock';
            }
        }

        return [
            'price' => $price,
            'original_price' => $originalPrice,
            'availability' => $availability,
        ];
    }

    private function extractPrimaryProductScope(string $html): ?string
    {
        // Shopify: product meta block
        $shopify = $this->extractShopifyProductMetaScope($html);
        if ($shopify !== null) {
            return $shopify;
        }

        // Shopify (general PDP): scope around the product form when present to avoid picking up
        // unrelated product-card prices from recommendation widgets on PDP pages (notably Hobby Sense).
        $shopifyForm = $this->extractShopifyProductFormScope($html);
        if ($shopifyForm !== null) {
            return $shopifyForm;
        }

        // Magento (HobbyWholesale): product-info-main block
        $magento = $this->extractMagentoProductInfoMainScope($html);
        if ($magento !== null) {
            return $magento;
        }

        return null;
    }

    private function extractShopifyProductFormScope(string $html): ?string
    {
        // Most Shopify themes include a product form container on PDPs.
        if (! str_contains($html, 'data-product-form') && ! str_contains($html, 'product_form')) {
            return null;
        }

        $pos = mb_stripos($html, 'data-product-form');
        if ($pos === false) {
            $pos = mb_stripos($html, 'product_form');
        }
        if ($pos === false) {
            return null;
        }

        $start = max(0, (int) $pos - 6000);

        // Prefer ending at the product form close tag. This avoids picking up unrelated prices
        // from recommendation widgets later in the HTML.
        $endForm = mb_stripos($html, '</form>', (int) $pos);
        if ($endForm !== false) {
            $end = (int) $endForm + 7; // strlen('</form>')
            if ($end > $start) {
                return mb_substr($html, $start, $end - $start);
            }
        }

        // Fallback window (best-effort).
        return mb_substr($html, $start, 12000);
    }

    private function extractMagentoProductInfoMainScope(string $html): ?string
    {
        if (! str_contains($html, 'product-info-main')) {
            return null;
        }

        // Best-effort: capture the product-info-main section without trying to fully parse HTML.
        if (preg_match('/<div\\s+class=["\']product-info-main["\'][^>]*>[\\s\\S]*?<\\/div>\\s*<\\/div>\\s*<\\/div>/i', $html, $m) === 1) {
            return (string) $m[0];
        }

        // Conservative fallback window.
        $pos = mb_stripos($html, 'product-info-main');
        if ($pos === false) {
            return null;
        }

        return mb_substr($html, max(0, (int) $pos - 200), 16000);
    }

    private function extractShopifyProductMetaScope(string $html): ?string
    {
        // Many Shopify themes (including Hobby Bee) wrap the product core info in this container.
        // Scoping avoids picking up unrelated prices from FAQ/shipping widgets elsewhere on the page.
        if (! str_contains($html, 'product-single__meta')) {
            return null;
        }

        if (preg_match('/<div\\s+class=["\']product-single__meta\\b[^"\']*["\'][^>]*>[\\s\\S]*?<\\/div>\\s*(?=<div\\s+class=["\']product-single__description\\b|<div\\s+class=["\']social-sharing\\b|<\\/div>\\s*<\\/div>\\s*<\\/div>)/i', $html, $m) === 1) {
            return (string) $m[0];
        }

        // Conservative fallback: grab a limited window starting at the container.
        $pos = mb_stripos($html, 'product-single__meta');
        if ($pos === false) {
            return null;
        }

        return mb_substr($html, max(0, (int) $pos - 200), 12000);
    }

    /**
     * Microdata Offer extraction (common in PrestaShop).
     *
     * @return array{price: float|null, original_price: float|null, availability: string|null}
     */
    private function extractMicrodataOfferFromHtml(string $html): array
    {
        $price = null;
        $originalPrice = null;
        $availability = null;

        // Availability via microdata link
        if (preg_match('/itemprop=["\']availability["\'][^>]*href=["\'][^"\']*(InStock|OutOfStock)[^"\']*["\']/i', $html, $m) === 1) {
            $availability = str_contains($m[1], 'OutOfStock') ? 'sold_out' : 'in_stock';
        }

        // Prefer content="23.29" if present (most reliable)
        if (preg_match('/itemprop=["\']price["\'][^>]*content=["\']([0-9]{1,6}(?:\\.[0-9]{1,2})?)["\']/i', $html, $m) === 1) {
            $price = (float) $m[1];
        } elseif (preg_match('/itemprop=["\']price["\'][^>]*>\\s*\\$\\s*([0-9]{1,6}(?:\\.[0-9]{2})?)\\s*</i', $html, $m) === 1) {
            $price = (float) $m[1];
        }

        return [
            'price' => $price,
            'original_price' => $originalPrice,
            'availability' => $availability,
        ];
    }

    /**
     * Magento PDP extraction (e.g. HobbyWholesale).
     *
     * @return array{price: float|null, original_price: float|null, availability: string|null}
     */
    private function extractMagentoOfferFromHtml(string $html): array
    {
        $price = null;
        $originalPrice = null;
        $availability = null;

        if (preg_match('/\\bstock\\s+unavailable\\b/i', $html) === 1 || preg_match('/\\bOut\\s+of\\s+stock\\b/i', $html) === 1) {
            $availability = 'sold_out';
        } elseif (preg_match('/\\bstock\\s+available\\b/i', $html) === 1 || preg_match('/\\bIn\\s+stock\\b/i', $html) === 1) {
            $availability = 'in_stock';
        }

        // Current price
        if (preg_match('/data-price-type=["\']finalPrice["\'][^>]*data-price-amount=["\']([0-9]{1,6}(?:\\.[0-9]{1,2})?)["\']/i', $html, $m) === 1) {
            $price = (float) $m[1];
        } elseif (preg_match('/data-price-amount=["\']([0-9]{1,6}(?:\\.[0-9]{1,2})?)["\'][^>]*data-price-type=["\']finalPrice["\']/i', $html, $m) === 1) {
            $price = (float) $m[1];
        }

        // Original price (if present)
        if (preg_match('/data-price-type=["\']oldPrice["\'][^>]*data-price-amount=["\']([0-9]{1,6}(?:\\.[0-9]{1,2})?)["\']/i', $html, $m) === 1) {
            $originalPrice = (float) $m[1];
        }

        if ($price !== null && $originalPrice !== null && $originalPrice <= $price) {
            $originalPrice = null;
        }

        return [
            'price' => $price,
            'original_price' => $originalPrice,
            'availability' => $availability,
        ];
    }

    /**
     * Panda Hobby-specific extraction.
     *
     * Expected markup:
     * - Original/compare-at: <span class="money price__compare-at--single" data-price-compare>...$19.99 CAD...</span>
     * - Current (on sale):  <div class="price__current price__current--on-sale"> ... <span class="money" data-price>...$15.99 CAD...</span>
     * - Availability: "Online Shipping ... In stock|Out of stock" in an ".iia-title-text" block.
     *
     * @return array{price: float|null, original_price: float|null, availability: string|null}
     */
    private function extractPandaOfferFromHtml(string $html): array
    {
        // Gate on identifiers to avoid false positives on other sites.
        if (! str_contains($html, 'product-main') && ! str_contains($html, 'price__current--on-sale') && ! str_contains($html, 'iia-title-text')) {
            return ['price' => null, 'original_price' => null, 'availability' => null];
        }

        $price = null;
        $original = null;

        // Current on-sale price
        if (preg_match(
            '/price__current[^>]*price__current--on-sale[\s\S]*?(?:data-price[^>]*>)?[\s\S]*?\\$\\s*([0-9]{1,6}(?:\\.[0-9]{2})?)\\s*CAD\\b/i',
            $html,
            $m,
        )) {
            $price = (float) $m[1];
        }

        // Original / compare-at price
        if (preg_match(
            '/price__compare-at--single[^>]*data-price-compare[^>]*>[\s\S]*?\\$\\s*([0-9]{1,6}(?:\\.[0-9]{2})?)\\s*CAD\\b/i',
            $html,
            $m,
        )) {
            $original = (float) $m[1];
        }

        $availability = null;
        // Online Shipping availability
        if (preg_match(
            '/iia-title-text[\\s\\S]*?<span[^>]*class=["\']iia-name["\'][^>]*>\\s*Online\\s+Shipping\\s*<\\/span>[\\s\\S]*?<span[^>]*class=["\']iia-stock-threshold["\'][^>]*>\\s*(In stock|Out of stock)\\s*<\\/span>/i',
            $html,
            $m,
        )) {
            $availability = str_contains(mb_strtolower($m[1]), 'in stock') ? 'in_stock' : 'sold_out';
        }

        // If we got both prices but original <= current, don't treat as a sale.
        if ($price !== null && $original !== null && $original <= $price) {
            $original = null;
        }

        return [
            'price' => $price,
            'original_price' => $original,
            'availability' => $availability,
        ];
    }

    /**
     * Extract CAD-labelled dollar amounts that are likely to be product prices.
     *
     * @return array<int, float>
     */
    private function extractCadAmountsLikelyForPrice(string $html): array
    {
        if (! preg_match_all('/\\$\\s*([0-9]{1,6}(?:\\.[0-9]{2})?)\\s*(?:CAD|C\\$)\\b/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        /** @var array<int, array{0: string, 1: int}> $matches */
        $matches = $m[0] ?? [];

        $candidates = [];
        foreach ($matches as $match) {
            $raw = (string) ($match[0] ?? '');
            $pos = (int) ($match[1] ?? 0);

            if (! preg_match('/\\$\\s*([0-9]{1,6}(?:\\.[0-9]{2})?)/', $raw, $num)) {
                continue;
            }

            $value = (float) $num[1];
            $ctx = mb_strtolower(mb_substr($html, max(0, $pos - 60), 160));

            // Ignore obvious non-price contexts.
            $hasShippingPolicy = str_contains($ctx, 'shipping policy');
            $looksLikeShippingFee = str_contains($ctx, 'shipping:') || str_contains($ctx, 'delivery:') || str_contains($ctx, 'free ship') || str_contains($ctx, 'orders');
            $looksPriceSemantic = str_contains($ctx, 'itemprop="price"') || str_contains($ctx, "itemprop='price'") || str_contains($ctx, 'productprice') || str_contains($ctx, 'product-single__prices') || str_contains($ctx, 'money');

            // "Shipping Policy" links often sit near the real product price on PDPs; don't exclude those.
            if (! $hasShippingPolicy && $looksLikeShippingFee && ! $looksPriceSemantic) {
                continue;
            }

            $score = 0;
            if (str_contains($ctx, 'price')) $score += 3;
            if (str_contains($ctx, 'product')) $score += 1;
            if (str_contains($ctx, 'add to cart')) $score += 1;
            if (str_contains($ctx, 'sold out')) $score += 1;
            if (str_contains($ctx, 'tax')) $score -= 2;
            if ($looksPriceSemantic) $score += 4;

            $candidates[] = [
                'value' => $value,
                'score' => $score,
            ];
        }

        if ($candidates === []) {
            return [];
        }

        usort($candidates, function (array $a, array $b): int {
            // score desc, then value desc
            $cmp = ($b['score'] <=> $a['score']);
            if ($cmp !== 0) return $cmp;
            return ($b['value'] <=> $a['value']);
        });

        $vals = array_map(static fn (array $c): float => (float) $c['value'], $candidates);
        $vals = array_values(array_unique($vals));

        return $vals;
    }

    /**
     * Extract dollar amounts that are likely to be product prices (not shipping thresholds, etc).
     *
     * @return array<int, float>
     */
    private function extractDollarAmountsLikelyForPrice(string $html): array
    {
        if (! preg_match_all('/\\$\\s*([0-9]{1,6}(?:\\.[0-9]{2})?)/', $html, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        /** @var array<int, array{0: string, 1: int}> $matches */
        $matches = $m[0] ?? [];

        $vals = [];
        foreach ($matches as $match) {
            $raw = (string) ($match[0] ?? '');
            $pos = (int) ($match[1] ?? 0);

            if (! preg_match('/\\$\\s*([0-9]{1,6}(?:\\.[0-9]{2})?)/', $raw, $num)) {
                continue;
            }

            $value = (float) $num[1];

            // Heuristic: ignore prices that appear in "shipping" contexts like:
            // "FREE SHIPPING on orders $80 or more", etc.
            $ctx = mb_strtolower(mb_substr($html, max(0, $pos - 40), 120));
            // Avoid obvious cart totals / header totals (common on PrestaShop pages).
            if (str_contains($ctx, 'ajax_block_cart_total') || str_contains($ctx, 'cart_block_total')) {
                continue;
            }
            if (str_contains($ctx, 'shipping') || str_contains($ctx, 'free ship') || str_contains($ctx, 'orders') || str_contains($ctx, 'order $')) {
                continue;
            }

            $vals[] = $value;
        }

        $vals = array_values(array_unique($vals));

        return $vals;
    }

    /**
     * @return array<int, string>
     */
    public function extractCandidateProductUrls(string $html, string $baseUrl): array
    {
        $base = rtrim($baseUrl, '/');
        $isHobbyWholesale = Str::contains($base, 'hobbywholesale.com');
        $isMeeplemart = Str::contains($base, 'meeplemart.com');
        $isCanadianGundam = Str::contains($base, 'canadiangundam.com');

        // Find all href values.
        preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $m);
        $hrefs = $m[1] ?? [];

        $urls = [];
        foreach ($hrefs as $href) {
            $href = trim((string) $href);
            // Decode HTML entities inside hrefs (e.g. "&amp;") so we can match and fetch correctly.
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
            if ($href === '' || Str::startsWith($href, ['#', 'mailto:', 'tel:', 'javascript:'])) {
                continue;
            }

            if (Str::startsWith($href, ['http://', 'https://'])) {
                $url = $href;
            } elseif (Str::startsWith($href, '/')) {
                $url = $base.$href;
            } else {
                continue;
            }

            // Only accept PDP URLs. Collection/search pages often contain unrelated prices.
            $isKnownPdpPath = Str::contains($url, ['/products/', '/product/', '/gundam-model-kits/']);
            $isHobbyWholesaleProduct = $isHobbyWholesale
                && preg_match('/\\.html(?:\\?|$)/i', $url) === 1
                // HobbyWholesale (Magento) uses .html for categories too. For Bandai kits we rely on their part#
                // marker embedded in the slug (e.g. ban5060358) to avoid landing on category pages like
                // /models/plastic-models.html.
                && preg_match('/\\bban\\d{6,}\\b/i', $url) === 1
                && ! Str::contains($url, ['/search/', '/customer/', '/checkout/', '/cart/', '/catalogsearch/']);
            // Meeplemart product pages are typically "folder/file.aspx" (e.g. "/gundam-seed.../144-13-....aspx"),
            // while categories are usually root-level "/model-kits-8338.aspx". Exclude /store/* entirely.
            $meeplemartPath = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            $isMeeplemartProduct = $isMeeplemart
                && Str::endsWith(Str::lower($url), '.aspx')
                && ! Str::contains(Str::lower($url), '/store/')
                && preg_match('/\\/[^\\/]+\\/[^\\/]+\\.aspx\\b/i', $meeplemartPath) === 1;
            // CanadianGundam (PrestaShop) PDPs are usually /{category}/{id}-{slug}.html?... (category varies).
            $isCanadianGundamProduct = $isCanadianGundam
                && preg_match('/\\.html(?:\\?|$)/i', $url) === 1
                && preg_match('/\\/\\d+-[^\\/\\?]+\\.html\\b/i', $url) === 1
                && ! Str::contains(Str::lower($url), ['/search', '/cart', '/order', '/my-account', '/contact-us', '/sitemap']);

            if (! $isKnownPdpPath && ! $isHobbyWholesaleProduct && ! $isMeeplemartProduct && ! $isCanadianGundamProduct) {
                continue;
            }

            if (preg_match('/\\/products\\/gift-?card(s)?\\b/i', $url) === 1) {
                continue;
            }

            $urls[] = $this->normalizeUrl($url);
        }

        return array_values(array_unique($urls));
    }

    public function extractPriceFromHtml(string $html): ?float
    {
        // Prefer JSON-LD Product offers price.
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $m)) {
            foreach ($m[1] as $json) {
                $decoded = json_decode($json, true);
                if (! is_array($decoded)) {
                    continue;
                }

                $price = $this->findPriceInLdJson($decoded);
                if ($price !== null) {
                    return $price;
                }
            }
        }

        // Fallback: first $X.YY occurrence.
        if (preg_match('/\\$\\s*([0-9]{1,6}(?:\\.[0-9]{2})?)/', $html, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function findPriceInLdJson(array $decoded): ?float
    {
        // JSON-LD can be an array, graph, or a single object.
        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            foreach ($decoded['@graph'] as $node) {
                if (is_array($node)) {
                    $p = $this->findPriceInLdJson($node);
                    if ($p !== null) return $p;
                }
            }
        }

        if (isset($decoded['offers']) && is_array($decoded['offers'])) {
            $offers = $decoded['offers'];
            // Offers can be a list or object.
            if (isset($offers['price'])) {
                return (float) $offers['price'];
            }
            foreach ($offers as $offer) {
                if (is_array($offer) && isset($offer['price'])) {
                    return (float) $offer['price'];
                }
            }
        }

        if (isset($decoded['price'])) {
            return (float) $decoded['price'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{price: float|null, original_price: float|null, availability: string|null}
     */
    private function findOfferInLdJson(array $decoded): array
    {
        // JSON-LD can be an array, graph, or a single object.
        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            foreach ($decoded['@graph'] as $node) {
                if (is_array($node)) {
                    $o = $this->findOfferInLdJson($node);
                    if ($o['price'] !== null || $o['availability'] !== null) {
                        return $o;
                    }
                }
            }
        }

        $price = null;
        $originalPrice = null;
        $availability = null;

        if (isset($decoded['offers']) && is_array($decoded['offers'])) {
            $offers = $decoded['offers'];

            $offerObj = null;
            if (isset($offers['price']) || isset($offers['availability'])) {
                $offerObj = $offers;
            } else {
                foreach ($offers as $offer) {
                    if (is_array($offer) && (isset($offer['price']) || isset($offer['availability']))) {
                        $offerObj = $offer;
                        break;
                    }
                }
            }

            if (is_array($offerObj)) {
                if (isset($offerObj['price'])) {
                    $price = (float) $offerObj['price'];
                }

                // Some JSON-LD includes highPrice/lowPrice for ranges; when present treat highPrice as original if > price.
                if (isset($offerObj['highPrice'])) {
                    $hp = (float) $offerObj['highPrice'];
                    if ($price === null || $hp > $price) {
                        $originalPrice = $hp;
                    }
                }
                if (isset($offerObj['priceSpecification']) && is_array($offerObj['priceSpecification'])) {
                    $ps = $offerObj['priceSpecification'];
                    // Handle list of price specifications.
                    foreach (is_array($ps) && array_is_list($ps) ? $ps : [$ps] as $spec) {
                        if (! is_array($spec)) continue;
                        if (! isset($spec['price'])) continue;
                        $pt = (string) ($spec['priceType'] ?? '');
                        if (str_contains($pt, 'ListPrice') || str_contains($pt, 'MSRP')) {
                            $originalPrice = (float) $spec['price'];
                            break;
                        }
                    }
                }

                if (isset($offerObj['availability']) && is_string($offerObj['availability'])) {
                    if (str_contains($offerObj['availability'], 'OutOfStock')) {
                        $availability = 'sold_out';
                    } elseif (str_contains($offerObj['availability'], 'InStock')) {
                        $availability = 'in_stock';
                    }
                }
            }
        }

        return [
            'price' => $price,
            'original_price' => $originalPrice,
            'availability' => $availability,
        ];
    }

    private function normalizeUrl(string $url): string
    {
        // strip fragments
        $url = preg_replace('/#.*/', '', $url) ?? $url;
        return $url;
    }
}


