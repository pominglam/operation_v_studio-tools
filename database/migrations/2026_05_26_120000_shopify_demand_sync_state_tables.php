<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_sync_state', static function (Blueprint $table): void {
            $table->id();
            $table->string('sync_key', 64)->unique();
            $table->timestamp('last_success_at')->nullable()->index();
            $table->timestamp('high_water_updated_at')->nullable();
            $table->timestamp('last_run_started_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_order_line_items', static function (Blueprint $table): void {
            $table->id();
            $table->string('order_gid', 191)->index();
            $table->string('line_gid', 191)->unique();
            $table->string('sku')->nullable()->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->integer('quantity')->default(0);
            $table->date('sold_on')->nullable()->index();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });

        Schema::create('product_demand_daily_rollups', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->date('sold_on')->index();
            $table->unsignedInteger('shopify_sold')->default(0);
            $table->unsignedInteger('assumed_sold')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'sold_on'], 'product_demand_daily_rollups_product_sold_unique');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_demand_daily_rollups');
        Schema::dropIfExists('shopify_order_line_items');
        Schema::dropIfExists('shopify_sync_state');
    }
};
