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
            $table->decimal('original_price', 12, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('product_price_quotes', function (Blueprint $table): void {
            $table->dropColumn('original_price');
        });
    }
};


