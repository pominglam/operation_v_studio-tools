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
            if (! Schema::hasColumn('products', 'handle')) {
                $table->string('handle', 255)->nullable()->after('description');
                $table->index(['handle'], 'idx_products_handle');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'handle')) {
                $table->dropIndex('idx_products_handle');
                $table->dropColumn('handle');
            }
        });
    }
};
