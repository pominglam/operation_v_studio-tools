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
            $table->json('payment_gateway_names')->nullable()->after('pos_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_orders', static function (Blueprint $table): void {
            $table->dropColumn('payment_gateway_names');
        });
    }
};
