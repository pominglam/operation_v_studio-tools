<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalAsset;
use App\Models\ProductExternalContent;
use App\Models\ProductSellingPrice;
use App\Services\Shopify\CloudflaredTunnel;
use Illuminate\Support\Facades\Storage;

it('prepares shopify content export and returns download_url + skipped lists', function (): void {
    Storage::fake('local');

    app()->instance(CloudflaredTunnel::class, new class implements CloudflaredTunnel {
        public function status(): array
        {
            return [
                'running' => true,
                'tunnel_url' => 'https://abc.trycloudflare.com',
                'container_id' => 'cid',
                'error' => null,
            ];
        }

        public function start(): array
        {
            return ['ok' => true, 'tunnel_url' => 'https://abc.trycloudflare.com', 'error' => null];
        }

        public function stop(): array
        {
            return ['ok' => true, 'error' => null];
        }
    });

    $p1 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090040',
        'sku' => 'SKU-EXP-1',
        'barcode' => '123',
        'description' => 'Export Product 1',
        'handle' => null,
        'type' => 'MG',
        'vendor' => null,
        'published_on_shopify' => true,
        'price' => null,
        'order_qty' => null,
        'filled_qty' => 5,
        'available_qty' => 2,
        'extended' => null,
        'preferred_description_source' => 'plamod',
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p1->id,
        'product_uuid' => $p1->uuid,
        'selling_price' => '55.99',
    ]);

    ProductExternalContent::query()->create([
        'product_id' => $p1->id,
        'source' => 'hlj',
        'title' => 'HLJ',
        'description_html' => '<p>HLJ desc</p>',
        'attributes_json' => null,
        'source_url' => 'https://www.hlj.com/x',
    ]);

    ProductExternalContent::query()->create([
        'product_id' => $p1->id,
        'source' => 'plamod',
        'title' => 'Plamod',
        'description_html' => "<p>This is a posable, high-grade kit.</p>\n<br>\n<p>Second paragraph.Â </p>",
        'attributes_json' => null,
        'source_url' => 'https://plamod.com/retailer/products/SKU-EXP-1',
    ]);

    Storage::disk('local')->put('plamod/extracted/x/1.png', 'img1');
    Storage::disk('local')->put('hlj/images/x/2.png', 'img2');
    Storage::disk('local')->put('gundamplanet/images/x/3.png', 'img3');

    ProductExternalAsset::query()->create([
        'product_id' => $p1->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => 'plamod/extracted/x/1.png',
        'filename' => '1.png',
        'mime_type' => 'image/png',
        'size_bytes' => 3,
        'checksum_sha256' => null,
        'sort_order' => 2,
        'shopify_enabled' => true,
    ]);
    ProductExternalAsset::query()->create([
        'product_id' => $p1->id,
        'source' => 'hlj',
        'kind' => 'image',
        'storage_path' => 'hlj/images/x/2.png',
        'filename' => '2.png',
        'mime_type' => 'image/png',
        'size_bytes' => 4,
        'checksum_sha256' => null,
        'sort_order' => 1,
        'shopify_enabled' => true,
    ]);
    ProductExternalAsset::query()->create([
        'product_id' => $p1->id,
        'source' => 'gundamplanet',
        'kind' => 'image',
        'storage_path' => 'gundamplanet/images/x/3.png',
        'filename' => '3.png',
        'mime_type' => 'image/png',
        'size_bytes' => 5,
        'checksum_sha256' => null,
        'sort_order' => 3,
        'shopify_enabled' => false,
    ]);

    // Product with duplicate existing handle (should be skipped)
    $p2 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090041',
        'sku' => 'SKU-EXP-2',
        'barcode' => null,
        'description' => 'Export Product 2',
        'handle' => 'export-product-1',
        'type' => null,
        'vendor' => null,
        'published_on_shopify' => false,
        'price' => null,
        'order_qty' => null,
        'filled_qty' => 0,
        'available_qty' => null,
        'extended' => null,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $p2->id,
        'product_uuid' => $p2->uuid,
        'selling_price' => '10.00',
    ]);

    $res = $this->postJson('/api/v1/products/exports/shopify-content/prepare');
    $res->assertOk();

    $res->assertJsonStructure([
        'export_id',
        'download_url',
        'exported_products',
        'exported_rows',
        'skipped_missing_handle',
        'skipped_duplicate_handle',
        'tunnel',
    ]);

    /** @var string $exportId */
    $exportId = $res->json('export_id');
    expect($exportId)->not->toBe('');

    expect((string) $res->json('download_url'))->toBe("/api/v1/products/exports/shopify-content/download/{$exportId}");

    $download = $this->get("/api/v1/products/exports/shopify-content/download/{$exportId}");
    $download->assertOk();

    $csv = (string) $download->streamedContent();
    expect((string) $download->headers->get('content-disposition'))->toContain('shopify-products-with-content-');
    expect($csv)->toContain('https://abc.trycloudflare.com/shopify-images/');
    expect($csv)->not->toContain('?expires=');
    expect($csv)->toMatch('/https:\\/\\/abc\\.trycloudflare\\.com\\/shopify-images\\/\\d+\\/\\d+\\/[a-f0-9]{64}\\//');

    // Respects preferred description source when available and normalizes HTML for Shopify.
    expect($csv)->toContain('This is a posable, high-grade kit.');
    expect($csv)->toContain('Second paragraph.');
    expect($csv)->not->toContain('<br');
    expect($csv)->not->toContain('Â');
    expect($csv)->not->toContain('HLJ desc');

    // Respects chosen image ordering (sort_order) and skips disabled images.
    // sort_order: HLJ (1) then Plamod (2); GundamPlanet is disabled.
    $pos2 = strpos($csv, '/2.png,1,');
    $pos1 = strpos($csv, '/1.png,2,');
    expect($pos2)->not->toBeFalse();
    expect($pos1)->not->toBeFalse();
    expect((int) $pos2)->toBeLessThan((int) $pos1);
});

it('prepares shopify content export for selected product IDs only', function (): void {
    Storage::fake('local');

    app()->instance(CloudflaredTunnel::class, new class implements CloudflaredTunnel {
        public function status(): array
        {
            return [
                'running' => true,
                'tunnel_url' => 'https://abc.trycloudflare.com',
                'container_id' => 'cid',
                'error' => null,
            ];
        }

        public function start(): array
        {
            return ['ok' => true, 'tunnel_url' => 'https://abc.trycloudflare.com', 'error' => null];
        }

        public function stop(): array
        {
            return ['ok' => true, 'error' => null];
        }
    });

    $p1 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090050',
        'sku' => 'SKU-SEL-1',
        'barcode' => '123',
        'description' => 'Selected 1',
        'handle' => 'selected-1',
        'type' => 'MG',
        'published_on_shopify' => true,
        'filled_qty' => 1,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $p1->id,
        'product_uuid' => $p1->uuid,
        'selling_price' => '55.99',
    ]);
    ProductExternalContent::query()->create([
        'product_id' => $p1->id,
        'source' => 'hlj',
        'title' => 'HLJ',
        'description_html' => '<p>desc</p>',
        'attributes_json' => null,
        'source_url' => 'https://www.hlj.com/x',
    ]);

    $p2 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090051',
        'sku' => 'SKU-SEL-2',
        'barcode' => '999',
        'description' => 'Selected 2',
        'handle' => 'selected-2',
        'type' => 'MG',
        'published_on_shopify' => false,
        'filled_qty' => 1,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $p2->id,
        'product_uuid' => $p2->uuid,
        'selling_price' => '10.00',
    ]);

    $res = $this->postJson('/api/v1/products/exports/shopify-content/prepare', [
        'ids' => [$p1->uuid],
    ]);
    $res->assertOk();

    /** @var string $exportId */
    $exportId = $res->json('export_id');
    $download = $this->get("/api/v1/products/exports/shopify-content/download/{$exportId}");
    $download->assertOk();

    $csv = (string) $download->streamedContent();
    expect($csv)->toContain('SKU-SEL-1')->not->toContain('SKU-SEL-2');
});


