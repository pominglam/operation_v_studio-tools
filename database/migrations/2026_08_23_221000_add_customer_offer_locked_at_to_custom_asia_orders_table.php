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
            $table->timestamp('customer_offer_locked_at')->nullable()->after('deposit_percent');
        });

        DB::table('custom_asia_orders')
            ->whereNotNull('customer_price_cad')
            ->whereNotNull('deposit_percent')
            ->whereNull('customer_offer_locked_at')
            ->update([
                'customer_offer_locked_at' => DB::raw(
                    'COALESCE(deposit_received_at, merchandiser_ordered_at, updated_at, created_at)',
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->dropColumn('customer_offer_locked_at');
        });
    }
};
