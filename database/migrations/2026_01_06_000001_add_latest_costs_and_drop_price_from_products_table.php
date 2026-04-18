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
            if (! Schema::hasColumn('products', 'latest_unit_cost')) {
                $table->decimal('latest_unit_cost', 12, 2)->nullable()->after('extended');
            }
            if (! Schema::hasColumn('products', 'latest_landed_unit_cost')) {
                $table->decimal('latest_landed_unit_cost', 12, 2)->nullable()->after('latest_unit_cost');
            }
            if (Schema::hasColumn('products', 'price')) {
                $table->dropColumn('price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'latest_landed_unit_cost')) {
                $table->dropColumn('latest_landed_unit_cost');
            }
            if (Schema::hasColumn('products', 'latest_unit_cost')) {
                $table->dropColumn('latest_unit_cost');
            }
            if (! Schema::hasColumn('products', 'price')) {
                $table->decimal('price', 12, 2)->nullable()->after('type');
            }
        });
    }
};
