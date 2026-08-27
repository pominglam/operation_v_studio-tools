<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->decimal('merchandiser_price_multiplier', 4, 2)->nullable()->after('receive_delay_days');
            $table->decimal('merchandiser_price_cad', 10, 2)->nullable()->after('merchandiser_price_multiplier');
        });

        DB::statement('ALTER TABLE custom_asia_orders CHANGE price_multiplier our_price_multiplier DECIMAL(4,2) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE custom_asia_orders CHANGE our_price_multiplier price_multiplier DECIMAL(4,2) NULL');

        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->dropColumn(['merchandiser_price_multiplier', 'merchandiser_price_cad']);
        });
    }
};
