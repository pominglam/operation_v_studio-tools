<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;

it('includes stored shipment details on purchase orders index', function (): void {
    $air = PurchaseOrder::query()->create([
        'vendor' => 'ShipVendor',
        'shipment_method' => 'air',
        'shipment_tracking_numbers' => ['1Z999AA10123456784', 'RR123456789CN'],
    ]);
    $sea = PurchaseOrder::query()->create([
        'vendor' => 'ShipVendor',
        'shipment_method' => 'sea',
    ]);
    $unset = PurchaseOrder::query()->create([
        'vendor' => 'ShipVendor',
        'shipment_method' => null,
    ]);

    $res = $this->getJson('/api/v1/purchase-orders?per_page=100&vendors[]=ShipVendor');
    $res->assertOk();

    $byId = collect($res->json('data') ?? [])->keyBy('id');

    expect($byId[(string) $air->uuid]['shipment_method'])->toBe('air');
    expect($byId[(string) $air->uuid]['shipment_tracking_numbers'])->toBe([
        '1Z999AA10123456784',
        'RR123456789CN',
    ]);
    expect($byId[(string) $sea->uuid]['shipment_method'])->toBe('sea');
    expect($byId[(string) $unset->uuid]['shipment_method'])->toBeNull();
});
