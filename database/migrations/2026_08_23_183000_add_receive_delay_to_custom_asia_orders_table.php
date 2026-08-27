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
            $table->unsignedSmallInteger('receive_delay_amount')->nullable()->after('fx_rate_date');
            $table->string('receive_delay_unit', 8)->nullable()->after('receive_delay_amount');
            $table->unsignedInteger('receive_delay_days')->nullable()->after('receive_delay_unit');
            $table->index('receive_delay_days', 'idx_custom_asia_orders_receive_delay_days');
        });
    }

    public function down(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->dropIndex('idx_custom_asia_orders_receive_delay_days');
            $table->dropColumn(['receive_delay_amount', 'receive_delay_unit', 'receive_delay_days']);
        });
    }
};
