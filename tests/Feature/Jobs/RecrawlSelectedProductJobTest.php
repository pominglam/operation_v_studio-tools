<?php

declare(strict_types=1);

use App\Models\ProductExternalAsset;
use App\Jobs\RecrawlSelectedProductJob;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

it('runs gundamplanet sync when selected as a recrawl source', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070100',
        'sku' => 'SKU-1',
        'description' => 'RG GOD GUNDAM',
        'vendor' => 'V',
    ]);

    $searchHtml = <<<HTML
    <html><body>
      <a href="/products/rg-god-gundam">RG God Gundam (Burning Gundam)</a>
    </body></html>
    HTML;

    $pdpHtml = <<<HTML
    <html><body>
      <img src="https://cdn.example.com/outside.jpg" />
      <product-gallery>
        <img src="https://cdn.shopify.com/files/gp1.jpg" />
        <img srcset="https://cdn.shopify.com/files/gp2_200.jpg 200w, https://cdn.shopify.com/files/gp2_900.jpg 900w" />
      </product-gallery>
    </body></html>
    HTML;

    Http::fake(function (\Illuminate\Http\Client\Request $req) use ($searchHtml, $pdpHtml) {
        $url = $req->url();
        if (str_contains($url, 'www.gundamplanet.com/search?')) {
            return Http::response($searchHtml, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        if (str_contains($url, 'www.gundamplanet.com/products/rg-god-gundam')) {
            return Http::response($pdpHtml, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        if (str_contains($url, 'cdn.shopify.com/files/gp1.jpg')) {
            return Http::response('img1', 200, ['Content-Type' => 'image/jpeg']);
        }
        if (str_contains($url, 'cdn.shopify.com/files/gp2_900.jpg')) {
            return Http::response('img2', 200, ['Content-Type' => 'image/jpeg']);
        }
        return Http::response('not found', 404);
    });

    $batchId = 'a0ae0689-dd2d-4812-aa44-73b294d283cb';
    // Ensure batch exists so Batchable::batch() can resolve it.
    DB::table('job_batches')->updateOrInsert(
        ['id' => $batchId],
        [
            'name' => 'recrawl_selected_products',
            'total_jobs' => 1,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => '[]',
            'created_at' => now()->timestamp,
            'cancelled_at' => null,
            'finished_at' => null,
        ],
    );

    $job = new RecrawlSelectedProductJob('sync-uuid', '00000000-0000-0000-0000-000000070100', ['gundamplanet']);
    // Inject batch id into the job (Batchable stores it internally).
    $ref = new ReflectionClass($job);
    if ($ref->hasProperty('batchId')) {
        $prop = $ref->getProperty('batchId');
        $prop->setAccessible(true);
        $prop->setValue($job, $batchId);
    }

    $job->handle(
        app(\App\DAL\Products\ProductRepository::class),
        app(\App\Services\Products\PlamodAssetSyncService::class),
        app(\App\Services\Products\Hlj\HljContentSync::class),
        app(\App\Services\Products\GundamPlanet\GundamPlanetContentSyncService::class),
        app(\App\Services\Products\Newtype\NewtypeContentSyncService::class),
        app(\App\Services\Products\Bandai\BandaiContentSyncService::class),
        app(\App\Services\PriceResearch\PriceResearchService::class),
        app(\App\Services\Jobs\JobBatchItemService::class),
    );

    expect(ProductExternalAsset::query()->where('product_id', $p->id)->where('source', 'gundamplanet')->count())->toBe(2);
    Storage::disk('local')->assertExists('gundamplanet/images/SKU-1/gundamplanet-SKU-1-1.jpg');

    // Confirm trace is appended for quick UI debugging.
    $debug = (string) \Illuminate\Support\Facades\DB::table('job_batch_items')
        ->where('batch_id', '=', $batchId)
        ->where('product_uuid', '=', $p->uuid)
        ->value('debug_log');
    expect($debug)->toContain('[job] sources=gundamplanet');
    expect($debug)->toContain('[gundamplanet][start]');
});

it('runs newtype sync when selected as a recrawl source', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070200',
        'sku' => 'SKU-NT-1',
        'description' => '1/144 HGAC WING GUNDAM ZERO',
        'vendor' => 'V',
    ]);

    $searchHtml = <<<HTML
    <html><body>
      <a href="/p/AAA/h/hgac-174-wing-gundam-zero">Bandai HGAC 174 Wing Gundam Zero</a>
    </body></html>
    HTML;

    $pdpHtml = <<<HTML
    <html><head>
      <meta property="og:title" content="Bandai HGAC 174 Wing Gundam Zero - Newtype" />
      <script type="application/ld+json">
        {"@type":"Product","description":"<p>From Newtype</p>"}
      </script>
    </head><body>
      <div class="pt-square relative w-full overflow-hidden">
        <img alt="box art" src="https://cdn.shopify.com/s/files/1/2786/5582/products/box.jpg?v=1" />
        <div class="absolute" style="background-image:url('https://cdn.shopify.com/s/files/1/2786/5582/products/other.jpg?v=2')"></div>
      </div>

      <table class="-ml-px"><tbody>
        <tr><td class="pr-5 w-1/4">Scale</td><td>1/144</td></tr>
        <tr><td>Line</td><td><a href="/t/modelkit/line/hg"> HG - High Grade </a></td></tr>
        <tr><td class="pr-5 w-1/4">Brand</td><td><a href="/b/gundam"> Mobile Suit Gundam </a></td></tr>
        <tr><td class="pr-5 w-1/4">Series</td><td><a href="/t/modelkit/series/gundamwing"> Gundam Wing </a></td></tr>
      </tbody></table>
    </body></html>
    HTML;

    Http::fake(function (\Illuminate\Http\Client\Request $req) use ($searchHtml, $pdpHtml) {
        $url = $req->url();
        if (str_contains($url, 'newtype.us/search?')) {
            return Http::response($searchHtml, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        if (str_contains($url, 'newtype.us/p/AAA/h/hgac-174-wing-gundam-zero')) {
            return Http::response($pdpHtml, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        if (str_contains($url, 'cdn.shopify.com/s/files/1/2786/5582/products/box.jpg')) {
            return Http::response('img1', 200, ['Content-Type' => 'image/jpeg']);
        }
        if (str_contains($url, 'cdn.shopify.com/s/files/1/2786/5582/products/other.jpg')) {
            return Http::response('img2', 200, ['Content-Type' => 'image/jpeg']);
        }
        return Http::response('not found', 404);
    });

    $batchId = 'b0ae0689-dd2d-4812-aa44-73b294d283cb';
    DB::table('job_batches')->updateOrInsert(
        ['id' => $batchId],
        [
            'name' => 'recrawl_selected_products',
            'total_jobs' => 1,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => '[]',
            'created_at' => now()->timestamp,
            'cancelled_at' => null,
            'finished_at' => null,
        ],
    );

    $job = new RecrawlSelectedProductJob('sync-uuid', '00000000-0000-0000-0000-000000070200', ['newtype']);
    $ref = new ReflectionClass($job);
    if ($ref->hasProperty('batchId')) {
        $prop = $ref->getProperty('batchId');
        $prop->setAccessible(true);
        $prop->setValue($job, $batchId);
    }

    $job->handle(
        app(\App\DAL\Products\ProductRepository::class),
        app(\App\Services\Products\PlamodAssetSyncService::class),
        app(\App\Services\Products\Hlj\HljContentSync::class),
        app(\App\Services\Products\GundamPlanet\GundamPlanetContentSyncService::class),
        app(\App\Services\Products\Newtype\NewtypeContentSyncService::class),
        app(\App\Services\Products\Bandai\BandaiContentSyncService::class),
        app(\App\Services\PriceResearch\PriceResearchService::class),
        app(\App\Services\Jobs\JobBatchItemService::class),
    );

    expect(ProductExternalAsset::query()->where('product_id', $p->id)->where('source', 'newtype')->count())->toBe(2);
    Storage::disk('local')->assertExists('newtype/images/SKU-NT-1/newtype-SKU-NT-1-1.jpg');

    $fresh = Product::query()->whereKey($p->id)->firstOrFail();
    expect($fresh->scale)->toBe('1/144');
    expect($fresh->grade)->toBe('HG');
    expect($fresh->brand)->toBe('Mobile Suit Gundam');
    expect($fresh->series)->toBe('Gundam Wing');

    $content = \App\Models\ProductExternalContent::query()
        ->where('product_id', '=', $p->id)
        ->where('source', '=', 'newtype')
        ->first();
    expect($content)->not->toBeNull();
    expect((string) ($content?->description_html ?? ''))->toContain('From Newtype');

    $debug = (string) \Illuminate\Support\Facades\DB::table('job_batch_items')
        ->where('batch_id', '=', $batchId)
        ->where('product_uuid', '=', $p->uuid)
        ->value('debug_log');
    expect($debug)->toContain('[job] sources=newtype');
    expect($debug)->toContain('[newtype][start]');
    expect($debug)->toContain('[newtype][summary]');
});

