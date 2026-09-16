<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('special_orders', function (Blueprint $table): void {
            $table->timestamp('balance_received_at')->nullable()->after('deposit_received_at');
            $table->timestamp('customer_considering_at')->nullable()->after('customer_offer_locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('special_orders', function (Blueprint $table): void {
            $table->dropColumn(['balance_received_at', 'customer_considering_at']);
        });
    }
};
