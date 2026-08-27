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
            $table->decimal('actual_product_cost_amount', 12, 2)->nullable()->after('receive_delay_days');
            $table->string('actual_product_cost_currency', 3)->nullable()->after('actual_product_cost_amount');
            $table->decimal('actual_shipping_cost_amount', 12, 2)->nullable()->after('actual_product_cost_currency');
            $table->string('actual_shipping_cost_currency', 3)->nullable()->after('actual_shipping_cost_amount');
            $table->decimal('actual_landed_cost_cad', 12, 2)->nullable()->after('actual_shipping_cost_currency');
            $table->decimal('actual_product_fx_rate_to_cad', 12, 6)->nullable()->after('actual_landed_cost_cad');
            $table->decimal('actual_shipping_fx_rate_to_cad', 12, 6)->nullable()->after('actual_product_fx_rate_to_cad');
            $table->date('actual_fx_rate_date')->nullable()->after('actual_shipping_fx_rate_to_cad');
            $table->unsignedSmallInteger('actual_receive_delay_amount')->nullable()->after('actual_fx_rate_date');
            $table->string('actual_receive_delay_unit', 16)->nullable()->after('actual_receive_delay_amount');
            $table->unsignedSmallInteger('actual_receive_delay_days')->nullable()->after('actual_receive_delay_unit');
            $table->date('actual_arrival_at')->nullable()->after('actual_receive_delay_days');
        });
    }

    public function down(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'actual_product_cost_amount',
                'actual_product_cost_currency',
                'actual_shipping_cost_amount',
                'actual_shipping_cost_currency',
                'actual_landed_cost_cad',
                'actual_product_fx_rate_to_cad',
                'actual_shipping_fx_rate_to_cad',
                'actual_fx_rate_date',
                'actual_receive_delay_amount',
                'actual_receive_delay_unit',
                'actual_receive_delay_days',
                'actual_arrival_at',
            ]);
        });
    }
};
