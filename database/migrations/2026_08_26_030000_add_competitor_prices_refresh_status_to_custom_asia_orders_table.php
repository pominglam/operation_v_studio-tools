<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->string('competitor_prices_refresh_status', 32)->nullable()->after('competitor_prices_fetched_at');
            $table->string('competitor_prices_refresh_scope', 16)->nullable()->after('competitor_prices_refresh_status');
            $table->text('competitor_prices_refresh_error')->nullable()->after('competitor_prices_refresh_scope');
        });
    }

    public function down(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'competitor_prices_refresh_status',
                'competitor_prices_refresh_scope',
                'competitor_prices_refresh_error',
            ]);
        });
    }
};
