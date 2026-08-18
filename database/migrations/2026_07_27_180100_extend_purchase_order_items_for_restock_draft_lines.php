<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropForeign(['product_id']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('restrict')
                ->onUpdate('restrict');

            $table->string('product_name', 512)->nullable()->after('sku');
            $table->string('barcode', 64)->nullable()->after('product_name');
            $table->unsignedInteger('planned_maintain_qty')->nullable()->after('qty_received');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropForeign(['product_id']);
            $table->dropColumn(['product_name', 'barcode', 'planned_maintain_qty']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('restrict')
                ->onUpdate('restrict');
        });
    }
};
