<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_external_assets', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->nullable()->after('checksum_sha256');

            $table->index(['product_id', 'source', 'sort_order'], 'idx_product_external_assets_product_source_sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('product_external_assets', function (Blueprint $table): void {
            $table->dropIndex('idx_product_external_assets_product_source_sort_order');
            $table->dropColumn('sort_order');
        });
    }
};






