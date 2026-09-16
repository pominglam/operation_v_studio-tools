<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('special_orders', static function (Blueprint $table): void {
            $table->string('shopify_customer_gid', 191)->nullable()->after('deposit_received_at');
            $table->string('shopify_deposit_draft_order_gid', 191)->nullable()->after('shopify_customer_gid');
            $table->string('shopify_deposit_draft_order_legacy_id', 32)->nullable()->after('shopify_deposit_draft_order_gid');
            $table->string('shopify_deposit_draft_order_name', 64)->nullable()->after('shopify_deposit_draft_order_legacy_id');
            $table->string('shopify_deposit_invoice_url', 512)->nullable()->after('shopify_deposit_draft_order_name');
            $table->timestamp('shopify_deposit_invoice_sent_at')->nullable()->after('shopify_deposit_invoice_url');
            $table->string('shopify_balance_draft_order_gid', 191)->nullable()->after('shopify_deposit_invoice_sent_at');
            $table->string('shopify_balance_draft_order_legacy_id', 32)->nullable()->after('shopify_balance_draft_order_gid');
            $table->string('shopify_balance_draft_order_name', 64)->nullable()->after('shopify_balance_draft_order_legacy_id');
            $table->string('shopify_balance_invoice_url', 512)->nullable()->after('shopify_balance_draft_order_name');
            $table->timestamp('shopify_balance_invoice_sent_at')->nullable()->after('shopify_balance_invoice_url');
        });
    }

    public function down(): void
    {
        Schema::table('special_orders', static function (Blueprint $table): void {
            $table->dropColumn([
                'shopify_customer_gid',
                'shopify_deposit_draft_order_gid',
                'shopify_deposit_draft_order_legacy_id',
                'shopify_deposit_draft_order_name',
                'shopify_deposit_invoice_url',
                'shopify_deposit_invoice_sent_at',
                'shopify_balance_draft_order_gid',
                'shopify_balance_draft_order_legacy_id',
                'shopify_balance_draft_order_name',
                'shopify_balance_invoice_url',
                'shopify_balance_invoice_sent_at',
            ]);
        });
    }
};
