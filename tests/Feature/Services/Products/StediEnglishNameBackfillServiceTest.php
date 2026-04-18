<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\Products\StediEnglishNameBackfillService;

it('updates Stedi product descriptions when incoming name differs (match by vendor + sku)', function (): void {
    $p1 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-0000000a0001',
        'sku' => 'MC-01',
        'description' => 'MC-01哑光大红（基础色）',
        'vendor' => 'Stedi',
    ]);
    $p2 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-0000000a0002',
        'sku' => 'MC-02',
        'description' => 'Already English',
        'vendor' => 'Stedi',
    ]);
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-0000000a0003',
        'sku' => 'MC-03',
        'description' => 'MC-03哑光黑色（基础色）',
        'vendor' => 'OtherVendor',
    ]);

    $csv = <<<'CSV'
名称,司特力型号,每盒入数,order qty,received qty,english name
MC-01哑光大红（基础色）,MC-01,瓶,10,10,Matte Red
MC-02哑光浓绯红（基础色）,MC-02,瓶,10,10,Matte Intense Scarlet
MC-03哑光黑色（基础色）,MC-03,瓶,10,10,Matte Black
CSV;

    $path = base_path('storage/framework/testing/stedi-english-names-test.csv');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $csv);

    /** @var StediEnglishNameBackfillService $service */
    $service = app(StediEnglishNameBackfillService::class);
    $res = $service->backfillFromShipmentCsv($path, true, 'Stedi');

    expect($res->rowsRead)->toBe(3);
    expect($res->updatedCount)->toBe(2);

    $p1->refresh();
    expect($p1->description)->toBe('Matte Red');
    $p2->refresh();
    expect($p2->description)->toBe('Matte Intense Scarlet');
});

it('can use the last non-empty column as the name source', function (): void {
    $p1 = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-0000000b0001',
        'sku' => 'MC-01',
        'description' => 'Matte Red',
        'vendor' => 'Stedi',
    ]);

    $csv = <<<'CSV'
名称,司特力型号,每盒入数,order qty,received qty,english name,,,,,
MC-01哑光大红（基础色）,MC-01,瓶,10,10,Matte Red,Stedi,MC-01,Matte Red,Stedi MC-01 Matte Red,Stedi MC-01 Matte Red
CSV;

    $path = base_path('storage/framework/testing/stedi-english-names-test-last-col.csv');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $csv);

    /** @var StediEnglishNameBackfillService $service */
    $service = app(StediEnglishNameBackfillService::class);
    $res = $service->backfillFromShipmentCsv($path, true, 'Stedi', 'last_non_empty');

    expect($res->rowsRead)->toBe(1);
    expect($res->updatedCount)->toBe(1);

    $p1->refresh();
    expect($p1->description)->toBe('Stedi MC-01 Matte Red');
});

it('throws when CSV path is missing', function (): void {
    /** @var StediEnglishNameBackfillService $service */
    $service = app(StediEnglishNameBackfillService::class);
    $service->backfillFromShipmentCsv('storage/framework/testing/does-not-exist.csv', false, 'Stedi');
})->throws(InvalidArgumentException::class);
