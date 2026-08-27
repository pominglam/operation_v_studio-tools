<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->decimal('price_multiplier', 4, 2)->nullable()->after('receive_delay_days');
            $table->decimal('customer_price_cad', 10, 2)->nullable()->after('price_multiplier');
            $table->decimal('deposit_amount_cad', 10, 2)->nullable()->after('customer_price_cad');
            $table->index('customer_price_cad', 'idx_custom_asia_orders_customer_price_cad');
        });
    }

    public function down(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->dropIndex('idx_custom_asia_orders_customer_price_cad');
            $table->dropColumn(['price_multiplier', 'customer_price_cad', 'deposit_amount_cad']);
        });
    }
};
