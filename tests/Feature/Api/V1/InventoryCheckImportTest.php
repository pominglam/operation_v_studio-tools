<?php

declare(strict_types=1);

use App\Models\InventoryCheck;
use App\Models\InventoryCheckItem;
use App\Models\Product;
use Illuminate\Http\UploadedFile;

it('imports an inventory check CSV and applies qty + stedi english name updates', function (): void {
    $stedi = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090001',
        'sku' => 'S-001',
        'barcode' => 'S001',
        'description' => '中文名稱',
        'handle' => 'stedi-handle-1',
        'type' => 'HG',
        'vendor' => 'Stedi',
        'available_qty' => 1,
    ]);

    $plamod = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090002',
        'sku' => 'P-001',
        'barcode' => 'P001',
        'description' => 'Plamod product',
        'handle' => null,
        'type' => 'MG',
        'vendor' => 'Plamod',
        'available_qty' => 2,
    ]);

    $csv = implode("\n", [
        'Handle,Vendor,SKU,Type,Product Name,English name,Available amount,Selling price,Quantity in store,Difference,Notes',
        'stedi-handle-1,Stedi,S-001,HG,Stedi Product,English Name,1,,7,6,Counted',
        ',Plamod,P-001,MG,Plamod Product,,2,,3,1,Ok',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('inventory_check.csv', $csv);

    $res = $this->post('/api/v1/products/import-inventory-check', [
        'file' => $file,
    ]);

    $res->assertOk();
    $data = $res->json();

    expect($data)->toHaveKey('inventory_check.uuid');
    expect($data['rows_parsed'])->toBe(2);
    expect($data['matched'])->toBe(2);
    expect($data['applied'])->toBe(2);
    expect($data['not_applied'])->toBe(0);
    expect($data['unmatched'])->toBe(0);
    expect($data['ambiguous'])->toBe(0);

    $stedi->refresh();
    $plamod->refresh();

    expect($stedi->available_qty)->toBe(7);
    expect($stedi->description)->toBe('English Name');
    expect($plamod->available_qty)->toBe(3);
    expect($plamod->description)->toBe('Plamod product');

    /** @var string $uuid */
    $uuid = $data['inventory_check']['uuid'];
    $check = InventoryCheck::query()->where('uuid', '=', $uuid)->first();
    expect($check)->not->toBeNull();

    $items = InventoryCheckItem::query()->where('inventory_check_id', '=', $check->id)->orderBy('id')->get();
    expect($items)->toHaveCount(2);

    expect($items[0]->match_status)->toBe('matched');
    expect($items[0]->applied)->toBeTrue();
    expect($items[0]->handle)->toBe('stedi-handle-1');
    expect($items[0]->sku)->toBe('S-001');
    expect($items[0]->vendor)->toBe('Stedi');
    expect($items[0]->english_name)->toBe('English Name');
    expect($items[0]->quantity_in_store)->toBe(7);
    expect($items[0]->difference)->toBe(6);
    expect($items[0]->notes)->toBe('Counted');

    expect($items[1]->match_status)->toBe('matched');
    expect($items[1]->applied)->toBeTrue();
    expect($items[1]->handle)->toBe('');
    expect($items[1]->sku)->toBe('P-001');
    expect($items[1]->vendor)->toBe('Plamod');
    expect($items[1]->quantity_in_store)->toBe(3);
    expect($items[1]->difference)->toBe(1);
    expect($items[1]->notes)->toBe('Ok');
});

it('does not apply updates when quantity in store is blank and flags the row', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090010',
        'sku' => 'Q-001',
        'barcode' => 'Q001',
        'description' => 'Qty blank',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'available_qty' => 2,
    ]);

    $csv = implode("\n", [
        'Handle,Vendor,SKU,Type,Product Name,English name,Available amount,Selling price,Quantity in store,Difference,Notes',
        ',Plamod,Q-001,HG,Qty blank,,2,,,' . '-2' . ',Missing qty',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('inventory_check.csv', $csv);

    $res = $this->post('/api/v1/products/import-inventory-check', [
        'file' => $file,
    ]);

    $res->assertOk();
    $data = $res->json();

    expect($data['rows_parsed'])->toBe(1);
    expect($data['matched'])->toBe(1);
    expect($data['applied'])->toBe(0);
    expect($data['not_applied'])->toBe(1);
    expect($data['not_applied_rows'])->toHaveCount(1);

    $p->refresh();
    expect($p->available_qty)->toBe(2);

    /** @var string $uuid */
    $uuid = $data['inventory_check']['uuid'];
    $check = InventoryCheck::query()->where('uuid', '=', $uuid)->first();
    expect($check)->not->toBeNull();

    $item = InventoryCheckItem::query()->where('inventory_check_id', '=', $check->id)->first();
    expect($item)->not->toBeNull();
    expect($item->match_status)->toBe('matched');
    expect($item->applied)->toBeFalse();
    expect($item->match_error)->toBe('Missing Quantity in store (available not updated).');
    expect($item->difference)->toBeNull();
});

it('can still apply Stedi English name updates even when quantity in store is blank, and flags the row', function (): void {
    $stedi = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090011',
        'sku' => 'S-BLANK-1',
        'barcode' => 'SB001',
        'description' => '中文名稱',
        'handle' => 'stedi-blank-qty',
        'vendor' => 'Stedi',
        'available_qty' => 2,
    ]);

    $csv = implode("\n", [
        'Handle,Vendor,SKU,Type,Product Name,English name,Available amount,Selling price,Quantity in store,Difference,Notes',
        'stedi-blank-qty,Stedi,S-BLANK-1,TOOLS,Test,English Name,2,,,' . '-2' . ',Missing qty',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('inventory_check.csv', $csv);

    $res = $this->post('/api/v1/products/import-inventory-check', [
        'file' => $file,
    ]);

    $res->assertOk();
    $data = $res->json();

    expect($data['rows_parsed'])->toBe(1);
    expect($data['matched'])->toBe(1);
    expect($data['applied'])->toBe(1);
    expect($data['not_applied'])->toBe(1);
    expect($data['not_applied_rows'])->toHaveCount(1);

    $stedi->refresh();
    expect($stedi->available_qty)->toBe(2);
    expect($stedi->description)->toBe('English Name');
});

it('returns 422 when required columns are missing', function (): void {
    $csv = implode("\n", [
        'Vendor,SKU',
        'Plamod,P-001',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('bad.csv', $csv);

    $res = $this->post('/api/v1/products/import-inventory-check', [
        'file' => $file,
    ]);

    $res->assertStatus(422);
    $res->assertJsonFragment([
        'message' => 'Missing required column: Quantity in store',
    ]);
});

it('marks a row as ambiguous when multiple products share the same handle', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090003',
        'sku' => 'DUP-1',
        'barcode' => 'DUP1',
        'description' => 'Dup 1',
        'handle' => 'dup-handle',
        'vendor' => 'Stedi',
        'available_qty' => 1,
    ]);

    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090004',
        'sku' => 'DUP-2',
        'barcode' => 'DUP2',
        'description' => 'Dup 2',
        'handle' => 'dup-handle',
        'vendor' => 'Stedi',
        'available_qty' => 2,
    ]);

    $csv = implode("\n", [
        'Handle,Vendor,SKU,Type,Product Name,English name,Available amount,Selling price,Quantity in store,Difference,Notes',
        'dup-handle,Stedi,DUP-1,,Dup,,1,,5,4,Test',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('inventory_check.csv', $csv);

    $res = $this->post('/api/v1/products/import-inventory-check', [
        'file' => $file,
    ]);

    $res->assertOk();
    $data = $res->json();

    expect($data['rows_parsed'])->toBe(1);
    expect($data['matched'])->toBe(0);
    expect($data['applied'])->toBe(0);
    expect($data['unmatched'])->toBe(0);
    expect($data['ambiguous'])->toBe(1);
});




