<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_quotes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id');

            $table->string('site_key', 64);
            $table->string('site_name', 128);
            $table->string('status', 32); // found | not_found | error

            $table->string('currency', 3)->default('CAD');
            $table->decimal('price', 12, 2)->nullable();
            $table->text('product_url')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('fetched_at');

            $table->timestamps();

            $table->unique(['product_id', 'site_key'], 'unique_product_price_quotes_product_site');
            $table->index(['site_key', 'fetched_at'], 'idx_product_price_quotes_site_fetched');

            $table->foreign('product_id', 'fk_product_price_quotes_product_id')
                ->references('id')
                ->on('products')
                ->onDelete('restrict')
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_quotes');
    }
};
