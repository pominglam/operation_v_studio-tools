<?php

declare(strict_types=1);

use App\Support\PurchaseOrders\PmInvoiceImportSkuResolver;

it('keeps Stedi catalog SKUs when the invoice SKU column is a real vendor code', function (): void {
    $resolver = new PmInvoiceImportSkuResolver;

    expect($resolver->resolveSku('PM', 'Stedi tool MS-001', 'MS-001', 2))->toBe('MS-001');
});

it('uses PM line refs when the customer column is numeric', function (): void {
    $resolver = new PmInvoiceImportSkuResolver;

    expect($resolver->resolveSku('34', '', 'A3', 6))->toBe('PM-34');
    expect($resolver->resolveProductName('34', '', 'A3'))->toBe('PM item 34 (A3)');
});

it('derives distinct SKUs from item names when the SKU column repeats size codes', function (): void {
    $resolver = new PmInvoiceImportSkuResolver;

    $mint = $resolver->resolveSku('Mr. Lam, Po Ming', 'Cutting mat - Mint green', 'A3', 5);
    $matcha = $resolver->resolveSku('35', 'Cutting mat - Matcha green', 'A3', 7);

    expect($mint)->toBe('cutting-mat-mint-green-a3');
    expect($matcha)->toBe('PM-35');
    expect($mint)->not->toBe($matcha);
});

it('prefers numeric PM line refs over long item names and caps sku length', function (): void {
    $resolver = new PmInvoiceImportSkuResolver;

    $longName = 'SNAA XH-01 - Hunt and Kill Hunting Falcon Original Colors '.
        '40x32x10cm / 1.4Kg';

    expect($resolver->resolveSku('56', $longName, '1/100', 14))->toBe('PM-56');
    expect(strlen($resolver->resolveSku('Mr. Lam', $longName, '1/100', 14)))->toBeLessThanOrEqual(64);
});

it('normalizes PM invoice rows so duplicate size codes do not collapse', function (): void {
    $resolver = new PmInvoiceImportSkuResolver;

    $rows = $resolver->normalizeRows([
        [
            'row' => 5,
            'sku' => 'A3',
            'pm_customer_ref' => 'Mr. Lam, Po Ming',
            'product_name' => 'Cutting mat - Mint green',
            'unit_cost' => '25.00',
            'qty_ordered' => 2,
            'qty_shipped' => null,
            'qty_received' => null,
            'barcode' => null,
        ],
        [
            'row' => 7,
            'sku' => 'A3',
            'pm_customer_ref' => '35',
            'product_name' => null,
            'unit_cost' => '25.00',
            'qty_ordered' => 8,
            'qty_shipped' => null,
            'qty_received' => null,
            'barcode' => null,
        ],
    ]);

    expect($rows)->toHaveCount(2);
    expect($rows[0]['sku'])->toBe('cutting-mat-mint-green-a3');
    expect($rows[1]['sku'])->toBe('PM-35');
});
