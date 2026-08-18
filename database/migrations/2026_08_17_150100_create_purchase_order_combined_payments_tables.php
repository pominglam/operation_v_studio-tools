<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_combined_payments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique('unique_po_combined_payments_uuid');
            $table->char('vendor_currency_code', 3);
            $table->decimal('vendor_total', 14, 2);
            $table->decimal('total_paid_cad', 14, 2);
            $table->decimal('fx_rate_to_cad', 12, 6);
            $table->boolean('includes_shipping')->default(false);
            $table->timestamps();
        });

        Schema::create('purchase_order_combined_payment_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('purchase_order_combined_payment_id');
            $table->foreignId('purchase_order_id');
            $table->decimal('vendor_product_total', 12, 2);
            $table->decimal('vendor_shipping_total', 12, 2)->nullable();
            $table->decimal('product_total_cad', 12, 2);
            $table->decimal('shipping_total_cad', 12, 2)->nullable();
            $table->timestamps();

            $table->foreign('purchase_order_combined_payment_id', 'fk_po_combined_payment_lines_payment')
                ->references('id')
                ->on('purchase_order_combined_payments')
                ->restrictOnDelete()
                ->restrictOnUpdate();
            $table->foreign('purchase_order_id', 'fk_po_combined_payment_lines_po')
                ->references('id')
                ->on('purchase_orders')
                ->restrictOnDelete()
                ->restrictOnUpdate();
            $table->unique('purchase_order_id', 'unique_po_combined_payment_lines_po');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_combined_payment_lines');
        Schema::dropIfExists('purchase_order_combined_payments');
    }
};
