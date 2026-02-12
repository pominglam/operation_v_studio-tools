<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('product_external_assets', 'shopify_enabled')) {
            return;
        }

        Schema::table('product_external_assets', function (Blueprint $table): void {
            $table->boolean('shopify_enabled')->default(true)->after('sort_order');

            $table->index(['product_id', 'shopify_enabled', 'sort_order'], 'idx_pea_product_shopify_enabled_sort');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('product_external_assets', 'shopify_enabled')) {
            return;
        }

        Schema::table('product_external_assets', function (Blueprint $table): void {
            $table->dropIndex('idx_pea_product_shopify_enabled_sort');
            $table->dropColumn('shopify_enabled');
        });
    }
};

