<?php

declare(strict_types=1);

use App\Support\SpecialOrders\SpecialOrderShippingCost;

it('keeps the existing amount when weight mode is selected without a kg value', function (): void {
    $resolved = SpecialOrderShippingCost::resolveQuote(
        SpecialOrderShippingCost::MODE_WEIGHT,
        null,
        '100.00',
        'CNY',
        '29.00',
    );

    expect($resolved['shipping_cost_input_mode'])->toBe(SpecialOrderShippingCost::MODE_WEIGHT);
    expect($resolved['shipping_weight_kg'])->toBeNull();
    expect($resolved['shipping_cost_amount'])->toBe('100.00');
    expect($resolved['shipping_cost_currency'])->toBe('CNY');
});

it('computes RMB from kg once a weight is entered', function (): void {
    $resolved = SpecialOrderShippingCost::resolveActual(
        SpecialOrderShippingCost::MODE_WEIGHT,
        '2.5',
        '100.00',
        'CNY',
        '29.00',
    );

    expect($resolved['actual_shipping_cost_input_mode'])->toBe(SpecialOrderShippingCost::MODE_WEIGHT);
    expect($resolved['actual_shipping_weight_kg'])->toBe('2.500');
    expect($resolved['actual_shipping_cost_amount'])->toBe('72.50');
});
