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
            $table->unsignedBigInteger('store_event_id')->nullable();
            $table->index('store_event_id', 'shopify_orders_store_event_id_idx');
            $table->foreign('store_event_id', 'shopify_orders_store_event_id_fk')
                ->references('id')
                ->on('store_events')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shopify_orders', static function (Blueprint $table): void {
            $table->dropForeign('shopify_orders_store_event_id_fk');
            $table->dropIndex('shopify_orders_store_event_id_idx');
            $table->dropColumn('store_event_id');
        });
    }
};
