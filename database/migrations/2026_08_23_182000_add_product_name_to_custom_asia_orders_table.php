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
            $table->string('product_name', 255)->nullable()->after('customer_contact_value');
            $table->index('product_name', 'idx_custom_asia_orders_product_name');
        });
    }

    public function down(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->dropIndex('idx_custom_asia_orders_product_name');
            $table->dropColumn('product_name');
        });
    }
};
