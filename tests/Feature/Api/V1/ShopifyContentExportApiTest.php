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

    Storage::disk('local')->put('plamod/extracted/x/1.png', 'img');
    ProductExternalAsset::query()->create([
        'product_id' => $p1->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => 'plamod/extracted/x/1.png',
        'filename' => '1.png',
        'mime_type' => 'image/png',
        'size_bytes' => 3,
        'checksum_sha256' => null,
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
});


