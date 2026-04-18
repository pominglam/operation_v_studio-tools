<?php

declare(strict_types=1);

use App\Jobs\RecrawlSelectedProductJob;
use App\Models\Product;
use App\Models\ProductExternalAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('runs gundamplanet sync when selected as a recrawl source', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070100',
        'sku' => 'SKU-1',
        'description' => 'RG GOD GUNDAM',
        'vendor' => 'V',
    ]);

    $searchHtml = <<<'HTML'
    <html><body>
      <a href="/products/rg-god-gundam">RG God Gundam (Burning Gundam)</a>
    </body></html>
    HTML;

    $pdpHtml = <<<'HTML'
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
        app(\App\Services\Products\GundamHangar\GundamHangarContentSyncService::class),
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

    $searchHtml = <<<'HTML'
    <html><body>
      <a href="/p/AAA/h/hgac-174-wing-gundam-zero">Bandai HGAC 174 Wing Gundam Zero</a>
    </body></html>
    HTML;

    $pdpHtml = <<<'HTML'
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
        app(\App\Services\Products\GundamHangar\GundamHangarContentSyncService::class),
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

it('runs gundamhangar sync when selected as a recrawl source', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070300',
        'sku' => 'SKU-GH-1',
        'description' => 'MG RX-78-2 GUNDAM',
        'vendor' => 'V',
    ]);

    Http::fake(function (\Illuminate\Http\Client\Request $req) {
        $url = $req->url();
        if (str_contains($url, 'server.gundamhangar.com/api/products?')) {
            return Http::response([
                'data' => [[
                    'title' => 'P.Bandai MG 1/100 RX78FRGMT Gundam [FRGMT VA Exclusive Ver.]',
                    'slug' => 'p-bandai-mg-1-100-rx78frgmt-gundam-frgmt-va-exclusive-ver',
                    'description' => '<p>From GundamHangar</p>',
                    'featured_image' => 'https://s3.amazonaws.com/gundamhangar.com/products/p-bandai-mg-1-100-rx78frgmt-gundam-frgmt-va-exclusive-ver/0.jpg',
                    'image_number' => 2,
                    'attributes' => [
                        ['name' => 'Gundam Series', 'pivot' => ['value' => 'Mobile Suit Gundam']],
                        ['name' => 'Gundam Kits Brands', 'pivot' => ['value' => 'P.Bandai']],
                    ],
                ]],
            ], 200, ['Content-Type' => 'application/json']);
        }
        if (str_contains($url, 'server.gundamhangar.com/api/product-similar/p-bandai-mg-1-100-rx78frgmt-gundam-frgmt-va-exclusive-ver')) {
            return Http::response(['data' => []], 200, ['Content-Type' => 'application/json']);
        }
        if (str_contains($url, '/p-bandai-mg-1-100-rx78frgmt-gundam-frgmt-va-exclusive-ver/0.jpg')) {
            return Http::response('img0', 200, ['Content-Type' => 'image/jpeg']);
        }
        if (str_contains($url, '/p-bandai-mg-1-100-rx78frgmt-gundam-frgmt-va-exclusive-ver/1.jpg')) {
            return Http::response('img1', 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response('not found', 404);
    });

    $batchId = 'c0ae0689-dd2d-4812-aa44-73b294d283cb';
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

    $job = new RecrawlSelectedProductJob('sync-uuid', '00000000-0000-0000-0000-000000070300', ['gundamhangar']);
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
        app(\App\Services\Products\GundamHangar\GundamHangarContentSyncService::class),
        app(\App\Services\Products\Bandai\BandaiContentSyncService::class),
        app(\App\Services\PriceResearch\PriceResearchService::class),
        app(\App\Services\Jobs\JobBatchItemService::class),
    );

    expect(ProductExternalAsset::query()->where('product_id', $p->id)->where('source', 'gundamhangar')->count())->toBe(2);
    Storage::disk('local')->assertExists('gundamhangar/images/SKU-GH-1/gundamhangar-SKU-GH-1-1.jpg');

    $content = \App\Models\ProductExternalContent::query()
        ->where('product_id', '=', $p->id)
        ->where('source', '=', 'gundamhangar')
        ->first();
    expect($content)->not->toBeNull();
    expect((string) ($content?->description_html ?? ''))->toContain('From GundamHangar');

    $debug = (string) \Illuminate\Support\Facades\DB::table('job_batch_items')
        ->where('batch_id', '=', $batchId)
        ->where('product_uuid', '=', $p->uuid)
        ->value('debug_log');
    expect($debug)->toContain('[job] sources=gundamhangar');
    expect($debug)->toContain('[gundamhangar][start]');
    expect($debug)->toContain('[gundamhangar][summary]');
});

it('continues gundamhangar sync when one search term throws', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070301',
        'sku' => 'JS-BLACKTROJAN1100G-53DCA60A',
        'description' => 'Black Trojan 1/100 Gangsuosi (Gundzilla) Model Kit',
        'vendor' => 'V',
    ]);

    $threwRedirect = false;
    Http::fake(function (\Illuminate\Http\Client\Request $req) use (&$threwRedirect) {
        $url = $req->url();
        if (str_contains($url, 'server.gundamhangar.com/api/products?')) {
            if (! $threwRedirect) {
                $threwRedirect = true;
                throw new \RuntimeException('Will not follow more than 10 redirects');
            }

            return Http::response([
                'data' => [[
                    'title' => 'Black Trojan 1/100 Gangsuosi (Gundzilla) Model Kit',
                    'slug' => 'black-trojan-1-100-gangsuosi-gundzilla-model-kit',
                    'description' => '<p>From GundamHangar</p>',
                    'featured_image' => 'https://s3.amazonaws.com/gundamhangar.com/products/black-trojan-1-100-gangsuosi-gundzilla-model-kit/0.jpg',
                    'image_number' => 2,
                    'attributes' => [],
                ]],
            ], 200, ['Content-Type' => 'application/json']);
        }
        if (str_contains($url, 'server.gundamhangar.com/api/product-similar/black-trojan-1-100-gangsuosi-gundzilla-model-kit')) {
            return Http::response(['data' => []], 200, ['Content-Type' => 'application/json']);
        }
        if (str_contains($url, '/black-trojan-1-100-gangsuosi-gundzilla-model-kit/0.jpg')) {
            return Http::response('img0', 200, ['Content-Type' => 'image/jpeg']);
        }
        if (str_contains($url, '/black-trojan-1-100-gangsuosi-gundzilla-model-kit/1.jpg')) {
            return Http::response('img1', 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response('not found', 404);
    });

    $batchId = 'c0ae0689-dd2d-4812-aa44-73b294d283cc';
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

    $job = new RecrawlSelectedProductJob('sync-uuid', '00000000-0000-0000-0000-000000070301', ['gundamhangar']);
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
        app(\App\Services\Products\GundamHangar\GundamHangarContentSyncService::class),
        app(\App\Services\Products\Bandai\BandaiContentSyncService::class),
        app(\App\Services\PriceResearch\PriceResearchService::class),
        app(\App\Services\Jobs\JobBatchItemService::class),
    );

    expect(ProductExternalAsset::query()->where('product_id', $p->id)->where('source', 'gundamhangar')->count())->toBe(2);

    $debug = (string) \Illuminate\Support\Facades\DB::table('job_batch_items')
        ->where('batch_id', '=', $batchId)
        ->where('product_uuid', '=', $p->uuid)
        ->value('debug_log');
    expect($debug)->toContain('[gundamhangar][search_exception]');
    expect($debug)->toContain('[gundamhangar][summary] result=ok');
});

it('continues gundamhangar sync when pdp ajax call throws', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070302',
        'sku' => 'SKU-GH-2',
        'description' => 'MG Test Product',
        'vendor' => 'V',
    ]);

    Http::fake(function (\Illuminate\Http\Client\Request $req) {
        $url = $req->url();
        if (str_contains($url, 'server.gundamhangar.com/api/products?')) {
            return Http::response([
                'data' => [[
                    'title' => 'MG Test Product',
                    'slug' => 'mg-test-product',
                    'description' => '<p>From GundamHangar</p>',
                    'featured_image' => 'https://s3.amazonaws.com/gundamhangar.com/products/mg-test-product/0.jpg',
                    'image_number' => 2,
                    'attributes' => [],
                ]],
            ], 200, ['Content-Type' => 'application/json']);
        }
        if (str_contains($url, 'server.gundamhangar.com/api/product-similar/mg-test-product')) {
            throw new \RuntimeException('temporary upstream failure');
        }
        if (str_contains($url, '/mg-test-product/0.jpg')) {
            return Http::response('img0', 200, ['Content-Type' => 'image/jpeg']);
        }
        if (str_contains($url, '/mg-test-product/1.jpg')) {
            return Http::response('img1', 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response('not found', 404);
    });

    $batchId = 'c0ae0689-dd2d-4812-aa44-73b294d283cd';
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

    $job = new RecrawlSelectedProductJob('sync-uuid', '00000000-0000-0000-0000-000000070302', ['gundamhangar']);
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
        app(\App\Services\Products\GundamHangar\GundamHangarContentSyncService::class),
        app(\App\Services\Products\Bandai\BandaiContentSyncService::class),
        app(\App\Services\PriceResearch\PriceResearchService::class),
        app(\App\Services\Jobs\JobBatchItemService::class),
    );

    expect(ProductExternalAsset::query()->where('product_id', $p->id)->where('source', 'gundamhangar')->count())->toBe(2);

    $debug = (string) \Illuminate\Support\Facades\DB::table('job_batch_items')
        ->where('batch_id', '=', $batchId)
        ->where('product_uuid', '=', $p->uuid)
        ->value('debug_log');
    expect($debug)->toContain('[gundamhangar][pdp_ajax_exception]');
    expect($debug)->toContain('[gundamhangar][summary] result=ok');
});

it('continues gundamhangar sync when one image download throws', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070303',
        'sku' => 'SKU-GH-3',
        'description' => 'MG Partial Image Product',
        'vendor' => 'V',
    ]);

    Http::fake(function (\Illuminate\Http\Client\Request $req) {
        $url = $req->url();
        if (str_contains($url, 'server.gundamhangar.com/api/products?')) {
            return Http::response([
                'data' => [[
                    'title' => 'MG Partial Image Product',
                    'slug' => 'mg-partial-image-product',
                    'description' => '<p>From GundamHangar</p>',
                    'featured_image' => 'https://s3.amazonaws.com/gundamhangar.com/products/mg-partial-image-product/0.jpg',
                    'image_number' => 2,
                    'attributes' => [],
                ]],
            ], 200, ['Content-Type' => 'application/json']);
        }
        if (str_contains($url, 'server.gundamhangar.com/api/product-similar/mg-partial-image-product')) {
            return Http::response(['data' => []], 200, ['Content-Type' => 'application/json']);
        }
        if (str_contains($url, '/mg-partial-image-product/0.jpg')) {
            throw new \RuntimeException('image transient failure');
        }
        if (str_contains($url, '/mg-partial-image-product/1.jpg')) {
            return Http::response('img1', 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response('not found', 404);
    });

    $batchId = 'c0ae0689-dd2d-4812-aa44-73b294d283ce';
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

    $job = new RecrawlSelectedProductJob('sync-uuid', '00000000-0000-0000-0000-000000070303', ['gundamhangar']);
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
        app(\App\Services\Products\GundamHangar\GundamHangarContentSyncService::class),
        app(\App\Services\Products\Bandai\BandaiContentSyncService::class),
        app(\App\Services\PriceResearch\PriceResearchService::class),
        app(\App\Services\Jobs\JobBatchItemService::class),
    );

    expect(ProductExternalAsset::query()->where('product_id', $p->id)->where('source', 'gundamhangar')->count())->toBe(1);

    $debug = (string) \Illuminate\Support\Facades\DB::table('job_batch_items')
        ->where('batch_id', '=', $batchId)
        ->where('product_uuid', '=', $p->uuid)
        ->value('debug_log');
    expect($debug)->toContain('[gundamhangar][image_download_exception]');
    expect($debug)->toContain('[gundamhangar][summary] result=ok');
});

it('resolves black trojan via encoded search and product detail endpoint', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070304',
        'sku' => 'JS-BLACKTROJAN1100G-53DCA60A',
        'description' => 'Black Trojan 1/100 Gangsuosi (Gundzilla) Model Kit',
        'vendor' => 'V',
    ]);

    Http::fake(function (\Illuminate\Http\Client\Request $req) {
        $url = $req->url();
        if (str_contains($url, 'server.gundamhangar.com/api/products?')) {
            if (! str_contains($url, '%25')) {
                return Http::response(['data' => []], 200, ['Content-Type' => 'application/json']);
            }

            return Http::response([
                'data' => [[
                    'title' => 'Black Trojan 1/100 Gangsuosi (Gundzilla) Model Kit',
                    'slug' => 'black-trojan-1-100-gangsuosi-gundzilla-model-kit',
                    'description' => '<p>From search endpoint</p>',
                    'featured_image' => 'https://s3.amazonaws.com/gundamhangar.com/product-other-hobby/black-trojan-1-100-gangsuosi-gundzilla-model-kit/0.jpg',
                    'image_number' => 8,
                    'attributes' => [],
                ]],
            ], 200, ['Content-Type' => 'application/json']);
        }
        if (str_contains($url, 'server.gundamhangar.com/api/product-similar/black-trojan-1-100-gangsuosi-gundzilla-model-kit')) {
            return Http::response(['data' => []], 200, ['Content-Type' => 'application/json']);
        }
        if (str_contains($url, 'server.gundamhangar.com/api/product/black-trojan-1-100-gangsuosi-gundzilla-model-kit')) {
            return Http::response([
                'data' => [[
                    'title' => 'Black Trojan 1/100 Gangsuosi (Gundzilla) Model Kit',
                    'slug' => 'black-trojan-1-100-gangsuosi-gundzilla-model-kit',
                    'description' => '<p>From product endpoint</p>',
                    'attributes' => [
                        ['name' => 'Gundam Kits Brands', 'pivot' => ['value' => 'Black Trojan']],
                    ],
                ]],
                'images' => [
                    'https://s3.amazonaws.com/gundamhangar.com/product-other-hobby/black-trojan-1-100-gangsuosi-gundzilla-model-kit/1.jpg?sig=abc',
                    'https://s3.amazonaws.com/gundamhangar.com/product-other-hobby/black-trojan-1-100-gangsuosi-gundzilla-model-kit/2.jpg?sig=def',
                ],
            ], 200, ['Content-Type' => 'application/json']);
        }
        if (str_contains($url, '/black-trojan-1-100-gangsuosi-gundzilla-model-kit/1.jpg')) {
            return Http::response('img1', 200, ['Content-Type' => 'image/jpeg']);
        }
        if (str_contains($url, '/black-trojan-1-100-gangsuosi-gundzilla-model-kit/2.jpg')) {
            return Http::response('img2', 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response('not found', 404);
    });

    $batchId = 'c0ae0689-dd2d-4812-aa44-73b294d283cf';
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

    $job = new RecrawlSelectedProductJob('sync-uuid', '00000000-0000-0000-0000-000000070304', ['gundamhangar']);
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
        app(\App\Services\Products\GundamHangar\GundamHangarContentSyncService::class),
        app(\App\Services\Products\Bandai\BandaiContentSyncService::class),
        app(\App\Services\PriceResearch\PriceResearchService::class),
        app(\App\Services\Jobs\JobBatchItemService::class),
    );

    expect(ProductExternalAsset::query()->where('product_id', $p->id)->where('source', 'gundamhangar')->count())->toBe(2);
    $content = \App\Models\ProductExternalContent::query()
        ->where('product_id', '=', $p->id)
        ->where('source', '=', 'gundamhangar')
        ->first();
    expect($content)->not->toBeNull();
    expect((string) ($content?->description_html ?? ''))->toContain('From product endpoint');
});

it('accepts gundamhangar image urls when content-type is generic', function (): void {
    Storage::fake('local');

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070305',
        'sku' => 'SKU-GH-OCTET',
        'description' => 'MG Generic Header Product',
        'vendor' => 'V',
    ]);

    Http::fake(function (\Illuminate\Http\Client\Request $req) {
        $url = $req->url();
        if (str_contains($url, 'server.gundamhangar.com/api/products?')) {
            return Http::response([
                'data' => [[
                    'title' => 'MG Generic Header Product',
                    'slug' => 'mg-generic-header-product',
                    'description' => '<p>From product endpoint</p>',
                    'featured_image' => 'https://s3.amazonaws.com/gundamhangar.com/product-other-hobby/mg-generic-header-product/0.jpg',
                    'image_number' => 2,
                    'attributes' => [],
                ]],
            ], 200, ['Content-Type' => 'application/json']);
        }
        if (str_contains($url, 'server.gundamhangar.com/api/product-similar/mg-generic-header-product')) {
            return Http::response(['data' => []], 200, ['Content-Type' => 'application/json']);
        }
        if (str_contains($url, 'server.gundamhangar.com/api/product/mg-generic-header-product')) {
            return Http::response([
                'data' => [[
                    'title' => 'MG Generic Header Product',
                    'slug' => 'mg-generic-header-product',
                    'description' => '<p>From product endpoint</p>',
                    'attributes' => [],
                ]],
                'images' => [
                    'https://s3.amazonaws.com/gundamhangar.com/product-other-hobby/mg-generic-header-product/1.jpg?sig=abc',
                    'https://s3.amazonaws.com/gundamhangar.com/product-other-hobby/mg-generic-header-product/2.jpg?sig=def',
                ],
            ], 200, ['Content-Type' => 'application/json']);
        }
        if (str_contains($url, '/mg-generic-header-product/1.jpg')) {
            return Http::response('img1', 200, ['Content-Type' => 'application/octet-stream']);
        }
        if (str_contains($url, '/mg-generic-header-product/2.jpg')) {
            return Http::response('img2', 200, ['Content-Type' => 'application/octet-stream']);
        }

        return Http::response('not found', 404);
    });

    $batchId = 'c0ae0689-dd2d-4812-aa44-73b294d283d0';
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

    $job = new RecrawlSelectedProductJob('sync-uuid', '00000000-0000-0000-0000-000000070305', ['gundamhangar']);
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
        app(\App\Services\Products\GundamHangar\GundamHangarContentSyncService::class),
        app(\App\Services\Products\Bandai\BandaiContentSyncService::class),
        app(\App\Services\PriceResearch\PriceResearchService::class),
        app(\App\Services\Jobs\JobBatchItemService::class),
    );

    expect(ProductExternalAsset::query()->where('product_id', $p->id)->where('source', 'gundamhangar')->count())->toBe(2);
});
