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
            $table->string('workshop_shelf', 128)->nullable()->after('scale');
            $table->json('workshop_facets')->nullable()->after('workshop_shelf');

            $table->index('workshop_shelf', 'idx_products_workshop_shelf');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('idx_products_workshop_shelf');
            $table->dropColumn(['workshop_shelf', 'workshop_facets']);
        });
    }
};
