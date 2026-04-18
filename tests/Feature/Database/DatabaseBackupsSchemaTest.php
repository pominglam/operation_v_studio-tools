<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('has database_backups table', function (): void {
    expect(Schema::hasTable('database_backups'))->toBeTrue();
});
