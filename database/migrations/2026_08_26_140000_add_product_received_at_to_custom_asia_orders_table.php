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
            $table->timestamp('product_received_at')->nullable()->after('estimated_arrival_at');
            $table->index('product_received_at', 'idx_custom_asia_orders_product_received_at');
        });
    }

    public function down(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->dropIndex('idx_custom_asia_orders_product_received_at');
            $table->dropColumn('product_received_at');
        });
    }
};
