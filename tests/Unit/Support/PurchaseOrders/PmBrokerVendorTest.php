<?php

declare(strict_types=1);

use App\Support\PurchaseOrders\PmBrokerVendor;

it('treats Other/multi as a PM broker vendor for HKD invoice import', function (): void {
    expect(PmBrokerVendor::isPmBrokerVendor('Other/multi'))->toBeTrue();
    expect(PmBrokerVendor::isPmBrokerVendor('other/multi'))->toBeTrue();
    expect(PmBrokerVendor::isOtherMulti('Other/multi'))->toBeTrue();
    expect(PmBrokerVendor::isPmBrokerVendor('PM'))->toBeFalse();
    expect(PmBrokerVendor::isPmBrokerVendor('SNAA'))->toBeFalse();
});

it('leaves product vendor null for Other/multi imports and assigns single-vendor broker names otherwise', function (): void {
    expect(PmBrokerVendor::productVendorForNewImportProduct('Other/multi'))->toBeNull();
    expect(PmBrokerVendor::productVendorForNewImportProduct('Stedi'))->toBe('Stedi');
    expect(PmBrokerVendor::productVendorForNewImportProduct('Dspiae'))->toBe('Dspiae');
});
