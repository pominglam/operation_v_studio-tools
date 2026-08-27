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
            $table->decimal('deposit_percent', 5, 2)->nullable()->after('customer_price_cad');
        });

        DB::table('custom_asia_orders')
            ->whereNotNull('deposit_amount_cad')
            ->whereNotNull('customer_price_cad')
            ->where('customer_price_cad', '>', 0)
            ->update([
                'deposit_percent' => DB::raw('ROUND((deposit_amount_cad / customer_price_cad) * 100, 2)'),
            ]);

        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->dropColumn('deposit_amount_cad');
        });
    }

    public function down(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->decimal('deposit_amount_cad', 10, 2)->nullable()->after('customer_price_cad');
        });

        DB::table('custom_asia_orders')
            ->whereNotNull('deposit_percent')
            ->whereNotNull('customer_price_cad')
            ->update([
                'deposit_amount_cad' => DB::raw('ROUND((customer_price_cad * deposit_percent) / 100, 2)'),
            ]);

        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->dropColumn('deposit_percent');
        });
    }
};
