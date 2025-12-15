<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_selling_prices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->unique();
            $table->uuid('product_uuid')->index();
            $table->decimal('selling_price', 12, 2)->nullable();
            $table->string('currency', 3)->default('CAD');
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('product_uuid')->references('uuid')->on('products')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_selling_prices');
    }
};



