<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('has job_batch_items table', function (): void {
    expect(Schema::hasTable('job_batch_items'))->toBeTrue();
    expect(Schema::hasColumn('job_batch_items', 'batch_id'))->toBeTrue();
    expect(Schema::hasColumn('job_batch_items', 'product_uuid'))->toBeTrue();
    expect(Schema::hasColumn('job_batch_items', 'status'))->toBeTrue();
    expect(Schema::hasColumn('job_batch_items', 'debug_log'))->toBeTrue();
});


