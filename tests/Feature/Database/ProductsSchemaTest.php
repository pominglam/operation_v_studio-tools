<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('has available_qty column on products table', function (): void {
    expect(Schema::hasColumn('products', 'available_qty'))->toBeTrue();
});


