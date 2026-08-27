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
            $table->string('competitor_prices_product_name', 255)->nullable()->after('rejected_at');
            $table->json('competitor_price_quotes_json')->nullable()->after('competitor_prices_product_name');
            $table->timestamp('competitor_prices_fetched_at')->nullable()->after('competitor_price_quotes_json');
        });
    }

    public function down(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'competitor_prices_product_name',
                'competitor_price_quotes_json',
                'competitor_prices_fetched_at',
            ]);
        });
    }
};
