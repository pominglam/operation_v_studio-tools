<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'preferred_description_source')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->string('preferred_description_source', 50)->nullable()->after('description');
            $table->index(['preferred_description_source'], 'idx_products_preferred_description_source');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'preferred_description_source')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('idx_products_preferred_description_source');
            $table->dropColumn('preferred_description_source');
        });
    }
};
