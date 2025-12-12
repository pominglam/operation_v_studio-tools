<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique('unique_products_uuid');

            $table->string('sku', 64)->unique('unique_products_sku');
            $table->string('barcode', 64)->nullable()->index('idx_products_barcode');
            $table->string('description', 512);
            $table->string('type', 128)->nullable();

            $table->decimal('price', 12, 2)->nullable();
            $table->unsignedInteger('order_qty')->nullable();
            $table->unsignedInteger('filled_qty')->nullable();
            $table->decimal('extended', 12, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};


