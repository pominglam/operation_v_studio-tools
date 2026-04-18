<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_external_contents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('source', 50);
            $table->string('title', 255)->nullable();
            $table->longText('description_html')->nullable();
            $table->json('attributes_json')->nullable();
            $table->timestamps();

            $table->foreign('product_id', 'fk_product_external_contents_product_id')
                ->references('id')
                ->on('products')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->unique(['product_id', 'source'], 'unique_product_external_contents_product_source');
            $table->index(['source', 'product_id'], 'idx_product_external_contents_source_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_external_contents');
    }
};
