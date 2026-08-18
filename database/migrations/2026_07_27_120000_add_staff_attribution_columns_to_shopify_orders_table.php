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
            $table->string('source_name', 64)->nullable()->after('display_fulfillment_status');
            $table->string('channel_name', 128)->nullable()->after('source_name');
            $table->unsignedBigInteger('pos_user_id')->nullable()->after('channel_name');
            $table->index(['ordered_at_shop_tz', 'source_name'], 'shopify_orders_ordered_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_orders', static function (Blueprint $table): void {
            $table->dropIndex('shopify_orders_ordered_source_idx');
            $table->dropColumn(['source_name', 'channel_name', 'pos_user_id']);
        });
    }
};
