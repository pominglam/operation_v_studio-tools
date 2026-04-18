<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\Products\Hlj\HljContentSync;
use App\Services\Products\Http\PlamodScraper;
use App\Services\Products\PlamodAssetSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('syncs Plamod ZIP into extracted assets and persists content + assets', function (): void {
    Storage::disk('local')->put('plamod/.db_backup_created.json', '{"created_at":"2025-12-24T00:00:00Z"}');

    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000050100',
        'sku' => '5063838',
        'description' => 'Plamod product',
        'vendor' => 'Plamod',
    ]);

    $zipStoragePath = 'plamod/raw_zips/5063838/test.zip';
    $zipAbs = Storage::disk('local')->path($zipStoragePath);
    @mkdir(dirname($zipAbs), 0777, true);

    $zip = new ZipArchive;
    expect($zip->open($zipAbs, ZipArchive::CREATE))->toBeTrue();
    $zip->addFromString('images/a.txt', 'hello');
    $zip->addFromString('../evil.txt', 'nope');
    $zip->close();

    $fake = Mockery::mock(PlamodScraper::class);
    $fake->shouldReceive('downloadZip')->once()->andReturn([
        'ok' => true,
        'zip_storage_path' => $zipStoragePath,
        'metadata' => [
            'title' => 'Plamod Title',
            'description_html' => '<p>Desc</p>',
            'attributes' => ['Brand' => 'Stedi'],
        ],
    ]);
    app()->instance(PlamodScraper::class, $fake);

    $service = app(PlamodAssetSyncService::class);
    $result = $service->syncByProductUuid('00000000-0000-0000-0000-000000050100');

    expect($result->assets)->not->toBeEmpty();

    $this->assertDatabaseHas('product_external_contents', [
        'source' => 'plamod',
        'title' => 'Plamod Title',
    ]);

    $this->assertDatabaseHas('product_external_assets', [
        'source' => 'plamod',
        'kind' => 'zip',
        'storage_path' => $zipStoragePath,
    ]);

    expect(DB::table('product_external_assets')
        ->where('source', 'plamod')
        ->where('storage_path', 'like', 'plamod/extracted/5063838/%')
        ->exists())->toBeTrue();

    $files = Storage::disk('local')->allFiles('plamod/extracted/5063838');
    expect($files)->toBeArray();
    expect(collect($files)->contains(fn (string $p): bool => str_contains($p, '/images/a.txt')))->toBeTrue();

    // Zip slip entry must not be written.
    expect(collect($files)->contains(fn (string $p): bool => str_contains($p, 'evil.txt')))->toBeFalse();
});

it('does not call Plamod scraper for non-Plamod vendor products (best-effort HLJ only)', function (): void {
    Storage::disk('local')->put('plamod/.db_backup_created.json', '{"created_at":"2025-12-24T00:00:00Z"}');

    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000050200',
        'sku' => 'MS-E400',
        'description' => 'Non Plamod product',
        'vendor' => 'MSMN',
    ]);

    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper->shouldNotReceive('downloadZip');
    app()->instance(PlamodScraper::class, $scraper);

    $hlj = Mockery::mock(HljContentSync::class);
    $hlj->shouldReceive('syncForProduct')->once();
    app()->instance(HljContentSync::class, $hlj);

    $service = app(PlamodAssetSyncService::class);
    $result = $service->syncByProductUuid('00000000-0000-0000-0000-000000050200');

    expect($result->assets)->toBeArray();
    expect($result->assets)->toHaveCount(0);
});

it('attempts Plamod assets for non-Plamod vendor when forced (manual product info sync)', function (): void {
    Storage::disk('local')->put('plamod/.db_backup_created.json', '{"created_at":"2025-12-24T00:00:00Z"}');

    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000050201',
        'sku' => '5058815',
        'description' => 'Non Plamod product but exists on Plamod',
        'vendor' => 'MSMN',
    ]);

    $zipStoragePath = 'plamod/raw_zips/5058815/test.zip';
    $zipAbs = Storage::disk('local')->path($zipStoragePath);
    @mkdir(dirname($zipAbs), 0777, true);

    $zip = new ZipArchive;
    expect($zip->open($zipAbs, ZipArchive::CREATE))->toBeTrue();
    $zip->addFromString('images/a.txt', 'hello');
    $zip->close();

    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper->shouldReceive('downloadZip')->once()->andReturn([
        'ok' => true,
        'zip_storage_path' => $zipStoragePath,
        'metadata' => [
            'title' => 'Plamod Title',
            'description_html' => null,
            'attributes' => null,
        ],
    ]);
    app()->instance(PlamodScraper::class, $scraper);

    $hlj = Mockery::mock(HljContentSync::class);
    $hlj->shouldReceive('syncForProduct')->once();
    app()->instance(HljContentSync::class, $hlj);

    $service = app(PlamodAssetSyncService::class);
    $result = $service->syncByProductUuid('00000000-0000-0000-0000-000000050201', true);

    expect($result->assets)->not->toBeEmpty();
});

it('does not fail the job when Plamod ZIP is missing (best-effort HLJ only)', function (): void {
    Storage::disk('local')->put('plamod/.db_backup_created.json', '{"created_at":"2025-12-24T00:00:00Z"}');

    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000050300',
        'sku' => 'MS-E400',
        'description' => 'Looks like Plamod vendor but no ZIP exists',
        'vendor' => 'Plamod',
    ]);

    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper->shouldReceive('downloadZip')->once()->andReturn([
        'ok' => false,
        'error_message' => 'Could not find "Download ZIP" button/link on Plamod PDP',
        'debug' => [
            'current_url' => 'https://plamod.com/retailer/products/MS-E400',
        ],
    ]);
    app()->instance(PlamodScraper::class, $scraper);

    $hlj = Mockery::mock(\App\Services\Products\Hlj\HljContentSync::class);
    $hlj->shouldReceive('syncForProduct')->once();
    app()->instance(\App\Services\Products\Hlj\HljContentSync::class, $hlj);

    $service = app(PlamodAssetSyncService::class);
    $result = $service->syncByProductUuid('00000000-0000-0000-0000-000000050300');

    expect($result->assets)->toHaveCount(0);
});
