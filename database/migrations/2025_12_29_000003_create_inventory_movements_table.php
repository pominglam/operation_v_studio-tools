<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique('unique_inventory_movements_uuid');

            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('restrict')
                ->onUpdate('restrict');
            $table->foreignId('inventory_lot_id')
                ->constrained('inventory_lots')
                ->onDelete('restrict')
                ->onUpdate('restrict');

            // deduct | receive | adjust_in | adjust_out | underflow
            $table->string('kind', 32);
            $table->integer('qty_delta'); // negative for deductions

            $table->string('reference_type', 64)->nullable(); // inventory_check, purchase_order, manual
            $table->uuid('reference_uuid')->nullable();

            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['product_id', 'occurred_at'], 'idx_inventory_movements_product_occurred_at');
            $table->index(['reference_type', 'reference_uuid'], 'idx_inventory_movements_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};


