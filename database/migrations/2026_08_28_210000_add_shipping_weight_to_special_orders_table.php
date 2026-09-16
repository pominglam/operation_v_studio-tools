<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('special_orders', function (Blueprint $table): void {
            $table->string('shipping_cost_input_mode', 16)->nullable()->after('shipping_cost_currency');
            $table->decimal('shipping_weight_kg', 10, 3)->nullable()->after('shipping_cost_input_mode');
            $table->string('actual_shipping_cost_input_mode', 16)->nullable()->after('actual_shipping_cost_currency');
            $table->decimal('actual_shipping_weight_kg', 10, 3)->nullable()->after('actual_shipping_cost_input_mode');
        });
    }

    public function down(): void
    {
        Schema::table('special_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'shipping_cost_input_mode',
                'shipping_weight_kg',
                'actual_shipping_cost_input_mode',
                'actual_shipping_weight_kg',
            ]);
        });
    }
};
