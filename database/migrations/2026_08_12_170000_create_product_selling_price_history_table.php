<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_selling_price_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete()
                ->restrictOnUpdate();
            $table->uuid('product_uuid')->index();
            $table->decimal('previous_price', 12, 2)->nullable();
            $table->decimal('new_price', 12, 2)->nullable();
            $table->string('currency', 3)->default('CAD');
            $table->string('source', 32);
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->constrained('purchase_orders')
                ->nullOnDelete()
                ->restrictOnUpdate();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['purchase_order_id', 'created_at'], 'idx_psph_po_created');
            $table->index(['product_id', 'created_at'], 'idx_psph_product_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_selling_price_history');
    }
};
