<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_preorders', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique('unique_store_preorders_uuid');
            $table->unsignedBigInteger('product_id');
            $table->string('plamod_sku', 64)->unique('unique_store_preorders_plamod_sku');
            $table->string('status', 16);
            $table->decimal('deposit_percent', 5, 2);
            $table->unsignedInteger('cap_qty')->nullable();
            $table->decimal('selling_price_cad', 12, 2)->nullable();
            $table->decimal('po_cost_cad', 12, 2)->nullable();
            $table->date('window_ends_on')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('product_id', 'fk_store_preorders_product_id')
                ->references('id')
                ->on('products')
                ->restrictOnDelete();
            $table->index(['status', 'window_ends_on'], 'idx_store_preorders_status_window');
            $table->index('product_id', 'idx_store_preorders_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_preorders');
    }
};
