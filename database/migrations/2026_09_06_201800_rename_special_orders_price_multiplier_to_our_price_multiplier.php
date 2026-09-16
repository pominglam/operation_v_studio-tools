<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasLegacy = Schema::hasColumn('special_orders', 'price_multiplier');
        $hasTarget = Schema::hasColumn('special_orders', 'our_price_multiplier');

        if ($hasLegacy && $hasTarget) {
            throw new \RuntimeException(
                'special_orders has both price_multiplier and our_price_multiplier; resolve before renaming.',
            );
        }

        if ($hasLegacy && ! $hasTarget) {
            DB::statement('ALTER TABLE special_orders CHANGE price_multiplier our_price_multiplier DECIMAL(4,2) NULL');
        }
    }

    public function down(): void
    {
        $hasLegacy = Schema::hasColumn('special_orders', 'price_multiplier');
        $hasTarget = Schema::hasColumn('special_orders', 'our_price_multiplier');

        if ($hasTarget && ! $hasLegacy) {
            DB::statement('ALTER TABLE special_orders CHANGE our_price_multiplier price_multiplier DECIMAL(4,2) NULL');
        }
    }
};
