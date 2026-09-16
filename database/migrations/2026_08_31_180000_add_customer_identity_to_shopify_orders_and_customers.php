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
            $table->string('customer_gid', 191)->nullable()->after('pos_user_id');
            $table->string('customer_email', 255)->nullable()->after('customer_gid');
            $table->string('customer_phone', 64)->nullable()->after('customer_email');
            $table->index('customer_gid', 'shopify_orders_customer_gid_idx');
            $table->index('customer_email', 'shopify_orders_customer_email_idx');
            $table->index('customer_phone', 'shopify_orders_customer_phone_idx');
        });

        Schema::table('shopify_customers', static function (Blueprint $table): void {
            $table->string('phone', 64)->nullable()->after('email');
            $table->index('phone', 'shopify_customers_phone_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_orders', static function (Blueprint $table): void {
            $table->dropIndex('shopify_orders_customer_gid_idx');
            $table->dropIndex('shopify_orders_customer_email_idx');
            $table->dropIndex('shopify_orders_customer_phone_idx');
            $table->dropColumn(['customer_gid', 'customer_email', 'customer_phone']);
        });

        Schema::table('shopify_customers', static function (Blueprint $table): void {
            $table->dropIndex('shopify_customers_phone_idx');
            $table->dropColumn('phone');
        });
    }
};
