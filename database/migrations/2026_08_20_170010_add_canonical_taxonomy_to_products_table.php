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
            $table->string('department', 64)->nullable()->after('main_type');
            $table->string('manufacturer', 128)->nullable()->after('brand');
            $table->string('franchise', 128)->nullable()->after('manufacturer');
            $table->string('product_line', 128)->nullable()->after('franchise');
            $table->string('subline', 128)->nullable()->after('product_line');

            $table->index('department', 'idx_products_department');
            $table->index('manufacturer', 'idx_products_manufacturer');
            $table->index('franchise', 'idx_products_franchise');
            $table->index('product_line', 'idx_products_product_line');
            $table->index('subline', 'idx_products_subline');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('idx_products_department');
            $table->dropIndex('idx_products_manufacturer');
            $table->dropIndex('idx_products_franchise');
            $table->dropIndex('idx_products_product_line');
            $table->dropIndex('idx_products_subline');
            $table->dropColumn([
                'department',
                'manufacturer',
                'franchise',
                'product_line',
                'subline',
            ]);
        });
    }
};
