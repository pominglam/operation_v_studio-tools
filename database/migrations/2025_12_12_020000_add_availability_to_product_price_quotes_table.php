<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_price_quotes', function (Blueprint $table): void {
            $table->string('availability', 32)->nullable()->after('status'); // in_stock | sold_out | null
        });
    }

    public function down(): void
    {
        Schema::table('product_price_quotes', function (Blueprint $table): void {
            $table->dropColumn('availability');
        });
    }
};
