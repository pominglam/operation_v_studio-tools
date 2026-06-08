<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plamod_preorder_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 32);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('counts_json')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('plamod_preorders', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('barcode', 64)->nullable()->index();
            $table->string('product_name');
            $table->string('series', 255)->nullable();
            $table->date('release_date')->nullable();
            $table->string('manufacturer', 128)->nullable()->index();
            $table->string('category', 128)->nullable()->index();
            $table->decimal('price_stock', 12, 2)->nullable();
            $table->decimal('price_preorder', 12, 2)->nullable();
            $table->decimal('price_backorder', 12, 2)->nullable();
            $table->unsignedInteger('quantity_preorder')->nullable();
            $table->date('po_due_date')->nullable();
            $table->date('eta_date')->nullable();
            $table->text('source_image_url')->nullable();
            $table->string('image_storage_path', 512)->nullable();
            $table->string('image_download_status', 32)->default('pending');
            $table->timestamp('image_downloaded_at')->nullable();
            $table->timestamp('dropped_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plamod_preorders');
        Schema::dropIfExists('plamod_preorder_sync_logs');
    }
};
