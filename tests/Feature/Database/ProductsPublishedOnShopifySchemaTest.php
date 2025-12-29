<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('has published_on_shopify column on products table', function (): void {
    expect(Schema::hasColumn('products', 'published_on_shopify'))->toBeTrue();
});


