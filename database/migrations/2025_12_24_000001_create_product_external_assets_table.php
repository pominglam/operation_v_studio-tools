<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_external_assets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('source', 50);
            $table->string('kind', 30);
            $table->string('storage_path', 800);
            $table->string('filename', 255);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->timestamps();

            $table->foreign('product_id', 'fk_product_external_assets_product_id')
                ->references('id')
                ->on('products')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->index(['product_id', 'source'], 'idx_product_external_assets_product_source');
            $table->index(['source', 'kind'], 'idx_product_external_assets_source_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_external_assets');
    }
};
