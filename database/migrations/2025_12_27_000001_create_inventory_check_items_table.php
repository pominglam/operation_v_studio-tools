<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_check_items', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('inventory_check_id');
            $table->unsignedBigInteger('product_id')->nullable();

            $table->string('handle', 255)->nullable();
            $table->string('vendor', 128)->nullable();
            $table->string('sku', 64);
            $table->string('type', 128)->nullable();
            $table->string('product_name', 512)->nullable();
            $table->string('english_name', 512)->nullable();

            $table->unsignedInteger('available_amount')->nullable();
            $table->unsignedInteger('quantity_in_store')->nullable();
            $table->integer('difference')->nullable();
            $table->text('notes')->nullable();

            $table->string('match_status', 32)->default('unmatched');
            $table->text('match_error')->nullable();

            $table->boolean('applied')->default(false);
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index(['inventory_check_id'], 'idx_inventory_check_items_inventory_check_id');
            $table->index(['product_id'], 'idx_inventory_check_items_product_id');
            $table->index(['handle'], 'idx_inventory_check_items_handle');
            $table->index(['sku', 'vendor'], 'idx_inventory_check_items_sku_vendor');

            $table->foreign('inventory_check_id', 'fk_inventory_check_items_inventory_check_id')
                ->references('id')
                ->on('inventory_check')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('product_id', 'fk_inventory_check_items_product_id')
                ->references('id')
                ->on('products')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_check_items');
    }
};




