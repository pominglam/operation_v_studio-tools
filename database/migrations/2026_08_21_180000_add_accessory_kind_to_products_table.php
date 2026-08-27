<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('accessory_kind', 64)->nullable()->after('workshop_facets');

            $table->index('accessory_kind', 'idx_products_accessory_kind');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('idx_products_accessory_kind');
            $table->dropColumn('accessory_kind');
        });
    }
};
