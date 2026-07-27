<?php

declare(strict_types=1);

use App\Services\Shopify\Admin\Orders\ShopifyOrderStaffBucketClassifier;

it('classifies quick sale and online store sources', function (): void {
    $classifier = new ShopifyOrderStaffBucketClassifier;
    $staff = [
        '134032556113' => ['key' => 'alex_hui', 'label' => 'Alex Hui'],
    ];

    expect($classifier->classify('quick_sale', null, 'Quick Sale', $staff))->toBe('quick_sale')
        ->and($classifier->classify('web', null, 'Online Store', $staff))->toBe('online_store');
});

it('classifies pos orders by configured staff user id', function (): void {
    $classifier = new ShopifyOrderStaffBucketClassifier;
    $staff = [
        '134032556113' => ['key' => 'alex_hui', 'label' => 'Alex Hui'],
    ];

    expect($classifier->classify('pos', 134032556113, 'Main Store (Point of Sale)', $staff))->toBe('alex_hui')
        ->and($classifier->classify('pos', 999, 'Main Store (Point of Sale)', $staff))->toBe('pos_other');
});
