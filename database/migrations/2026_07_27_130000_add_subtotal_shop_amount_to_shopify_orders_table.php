<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_orders', static function (Blueprint $table): void {
            $table->decimal('subtotal_shop_amount', 12, 2)->nullable()->after('pos_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_orders', static function (Blueprint $table): void {
            $table->dropColumn('subtotal_shop_amount');
        });
    }
};
