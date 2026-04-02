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

    app()->instance(CloudflaredTunnel::class, new class implements CloudflaredTunnel
    {
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

    // Uses available_qty as Variant Inventory Qty (Shopify CSV).
    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
    $rows = array_map('str_getcsv', $lines);
    $header = $rows[0] ?? null;
    expect($header)->not->toBeNull();
    /** @var array<int, string> $header */
    $idx = array_flip($header);
    $p1Row = collect($rows)->first(function (array $r): bool {
        return ($r[18] ?? '') === 'SKU-EXP-1';
    });
    expect($p1Row)->not->toBeNull();
    /** @var array<int, string> $p1Row */
    expect($p1Row[$idx['Variant Inventory Qty']] ?? null)->toBe('2');
    expect($p1Row[$idx['Variant Inventory Policy']] ?? null)->toBe('deny');
    expect($p1Row[$idx['Variant Requires Shipping']] ?? null)->toBe('TRUE');
    expect($p1Row[$idx['Variant Taxable']] ?? null)->toBe('TRUE');
    expect($p1Row[$idx['Gift Card']] ?? null)->toBe('FALSE');
});

it('skips image URLs when the underlying image file is missing/unreadable', function (): void {
    Storage::fake('local');

    app()->instance(CloudflaredTunnel::class, new class implements CloudflaredTunnel
    {
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

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090042',
        'sku' => 'SKU-EXP-MISS-IMG',
        'barcode' => '999',
        'description' => 'Export Product Missing Img',
        'handle' => 'export-product-missing-img',
        'type' => 'MG',
        'vendor' => null,
        'published_on_shopify' => true,
        'price' => null,
        'order_qty' => null,
        'filled_qty' => 0,
        'available_qty' => 1,
        'extended' => null,
        'preferred_description_source' => 'plamod',
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '1.00',
    ]);

    ProductExternalContent::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'title' => 'Plamod',
        'description_html' => '<p>Desc</p>',
        'attributes_json' => null,
        'source_url' => 'https://plamod.com/retailer/products/SKU-EXP-MISS-IMG',
    ]);

    $asset = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'newtype',
        'kind' => 'image',
        // Intentionally not present in Storage::fake('local')
        'storage_path' => 'private/newtype/images/5069371/missing.png',
        'filename' => 'missing.png',
        'mime_type' => 'image/png',
        'size_bytes' => 10,
        'checksum_sha256' => null,
        'sort_order' => 1,
        'shopify_enabled' => true,
    ]);

    $res = $this->postJson('/api/v1/products/exports/shopify-content/prepare', ['ids' => [$p->uuid]]);
    $res->assertOk();

    /** @var string $exportId */
    $exportId = $res->json('export_id');
    $download = $this->get("/api/v1/products/exports/shopify-content/download/{$exportId}");
    $download->assertOk();

    $csv = (string) $download->streamedContent();
    expect($csv)->not->toContain('/shopify-images/'.$asset->id.'/');
});

it('prepares shopify content export without inventory columns', function (): void {
    Storage::fake('local');

    app()->instance(CloudflaredTunnel::class, new class implements CloudflaredTunnel
    {
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

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090099',
        'sku' => 'SKU-EXP-NOINV-1',
        'barcode' => '777',
        'description' => 'Export Product No Inventory',
        'handle' => 'export-product-no-inventory',
        'type' => 'MG',
        'vendor' => null,
        'published_on_shopify' => true,
        'price' => null,
        'order_qty' => null,
        'filled_qty' => 0,
        'available_qty' => 999,
        'extended' => null,
        'preferred_description_source' => 'plamod',
    ]);

    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '12.34',
    ]);

    ProductExternalContent::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'title' => 'Plamod',
        'description_html' => '<p>Content desc</p>',
        'attributes_json' => null,
        'source_url' => 'https://plamod.com/retailer/products/SKU-EXP-NOINV-1',
    ]);

    Storage::disk('local')->put('plamod/extracted/noinv/1.png', 'img1');
    ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => 'plamod/extracted/noinv/1.png',
        'filename' => '1.png',
        'mime_type' => 'image/png',
        'size_bytes' => 3,
        'checksum_sha256' => null,
        'sort_order' => 1,
        'shopify_enabled' => true,
    ]);

    $res = $this->postJson('/api/v1/products/exports/shopify-content-no-inventory/prepare');
    $res->assertOk();

    /** @var string $exportId */
    $exportId = $res->json('export_id');
    expect($exportId)->not->toBe('');

    $download = $this->get("/api/v1/products/exports/shopify-content/download/{$exportId}");
    $download->assertOk();
    $csv = (string) $download->streamedContent();

    expect($csv)->toContain('Content desc');
    expect($csv)->toContain('https://abc.trycloudflare.com/shopify-images/');

    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
    $rows = array_map('str_getcsv', $lines);
    $header = $rows[0] ?? null;
    expect($header)->not->toBeNull();
    /** @var array<int, string> $header */
    expect($header)->not->toContain('Variant Inventory Tracker');
    expect($header)->not->toContain('Variant Inventory Qty');
    expect($header)->not->toContain('Variant Inventory Policy');
});

it('prepares shopify content export for selected product IDs only', function (): void {
    Storage::fake('local');

    app()->instance(CloudflaredTunnel::class, new class implements CloudflaredTunnel
    {
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

it('auto-repairs restrictive image directory permissions before export', function (): void {
    app()->instance(CloudflaredTunnel::class, new class implements CloudflaredTunnel
    {
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

    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090060',
        'sku' => 'SKU-PERM-REPAIR-1',
        'barcode' => '555',
        'description' => 'Permission Repair Product',
        'handle' => 'permission-repair-product',
        'type' => 'PG',
        'published_on_shopify' => true,
        'filled_qty' => 1,
        'available_qty' => 1,
    ]);
    ProductSellingPrice::query()->create([
        'product_id' => $p->id,
        'product_uuid' => $p->uuid,
        'selling_price' => '99.99',
    ]);

    $dir = storage_path('app/private/hlj/images/SKU-PERM-REPAIR-1');
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $abs = $dir.'/perm-repair-1.jpg';
    file_put_contents($abs, 'img-bytes');
    @chmod($dir, 0700);
    @chmod($abs, 0600);

    ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'hlj',
        'kind' => 'image',
        'storage_path' => 'hlj/images/SKU-PERM-REPAIR-1/perm-repair-1.jpg',
        'filename' => 'perm-repair-1.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => filesize($abs) ?: 0,
        'checksum_sha256' => null,
        'sort_order' => 1,
        'shopify_enabled' => true,
    ]);

    $res = $this->postJson('/api/v1/products/exports/shopify-content/prepare', ['ids' => [$p->uuid]]);
    $res->assertOk();

    $exportId = (string) $res->json('export_id');
    $download = $this->get("/api/v1/products/exports/shopify-content/download/{$exportId}");
    $download->assertOk();
    $csv = (string) $download->streamedContent();

    expect($csv)->toContain('/shopify-images/');
    expect((fileperms($dir) & 0777))->toBe(0755);
    expect((fileperms($abs) & 0777))->toBe(0644);
});
