<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;

it('includes stored shipment_method on purchase orders index', function (): void {
    $air = PurchaseOrder::query()->create([
        'vendor' => 'ShipVendor',
        'shipment_method' => 'air',
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
    expect($byId[(string) $sea->uuid]['shipment_method'])->toBe('sea');
    expect($byId[(string) $unset->uuid]['shipment_method'])->toBeNull();
});
