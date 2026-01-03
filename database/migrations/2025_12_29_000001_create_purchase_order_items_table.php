<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->onDelete('restrict')
                ->onUpdate('restrict');
            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('restrict')
                ->onUpdate('restrict');

            $table->string('sku', 64);
            $table->string('vendor', 128);

            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->unsignedInteger('qty_ordered')->nullable();
            $table->unsignedInteger('qty_shipped')->nullable();
            $table->unsignedInteger('qty_received')->nullable();

            $table->timestamps();

            $table->index(['purchase_order_id', 'product_id'], 'idx_po_items_po_product');
            $table->index(['vendor', 'sku'], 'idx_po_items_vendor_sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};


