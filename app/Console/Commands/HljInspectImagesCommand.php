<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductExternalAsset;
use App\Models\ProductExternalContent;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\Products\Hlj\HljContentSyncService;
use App\Services\Products\Hlj\HljHtmlParser;
use App\Services\Products\Hlj\HljImageAcceptanceService;
use Illuminate\Console\Command;

final class HljInspectImagesCommand extends Command
{
    protected $signature = 'hlj:inspect-images
        {sku : Product SKU to inspect}
        {--limit=50 : Max URLs to print}
        {--show-stored : Also print currently stored HLJ asset filenames}
        {--probe-download : Also perform HTTP GET for extracted URLs and print status/size/mime}
        {--probe-search : Also probe HLJ search results for the candidate queries and print the first parsed PDP URL}
        {--run-sync : Run HLJ syncForProduct before printing stored assets (will replace HLJ assets if download succeeds)}';

    protected $description = 'Debug HLJ image extraction for a product: prints extracted URLs (post-filter) and optionally stored HLJ assets.';

    public function handle(ExternalHtmlClient $http, HljHtmlParser $parser, HljImageAcceptanceService $acceptance, HljContentSyncService $sync): int
    {
        $sku = trim((string) $this->argument('sku'));
        if ($sku === '') {
            $this->error('SKU is required.');

            return self::FAILURE;
        }

        /** @var Product|null $product */
        $product = Product::query()->where('sku', $sku)->first();
        if ($product === null) {
            $this->error("No product found for sku: {$sku}");

            return self::FAILURE;
        }

        $this->info("Product: {$product->sku} (id={$product->id})");

        if ((bool) $this->option('run-sync')) {
            $this->line('');
            $this->warn('Running HLJ syncForProduct (this may replace HLJ assets for the product)…');
            $sync->syncForProduct($product);
            $this->info('Sync completed.');
        }

        if ((bool) $this->option('probe-search')) {
            $this->line('');
            $this->info('Search probe (candidate queries → first PDP URL):');
            foreach ($this->candidateQueriesForProduct($product) as $q) {
                $probe = $this->probeSearch($http, $parser, $q);
                $status = (string) ($probe['status'] ?? '—');
                $title = (string) ($probe['title'] ?? '');
                $title = $title !== '' ? " title=\"{$title}\"" : '';
                $pdp = (string) ($probe['pdp'] ?? '');
                $pdp = $pdp !== '' ? $pdp : 'NOT FOUND';
                $this->line("- {$q} => {$pdp} (status={$status}{$title})");
            }
        }

        $pdpUrl = $this->storedHljPdpUrl((int) $product->id);
        if (! is_string($pdpUrl) || trim($pdpUrl) === '') {
            $this->warn('No HLJ PDP URL stored for this product (source_url is empty).');
            if (! (bool) $this->option('run-sync')) {
                $this->warn('Tip: re-run with --run-sync (and optionally --probe-search) to see why it is not finding a PDP.');
            }

            return self::SUCCESS;
        }

        $this->info("HLJ PDP: {$pdpUrl}");
        $res = $http->get($pdpUrl, [], 'hlj');
        $this->info('HTTP status: '.$res->status());
        if (! $res->successful()) {
            $this->error('Failed to fetch HLJ PDP.');

            return self::FAILURE;
        }

        $expectedCode = $parser->productCodeFromPdpUrl($pdpUrl);
        $urls = $parser->extractImageUrls((string) $res->body(), $expectedCode);
        $limit = (int) $this->option('limit');
        $limit = $limit > 0 ? $limit : 50;

        $this->line('');
        $this->info('Extracted image URLs (post-filter): '.count($urls));
        $shown = array_slice($urls, 0, $limit);
        foreach ($shown as $u) {
            $this->line($u);
        }

        if ((bool) $this->option('probe-download') && $shown !== []) {
            $this->line('');
            $this->info('Download probe (no files saved):');

            foreach ($shown as $u) {
                $imgRes = $http->get($u, [
                    'Accept' => 'image/*',
                    'Referer' => $pdpUrl,
                ], 'hlj');

                $mime = $imgRes->header('Content-Type');
                $mime = is_string($mime) ? trim(explode(';', $mime)[0]) : null;
                $body = $imgRes->body();
                $len = is_string($body) ? strlen($body) : 0;
                $ok = $imgRes->successful() && is_string($mime) && str_starts_with($mime, 'image/') && is_string($body) && $len > 0;

                $reason = '—';
                $dims = '—';
                $sha = '—';
                if ($ok && is_string($mime) && is_string($body)) {
                    $a = $acceptance->assess($u, $body, $mime, $expectedCode);
                    $ok = (bool) $a['accept'];
                    $reason = (string) ($a['reason'] ?? '—');
                    $w = $a['width'] ?? null;
                    $h = $a['height'] ?? null;
                    $dims = is_int($w) && is_int($h) ? "{$w}x{$h}" : '—';
                    $sha = substr((string) ($a['sha256'] ?? ''), 0, 12);
                    $sha = $sha !== '' ? $sha : '—';
                }

                $this->line(sprintf(
                    '- %s | status=%d mime=%s bytes=%d dims=%s sha=%s %s (%s)',
                    $u,
                    $imgRes->status(),
                    $mime ?? '—',
                    $len,
                    $dims,
                    $sha,
                    $ok ? '(accepted)' : '(rejected)',
                    $reason,
                ));
            }
        }

        if ((bool) $this->option('show-stored')) {
            $rows = ProductExternalAsset::query()
                ->where('product_id', (int) $product->id)
                ->where('source', 'hlj')
                ->orderBy('id')
                ->get(['filename', 'storage_path', 'size_bytes']);

            $this->line('');
            $this->info('Stored HLJ assets: '.$rows->count());
            foreach ($rows as $r) {
                $this->line((string) $r->filename.' ('.((string) ($r->size_bytes ?? '—')).' bytes) -> '.(string) $r->storage_path);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function candidateQueriesForProduct(Product $product): array
    {
        $out = [];
        $barcode = is_string($product->barcode) ? trim($product->barcode) : '';
        if ($barcode !== '') {
            $out[] = $barcode;
        }

        $sku = is_string($product->sku) ? trim($product->sku) : '';
        if ($sku !== '') {
            $out[] = $sku;
        }

        $name = is_string($product->description) ? trim($product->description) : '';
        if ($name !== '') {
            $out[] = $name;
        }

        return array_values(array_unique(array_filter($out)));
    }

    private function storedHljPdpUrl(int $productId): ?string
    {
        /** @var ProductExternalContent|null $content */
        $content = ProductExternalContent::query()
            ->where('product_id', $productId)
            ->where('source', 'hlj')
            ->first();

        $url = $content?->source_url;

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    /**
     * @return array{status:int|null,title:string|null,pdp:string|null}
     */
    private function probeSearch(ExternalHtmlClient $http, HljHtmlParser $parser, string $query): array
    {
        $q = rawurlencode($query);
        $searchUrls = [
            "https://www.hlj.com/search/?Word={$q}",
            "https://www.hlj.com/search/?q={$q}",
        ];

        foreach ($searchUrls as $searchUrl) {
            $res = $http->get($searchUrl, [], 'hlj');
            $status = $res->status();
            $html = (string) $res->body();
            $title = null;
            if (preg_match('/<title[^>]*>(.*?)<\\/title>/is', $html, $m) === 1) {
                $t = trim(strip_tags((string) ($m[1] ?? '')));
                $title = $t !== '' ? $t : null;
            }

            $pdp = $res->successful() ? $parser->extractPdpUrlFromSearchHtml($html) : null;
            if ($pdp !== null) {
                return [
                    'status' => $status,
                    'title' => $title,
                    'pdp' => $pdp,
                ];
            }
        }

        // Report the first attempt's status/title as best-effort context.
        $res = $http->get($searchUrls[0], [], 'hlj');
        $status = $res->status();
        $html = (string) $res->body();
        $title = null;
        if (preg_match('/<title[^>]*>(.*?)<\\/title>/is', $html, $m) === 1) {
            $t = trim(strip_tags((string) ($m[1] ?? '')));
            $title = $t !== '' ? $t : null;
        }

        return ['status' => $status, 'title' => $title, 'pdp' => null];
    }
}
