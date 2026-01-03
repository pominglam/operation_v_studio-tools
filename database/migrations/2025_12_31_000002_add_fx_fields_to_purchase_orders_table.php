<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->char('vendor_currency_code', 3)->default('CAD')->after('vendor');
            $table->decimal('vendor_product_total', 12, 2)->nullable()->after('product_total');
            $table->decimal('fx_rate_to_cad', 12, 6)->nullable()->after('vendor_product_total');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['vendor_currency_code', 'vendor_product_total', 'fx_rate_to_cad']);
        });
    }
};

