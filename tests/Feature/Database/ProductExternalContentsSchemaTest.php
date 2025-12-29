<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('has product_external_contents.source_url column', function (): void {
    expect(Schema::hasColumn('product_external_contents', 'source_url'))->toBeTrue();
});


