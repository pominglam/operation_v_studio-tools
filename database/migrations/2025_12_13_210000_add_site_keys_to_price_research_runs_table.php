<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_research_runs', function (Blueprint $table): void {
            $table->json('site_keys')->nullable()->after('product_uuids');
        });
    }

    public function down(): void
    {
        Schema::table('price_research_runs', function (Blueprint $table): void {
            $table->dropColumn('site_keys');
        });
    }
};
