<?php

declare(strict_types=1);

use App\Support\Plamod\PlamodRestockTotalsCalculator;

it('sums existing and included new restock lines into totals', function (): void {
    $totals = PlamodRestockTotalsCalculator::compute(
        [
            [
                'sku' => 'EXISTING-1',
                'proposed_qty' => 2,
                'new_landed_cost' => [
                    'product' => '10.00',
                    'shipping' => '0.50',
                    'landed' => '10.50',
                ],
            ],
            [
                'sku' => 'EXISTING-1',
                'proposed_qty' => 1,
                'new_landed_cost' => [
                    'product' => '10.00',
                    'shipping' => '0.50',
                    'landed' => '10.50',
                ],
            ],
        ],
        [
            [
                'sku' => 'NEW-1',
                'status' => 'included',
                'order_qty' => 1,
                'new_landed_cost' => [
                    'product' => '20.00',
                    'shipping' => '1.00',
                    'landed' => '21.00',
                ],
            ],
            [
                'sku' => 'IGNORED-1',
                'status' => 'undecided',
                'order_qty' => 5,
                'new_landed_cost' => [
                    'product' => '99.00',
                    'shipping' => '4.95',
                    'landed' => '103.95',
                ],
            ],
        ],
        5.0,
    );

    expect($totals)->toMatchArray([
        'unique_products' => 2,
        'units' => 4,
        'product' => '50.00',
        'shipping' => '2.50',
        'landed' => '52.50',
        'lines_with_missing_price' => 0,
        'existing' => [
            'unique_products' => 1,
            'units' => 3,
            'product' => '30.00',
            'shipping' => '1.50',
            'landed' => '31.50',
            'lines_with_missing_price' => 0,
        ],
        'new_products' => [
            'unique_products' => 1,
            'units' => 1,
            'product' => '20.00',
            'shipping' => '1.00',
            'landed' => '21.00',
            'lines_with_missing_price' => 0,
        ],
    ]);
});
