<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_sync_logs', static function (Blueprint $table): void {
            $table->id();
            $table->string('sync_key', 64)->index();
            $table->string('status', 32)->default('running')->index();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable()->index();
            $table->unsignedInteger('records_fetched')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error_summary')->nullable();
            $table->json('checkpoint_json')->nullable();
            $table->json('counts_json')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_webhook_logs', static function (Blueprint $table): void {
            $table->id();
            $table->string('shop_domain')->index();
            $table->string('topic', 128)->index();
            $table->string('shopify_webhook_id', 128)->nullable()->index();
            $table->string('request_id', 128)->nullable()->index();
            $table->boolean('verification_ok')->default(false)->index();
            $table->string('processing_status', 32)->default('received')->index();
            $table->text('verification_error')->nullable();
            $table->longText('payload_json')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_locations', static function (Blueprint $table): void {
            $table->id();
            $table->string('gid', 191)->unique();
            $table->string('legacy_numeric_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->boolean('is_active')->nullable();
            $table->boolean('fulfills_online_orders')->nullable();
            $table->timestamp('graphql_updated_at')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_products', static function (Blueprint $table): void {
            $table->id();
            $table->string('gid', 191)->unique();
            $table->string('legacy_numeric_id')->nullable()->index();
            $table->string('handle')->nullable()->index();
            $table->string('title')->nullable();
            $table->string('status', 64)->nullable()->index();
            $table->string('vendor')->nullable()->index();
            $table->timestamp('graphql_updated_at')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_product_variants', static function (Blueprint $table): void {
            $table->id();
            $table->string('gid', 191)->unique();
            $table->string('product_gid')->index();
            $table->string('legacy_numeric_id')->nullable()->index();
            $table->string('sku')->nullable()->index();
            $table->string('barcode')->nullable()->index();
            $table->integer('inventory_quantity')->nullable();
            $table->string('inventory_item_gid')->nullable()->index();
            $table->timestamp('graphql_updated_at')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_inventory_items', static function (Blueprint $table): void {
            $table->id();
            $table->string('gid', 191)->unique();
            $table->string('legacy_numeric_id')->nullable()->index();
            $table->string('sku')->nullable()->index();
            $table->boolean('tracked')->nullable();
            $table->boolean('requires_shipping')->nullable();
            $table->timestamp('graphql_updated_at')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_inventory_levels', static function (Blueprint $table): void {
            $table->id();
            $table->string('inventory_item_gid')->index();
            $table->string('location_gid')->index();
            $table->integer('quantity_available')->nullable();
            $table->string('level_gid')->nullable()->index();
            $table->timestamp('graphql_updated_at')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
            $table->unique(['inventory_item_gid', 'location_gid'], 'shopify_inventory_levels_item_loc_unique');
        });

        Schema::create('shopify_orders', static function (Blueprint $table): void {
            $table->id();
            $table->string('gid', 191)->unique();
            $table->string('legacy_numeric_id')->nullable()->index();
            $table->string('name')->nullable()->index();
            $table->string('display_financial_status', 96)->nullable()->index();
            $table->string('display_fulfillment_status', 96)->nullable()->index();
            $table->timestamp('ordered_at_shop_tz')->nullable()->index();
            $table->timestamp('graphql_updated_at')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_customers', static function (Blueprint $table): void {
            $table->id();
            $table->string('gid', 191)->unique();
            $table->string('legacy_numeric_id')->nullable()->index();
            $table->string('display_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->timestamp('customer_created_at')->nullable()->index();
            $table->timestamp('graphql_updated_at')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_collections', static function (Blueprint $table): void {
            $table->id();
            $table->string('gid', 191)->unique();
            $table->string('legacy_numeric_id')->nullable()->index();
            $table->string('handle')->nullable()->index();
            $table->string('title')->nullable();
            $table->timestamp('graphql_updated_at')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_collections');
        Schema::dropIfExists('shopify_customers');
        Schema::dropIfExists('shopify_orders');
        Schema::dropIfExists('shopify_inventory_levels');
        Schema::dropIfExists('shopify_inventory_items');
        Schema::dropIfExists('shopify_product_variants');
        Schema::dropIfExists('shopify_products');
        Schema::dropIfExists('shopify_locations');
        Schema::dropIfExists('shopify_webhook_logs');
        Schema::dropIfExists('shopify_sync_logs');
    }
};
