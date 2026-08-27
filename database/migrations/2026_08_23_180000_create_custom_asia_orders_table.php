<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_asia_orders', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique('unique_custom_asia_orders_uuid');

            $table->string('customer_contact_media', 8);
            $table->string('customer_contact_value', 255);

            $table->string('customer_visual_path', 512)->nullable();
            $table->string('customer_visual_mime', 128)->nullable();
            $table->string('customer_visual_filename', 255)->nullable();

            $table->string('product_visual_path', 512)->nullable();
            $table->string('product_visual_mime', 128)->nullable();
            $table->string('product_visual_filename', 255)->nullable();

            $table->decimal('product_cost_amount', 12, 2)->nullable();
            $table->string('product_cost_currency', 8)->nullable();

            $table->decimal('shipping_cost_amount', 12, 2)->nullable();
            $table->string('shipping_cost_currency', 8)->nullable();

            $table->decimal('landed_cost_cad', 12, 2)->nullable();
            $table->decimal('product_fx_rate_to_cad', 12, 6)->nullable();
            $table->decimal('shipping_fx_rate_to_cad', 12, 6)->nullable();
            $table->date('fx_rate_date')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['customer_contact_media', 'created_at'], 'idx_custom_asia_orders_media_created');
            $table->index(['landed_cost_cad', 'created_at'], 'idx_custom_asia_orders_landed_created');
            $table->index('created_at', 'idx_custom_asia_orders_created_at');
            $table->index('updated_at', 'idx_custom_asia_orders_updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_asia_orders');
    }
};
