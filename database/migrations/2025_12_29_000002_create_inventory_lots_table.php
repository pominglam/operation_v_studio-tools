<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique('unique_inventory_lots_uuid');

            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('restrict')
                ->onUpdate('restrict');

            $table->foreignId('purchase_order_item_id')
                ->nullable()
                ->constrained('purchase_order_items')
                ->onDelete('restrict')
                ->onUpdate('restrict');

            // po | manual | opening_balance | negative_balance
            $table->string('source_type', 32);

            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->decimal('shipping_per_unit', 12, 6)->nullable();

            $table->unsignedInteger('qty_received')->nullable();
            $table->integer('qty_remaining'); // can go negative for underflow

            $table->dateTime('received_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'received_at', 'id'], 'idx_inventory_lots_product_received_at_id');
            $table->index(['product_id', 'qty_remaining'], 'idx_inventory_lots_product_qty_remaining');
            $table->index(['purchase_order_item_id'], 'idx_inventory_lots_po_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lots');
    }
};
