<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plamod_instock_sync_logs', function (Blueprint $table): void {
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

        Schema::create('plamod_instock_items', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('barcode', 64)->nullable()->index();
            $table->string('product_name');
            $table->string('series', 255)->nullable();
            $table->date('release_date')->nullable()->index();
            $table->string('release_date_label', 32)->nullable();
            $table->string('manufacturer', 128)->nullable();
            $table->string('category', 128)->nullable();
            $table->decimal('price_stock', 12, 2)->nullable();
            $table->text('source_image_url')->nullable();
            $table->string('plamod_pdp_url', 512)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedBigInteger('sync_log_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('plamod_restock_sku_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('status', 16);
            $table->unsignedInteger('order_qty')->nullable();
            $table->unsignedInteger('planned_maintain_qty')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plamod_restock_sku_decisions');
        Schema::dropIfExists('plamod_instock_items');
        Schema::dropIfExists('plamod_instock_sync_logs');
    }
};
