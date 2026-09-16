<?php

declare(strict_types=1);

use App\Services\Storefront\ModelKitStorefrontIndexPurchaseState;

it('keeps an open store preorder buyable when ERP sellable is zero', function (): void {
    expect(ModelKitStorefrontIndexPurchaseState::from(['closed' => false, 'offer' => true], 0))->toBe([
        'available' => false,
        'preorderClosed' => false,
        'openPreorder' => true,
    ]);
});

it('does not treat zero ERP stock as preorder closed', function (): void {
    $state = ModelKitStorefrontIndexPurchaseState::from(['closed' => false, 'offer' => true], 0);

    expect($state['preorderClosed'])->toBeFalse()
        ->and($state['openPreorder'])->toBeTrue();
});

it('marks a closed store preorder closed even with zero stock', function (): void {
    expect(ModelKitStorefrontIndexPurchaseState::from(['closed' => true, 'offer' => true], 0))->toBe([
        'available' => false,
        'preorderClosed' => true,
        'openPreorder' => false,
    ]);
});

it('leaves regular in-stock kits available without preorder flags', function (): void {
    expect(ModelKitStorefrontIndexPurchaseState::from(['closed' => false, 'offer' => false], 2))->toBe([
        'available' => true,
        'preorderClosed' => false,
        'openPreorder' => false,
    ]);
});

it('leaves regular sold-out kits unavailable without a closed preorder badge', function (): void {
    expect(ModelKitStorefrontIndexPurchaseState::from(['closed' => false, 'offer' => false], 0))->toBe([
        'available' => false,
        'preorderClosed' => false,
        'openPreorder' => false,
    ]);
});
